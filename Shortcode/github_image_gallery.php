<?php
/* (copyLeft) yRocket
 * [github_image_gallery github_url="https://github.com/ykim2718/WordPress/tree/main/Images"]
 *
 * GitHub 공개 저장소의 폴더 하나를 thumbnail gallery로 그린다.
 * 파일명의 hyphen prefix에서 group을 자동으로 만들고, 상단 control bar에서
 * group 다중 선택과 정렬(이름/날짜)을 제공한다. 필터와 정렬은 모두 client side다.
 *
 * [속성]
 *  github_url    (필수) .../tree/<branch>/<path> 또는 .../blob/... 또는 "owner/repo/path"
 *  column        기본 4   -- 최대 열 수 (화면이 좁으면 자동으로 줄어든다)
 *  gap           기본 14  -- 타일 간격 px
 *  ratio         기본 4/3 -- 타일 종횡비 (CSS aspect-ratio 값)
 *  group_depth   기본 2   -- group으로 삼을 hyphen 마디 수의 최대값
 *  min_group     기본 2   -- 이 개수 이상 모여야 group으로 인정
 *  groups        기본 ''  -- 처음부터 선택해 둘 group slug, 쉼표 구분
 *  sort          기본 name_asc -- name_asc | name_desc | date_desc | date_asc
 *  sort_by_date  기본 0   -- 1이면 sort를 date_desc로 시작 (sort 속성보다 우선)
 *  show_date     기본 0   -- 1이면 caption에 commit 날짜 표시 (API 요청이 늘어난다)
 *  show_name     기본 1   -- caption에 파일명 표시
 *  lightbox      기본 1   -- 클릭 시 원본을 overlay로 표시, 0이면 새 탭
 *  proxy         기본 ''  -- '' | photon | weserv, thumbnail 크기를 줄여 받는 경로
 *  thumb_width   기본 480 -- proxy를 쓸 때 요청할 가로 px
 *  limit         기본 0   -- 0이면 전부, 그 외에는 최대 개수
 *  cache         기본 60  -- 목록 캐시 분
 *  date_cache    기본 720 -- 날짜 캐시 분
 *  max_commits   기본 60  -- 날짜를 얻기 위해 훑을 commit 최대 개수
 *
 * [비고]
 *  - 비로그인 GitHub API는 IP당 시간당 60회다. show_date=0(기본)이면 요청은 1회뿐이고,
 *    show_date=1이면 commit을 훑느라 수십 회가 든다. wp-config.php에
 *    define('GITHUB_GALLERY_TOKEN', 'ghp_...'); 를 두면 한도가 5000회가 된다.
 *
 * [Change Log]
 *  2026.8.20: Kick-off
 */

add_shortcode('github_image_gallery', 'ygg_render');

/* ------------------------------------------------------------------ *
 * 1. github_url 해석
 * ------------------------------------------------------------------ */
function ygg_parse_source($url) {
    $url = trim((string) $url);
    if ($url === '') return new WP_Error('ygg_empty', 'github_url 속성이 비어 있습니다.');

    // "owner/repo/path" 축약형
    if (strpos($url, '://') === false && substr_count($url, '/') >= 1) {
        $seg = array_values(array_filter(explode('/', trim($url, '/'))));
        if (count($seg) < 2) return new WP_Error('ygg_short', 'owner/repo 형식이 아닙니다.');
        return array(
            'owner'  => $seg[0],
            'repo'   => $seg[1],
            'branch' => 'main',
            'path'   => implode('/', array_slice($seg, 2)),
        );
    }

    $p = wp_parse_url($url);
    if (empty($p['host']) || !in_array(strtolower($p['host']), array('github.com', 'www.github.com'), true)) {
        return new WP_Error('ygg_host', 'github.com 주소만 사용할 수 있습니다.');
    }
    $seg = array_values(array_filter(explode('/', trim($p['path'], '/'))));
    if (count($seg) < 2) return new WP_Error('ygg_path', 'owner/repo를 찾을 수 없습니다.');

    $owner = $seg[0];
    $repo  = $seg[1];
    $branch = 'main';
    $path   = '';
    if (isset($seg[2]) && in_array($seg[2], array('tree', 'blob'), true) && isset($seg[3])) {
        $branch = $seg[3];
        $path   = implode('/', array_slice($seg, 4));
    }
    return compact('owner', 'repo', 'branch', 'path');
}

/* ------------------------------------------------------------------ *
 * 2. GitHub API
 * ------------------------------------------------------------------ */
function ygg_api($endpoint) {
    $args = array(
        'timeout' => 15,
        'headers' => array(
            'Accept'     => 'application/vnd.github+json',
            'User-Agent' => 'wp-github-image-gallery',
        ),
    );
    if (defined('GITHUB_GALLERY_TOKEN') && GITHUB_GALLERY_TOKEN) {
        $args['headers']['Authorization'] = 'Bearer ' . GITHUB_GALLERY_TOKEN;
    }

    $res = wp_remote_get('https://api.github.com/' . ltrim($endpoint, '/'), $args);
    if (is_wp_error($res)) return $res;

    $code = (int) wp_remote_retrieve_response_code($res);
    $body = json_decode(wp_remote_retrieve_body($res), true);

    if ($code === 403 && (int) wp_remote_retrieve_header($res, 'x-ratelimit-remaining') === 0) {
        return new WP_Error('ygg_rate', 'GitHub API 호출 한도를 넘었습니다. 잠시 뒤에 다시 시도하세요.');
    }
    if ($code !== 200) {
        $msg = is_array($body) && !empty($body['message']) ? $body['message'] : ('HTTP ' . $code);
        return new WP_Error('ygg_http', 'GitHub API 오류: ' . $msg);
    }
    return $body;
}

/** 폴더 안의 이미지 파일 목록 */
function ygg_fetch_files($src, $cache_min) {
    $key  = 'ygg_f_' . md5(wp_json_encode($src));
    $hit  = get_transient($key);
    if (is_array($hit)) return $hit;

    $body = ygg_api(sprintf(
        'repos/%s/%s/contents/%s?ref=%s',
        rawurlencode($src['owner']), rawurlencode($src['repo']),
        implode('/', array_map('rawurlencode', array_filter(explode('/', $src['path'])))),
        rawurlencode($src['branch'])
    ));
    if (is_wp_error($body)) return $body;
    if (!is_array($body)) return new WP_Error('ygg_shape', '폴더 목록을 읽지 못했습니다.');

    $ok    = array('jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'bmp');
    $files = array();
    foreach ($body as $it) {
        if (!is_array($it) || ($it['type'] ?? '') !== 'file') continue;
        $ext = strtolower(pathinfo($it['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $ok, true)) continue;
        $files[] = array(
            'name' => $it['name'],
            'url'  => $it['download_url'],
            'size' => (int) ($it['size'] ?? 0),
        );
    }
    set_transient($key, $files, max(60, (int) $cache_min * 60));
    return $files;
}

/** 파일명 -> 마지막 commit 시각(unix). commit을 훑으므로 요청이 많다. */
function ygg_fetch_dates($src, $cache_min, $max_commits) {
    $key = 'ygg_d_' . md5(wp_json_encode($src));
    $hit = get_transient($key);
    if (is_array($hit)) return $hit;

    $list = ygg_api(sprintf(
        'repos/%s/%s/commits?sha=%s&path=%s&per_page=100',
        rawurlencode($src['owner']), rawurlencode($src['repo']),
        rawurlencode($src['branch']), rawurlencode($src['path'])
    ));
    if (is_wp_error($list) || !is_array($list)) return array();

    $dates = array();
    $seen  = 0;
    foreach ($list as $c) {                       // 최신 commit부터
        if ($seen >= (int) $max_commits) break;
        $sha = $c['sha'] ?? '';
        if ($sha === '') continue;
        $seen++;

        $detail = ygg_api(sprintf(
            'repos/%s/%s/commits/%s',
            rawurlencode($src['owner']), rawurlencode($src['repo']), rawurlencode($sha)
        ));
        if (is_wp_error($detail)) break;           // 한도 초과 등: 여기까지 모은 것만 쓴다

        $when = strtotime($c['commit']['author']['date'] ?? ($c['commit']['committer']['date'] ?? ''));
        foreach ((array) ($detail['files'] ?? array()) as $f) {
            $fn = $f['filename'] ?? '';
            if ($src['path'] !== '' && strpos($fn, $src['path'] . '/') !== 0) continue;
            $base = basename($fn);
            if (!isset($dates[$base])) $dates[$base] = $when;   // 최신 것이 먼저 오므로 한 번만
        }
    }
    set_transient($key, $dates, max(300, (int) $cache_min * 60));
    return $dates;
}

/* ------------------------------------------------------------------ *
 * 3. 파일명에서 group 뽑기
 * ------------------------------------------------------------------ */
function ygg_build_groups($names, $depth, $min) {
    $depth = max(1, (int) $depth);
    $min   = max(2, (int) $min);

    $tally = array();
    foreach ($names as $n) {
        $seg = explode('-', pathinfo($n, PATHINFO_FILENAME));
        $top = min($depth, count($seg) - 1);       // 이름 전체가 group이 되지는 않게
        for ($k = 1; $k <= $top; $k++) {
            $p = implode('-', array_slice($seg, 0, $k));
            $tally[$p] = isset($tally[$p]) ? $tally[$p] + 1 : 1;
        }
    }

    $map = array();
    foreach ($names as $n) {
        $seg  = explode('-', pathinfo($n, PATHINFO_FILENAME));
        $top  = min($depth, count($seg) - 1);
        $best = '';
        for ($k = 1; $k <= $top; $k++) {           // 조건을 만족하는 가장 긴 prefix
            $p = implode('-', array_slice($seg, 0, $k));
            if (isset($tally[$p]) && $tally[$p] >= $min) $best = $p;
        }
        $map[$n] = ($best !== '') ? $best : '_other';
    }

    // 더 긴 prefix에 식구를 빼앗겨 혼자 남은 group은 Other로 보낸다
    $size = array_count_values($map);
    foreach ($map as $n => $g) {
        if ($g !== '_other' && $size[$g] < $min) $map[$n] = '_other';
    }

    // group slug를 식구들의 공통 prefix까지 늘린다: apex-ice -> apex-ice-arena
    $members = array();
    foreach ($map as $n => $g) {
        if ($g === '_other') continue;
        $members[$g][] = explode('-', pathinfo($n, PATHINFO_FILENAME));
    }
    $rename = array();
    foreach ($members as $g => $rows) {
        $lcp = $rows[0];
        foreach ($rows as $r) {
            $keep = array();
            foreach ($lcp as $i => $s) {
                if (!isset($r[$i]) || $r[$i] !== $s) break;
                $keep[] = $s;
            }
            $lcp = $keep;
        }
        foreach ($rows as $r) {                    // 이름 한 마디는 group 밖에 남겨 둔다
            while (count($lcp) >= count($r)) array_pop($lcp);
        }
        if (count($lcp) > 0) $rename[$g] = implode('-', $lcp);
    }
    foreach ($map as $n => $g) {
        if (isset($rename[$g])) $map[$n] = $rename[$g];
    }
    return $map;
}

function ygg_group_label($slug) {
    if ($slug === '_other') return 'Other';
    return ucwords(str_replace('-', ' ', $slug));
}

/* ------------------------------------------------------------------ *
 * 4. thumbnail URL
 * ------------------------------------------------------------------ */
function ygg_thumb_url($raw, $proxy, $w) {
    $w = max(80, (int) $w);
    if ($proxy === 'photon') {
        return 'https://i0.wp.com/' . preg_replace('#^https?://#', '', $raw) . '?w=' . $w;
    }
    if ($proxy === 'weserv') {
        return 'https://images.weserv.nl/?url=' . rawurlencode(preg_replace('#^https?://#', '', $raw))
             . '&w=' . $w . '&fit=cover&we';
    }
    return $raw;
}

/* ------------------------------------------------------------------ *
 * 5. 본체
 * ------------------------------------------------------------------ */
function ygg_render($atts) {
    $a = shortcode_atts(array(
        'github_url'   => '',
        'column'       => 4,
        'gap'          => 14,
        'ratio'        => '4/3',
        'group_depth'  => 2,
        'min_group'    => 2,
        'groups'       => '',
        'sort'         => 'name_asc',
        'sort_by_date' => 0,
        'show_date'    => 0,
        'show_name'    => 1,
        'lightbox'     => 1,
        'proxy'        => '',
        'thumb_width'  => 480,
        'limit'        => 0,
        'cache'        => 60,
        'date_cache'   => 720,
        'max_commits'  => 60,
    ), $atts, 'github_image_gallery');

    $src = ygg_parse_source($a['github_url']);
    if (is_wp_error($src)) return ygg_notice($src->get_error_message());

    $files = ygg_fetch_files($src, $a['cache']);
    if (is_wp_error($files)) return ygg_notice($files->get_error_message());
    if (empty($files))       return ygg_notice('이 폴더에서 이미지를 찾지 못했습니다.');

    $sort = in_array($a['sort'], array('name_asc', 'name_desc', 'date_desc', 'date_asc'), true)
          ? $a['sort'] : 'name_asc';
    if ((int) $a['sort_by_date'] === 1) $sort = 'date_desc';

    $want_date = ((int) $a['show_date'] === 1) || (strpos($sort, 'date') === 0);
    $dates     = $want_date ? ygg_fetch_dates($src, $a['date_cache'], $a['max_commits']) : array();
    if ($want_date && empty($dates) && strpos($sort, 'date') === 0) {
        $sort = 'name_asc';                        // 날짜를 못 얻었으면 조용히 이름순으로
    }

    $names  = wp_list_pluck($files, 'name');
    $gmap   = ygg_build_groups($names, $a['group_depth'], $a['min_group']);

    // group 목록과 개수
    $gcount = array();
    foreach ($gmap as $g) $gcount[$g] = isset($gcount[$g]) ? $gcount[$g] + 1 : 1;
    uksort($gcount, function ($x, $y) {            // Other는 항상 끝으로
        if ($x === '_other') return 1;
        if ($y === '_other') return -1;
        return strcasecmp($x, $y);
    });

    $preset = array_filter(array_map('trim', explode(',', (string) $a['groups'])));

    // 초기 정렬
    usort($files, function ($p, $q) use ($sort, $dates) {
        if (strpos($sort, 'date') === 0) {
            $dp = isset($dates[$p['name']]) ? $dates[$p['name']] : 0;
            $dq = isset($dates[$q['name']]) ? $dates[$q['name']] : 0;
            if ($dp !== $dq) return ($sort === 'date_desc') ? ($dq - $dp) : ($dp - $dq);
            return strcasecmp($p['name'], $q['name']);
        }
        $c = strcasecmp($p['name'], $q['name']);
        return ($sort === 'name_desc') ? -$c : $c;
    });

    if ((int) $a['limit'] > 0) $files = array_slice($files, 0, (int) $a['limit']);

    $uid      = 'ygg-' . substr(md5(wp_json_encode($a) . wp_rand()), 0, 8);
    $col      = max(1, min(8, (int) $a['column']));
    $gap      = max(0, (int) $a['gap']);
    $ratio    = preg_replace('#[^0-9/\. ]#', '', (string) $a['ratio']);
    $lightbox = ((int) $a['lightbox'] === 1);
    $proxy    = in_array($a['proxy'], array('photon', 'weserv'), true) ? $a['proxy'] : '';

    ob_start();
    ?>
<div class="ygg" id="<?php echo esc_attr($uid); ?>"
     data-lightbox="<?php echo $lightbox ? '1' : '0'; ?>">

  <div class="ygg-bar">
    <div class="ygg-field ygg-groups">
      <button type="button" class="ygg-drop-btn" aria-expanded="false" aria-haspopup="true">
        <span class="ygg-drop-label">모든 group</span><span class="ygg-caret" aria-hidden="true">▾</span>
      </button>
      <div class="ygg-drop" role="group" aria-label="Group 선택" hidden>
        <div class="ygg-drop-head">
          <button type="button" class="ygg-mini" data-all="1">전체 선택</button>
          <button type="button" class="ygg-mini" data-all="0">전체 해제</button>
        </div>
        <ul class="ygg-drop-list">
          <?php foreach ($gcount as $slug => $n) : ?>
          <li>
            <label>
              <input type="checkbox" value="<?php echo esc_attr($slug); ?>"
                     <?php checked(in_array($slug, $preset, true)); ?>>
              <span class="ygg-gname"><?php echo esc_html(ygg_group_label($slug)); ?></span>
              <span class="ygg-gnum"><?php echo (int) $n; ?></span>
            </label>
          </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>

    <div class="ygg-field">
      <label class="ygg-lab" for="<?php echo esc_attr($uid); ?>-sort">정렬</label>
      <select id="<?php echo esc_attr($uid); ?>-sort" class="ygg-sort">
        <option value="name_asc"  <?php selected($sort, 'name_asc');  ?>>이름 A → Z</option>
        <option value="name_desc" <?php selected($sort, 'name_desc'); ?>>이름 Z → A</option>
        <option value="date_desc" <?php selected($sort, 'date_desc'); ?>>날짜 최신순</option>
        <option value="date_asc"  <?php selected($sort, 'date_asc');  ?>>날짜 오래된순</option>
      </select>
    </div>

    <div class="ygg-field ygg-grow">
      <label class="ygg-lab" for="<?php echo esc_attr($uid); ?>-q">검색</label>
      <input id="<?php echo esc_attr($uid); ?>-q" class="ygg-q" type="search"
             placeholder="파일명 일부" autocomplete="off">
    </div>

    <div class="ygg-count" aria-live="polite"></div>
  </div>

  <?php if ($want_date && empty($dates)) : ?>
  <p class="ygg-warn">commit 날짜를 가져오지 못해 이름순으로 표시합니다.</p>
  <?php endif; ?>

  <div class="ygg-grid">
    <?php foreach ($files as $f) :
        $g   = isset($gmap[$f['name']]) ? $gmap[$f['name']] : '_other';
        $ts  = isset($dates[$f['name']]) ? (int) $dates[$f['name']] : 0;
        $alt = ucwords(str_replace('-', ' ', pathinfo($f['name'], PATHINFO_FILENAME)));
    ?>
    <figure class="ygg-item"
            data-name="<?php echo esc_attr(strtolower($f['name'])); ?>"
            data-group="<?php echo esc_attr($g); ?>"
            data-date="<?php echo esc_attr($ts); ?>">
      <a class="ygg-link" href="<?php echo esc_url($f['url']); ?>"
         target="_blank" rel="noopener noreferrer">
        <img src="<?php echo esc_url(ygg_thumb_url($f['url'], $proxy, $a['thumb_width'])); ?>"
             alt="<?php echo esc_attr($alt); ?>" loading="lazy" decoding="async">
      </a>
      <?php if ((int) $a['show_name'] === 1 || ((int) $a['show_date'] === 1 && $ts)) : ?>
      <figcaption>
        <?php if ((int) $a['show_name'] === 1) : ?>
          <span class="ygg-fn"><?php echo esc_html($f['name']); ?></span>
        <?php endif; ?>
        <?php if ((int) $a['show_date'] === 1 && $ts) : ?>
          <span class="ygg-dt"><?php echo esc_html(date_i18n('Y-m-d', $ts)); ?></span>
        <?php endif; ?>
      </figcaption>
      <?php endif; ?>
    </figure>
    <?php endforeach; ?>
  </div>

  <p class="ygg-empty" hidden>조건에 맞는 이미지가 없습니다.</p>
</div>

<style>
#<?php echo $uid; ?> { --ygg-col: <?php echo $col; ?>; --ygg-gap: <?php echo $gap; ?>px; }
#<?php echo $uid; ?> .ygg-bar {
  display:flex; flex-wrap:wrap; align-items:flex-end; gap:12px;
  margin:0 0 16px; padding:12px 14px; border:1px solid rgba(128,128,128,.28); border-radius:10px;
}
#<?php echo $uid; ?> .ygg-field { position:relative; display:flex; flex-direction:column; gap:4px; }
#<?php echo $uid; ?> .ygg-grow { flex:1 1 180px; min-width:140px; }
#<?php echo $uid; ?> .ygg-lab { font-size:.72rem; letter-spacing:.04em; opacity:.7; }
#<?php echo $uid; ?> .ygg-sort,
#<?php echo $uid; ?> .ygg-q,
#<?php echo $uid; ?> .ygg-drop-btn {
  font:inherit; font-size:.9rem; padding:7px 10px; line-height:1.2;
  border:1px solid rgba(128,128,128,.45); border-radius:7px;
  background:transparent; color:inherit; max-width:100%;
}
#<?php echo $uid; ?> .ygg-drop-btn { display:flex; align-items:center; gap:8px; cursor:pointer; min-width:150px; }
#<?php echo $uid; ?> .ygg-caret { opacity:.6; margin-left:auto; }
#<?php echo $uid; ?> .ygg-drop {
  position:absolute; z-index:30; top:100%; left:0; margin-top:6px; min-width:220px; max-width:320px;
  max-height:320px; overflow:auto; padding:8px;
  border:1px solid rgba(128,128,128,.45); border-radius:9px;
  background:var(--ygg-pop, #fff); color:inherit; box-shadow:0 10px 26px rgba(0,0,0,.18);
}
@media (prefers-color-scheme: dark) { #<?php echo $uid; ?> .ygg-drop { background:#1e1e1e; } }
#<?php echo $uid; ?> .ygg-drop-head { display:flex; gap:6px; padding:2px 2px 8px; }
#<?php echo $uid; ?> .ygg-mini {
  font:inherit; font-size:.75rem; padding:3px 8px; cursor:pointer; color:inherit;
  border:1px solid rgba(128,128,128,.45); border-radius:999px; background:transparent;
}
#<?php echo $uid; ?> .ygg-drop-list { list-style:none; margin:0; padding:0; }
#<?php echo $uid; ?> .ygg-drop-list li { margin:0; }
#<?php echo $uid; ?> .ygg-drop-list label {
  display:flex; align-items:center; gap:8px; padding:6px 6px; border-radius:6px;
  font-size:.88rem; cursor:pointer;
}
#<?php echo $uid; ?> .ygg-drop-list label:hover { background:rgba(128,128,128,.14); }
#<?php echo $uid; ?> .ygg-gname { flex:1; }
#<?php echo $uid; ?> .ygg-gnum { font-size:.75rem; opacity:.6; }
#<?php echo $uid; ?> .ygg-count { margin-left:auto; font-size:.8rem; opacity:.7; padding-bottom:8px; }
#<?php echo $uid; ?> .ygg-warn { font-size:.8rem; opacity:.75; margin:-8px 0 12px; }

#<?php echo $uid; ?> .ygg-grid {
  display:grid; gap:var(--ygg-gap);
  grid-template-columns:repeat(auto-fill, minmax(min(100%, calc((100% - (var(--ygg-col) - 1) * var(--ygg-gap)) / var(--ygg-col))), 1fr));
}
#<?php echo $uid; ?> .ygg-item { margin:0; min-width:0; }
#<?php echo $uid; ?> .ygg-item[hidden] { display:none; }
#<?php echo $uid; ?> .ygg-link {
  display:block; overflow:hidden; border-radius:9px; background:rgba(128,128,128,.12);
  aspect-ratio:<?php echo $ratio ? $ratio : '4/3'; ?>;
}
#<?php echo $uid; ?> .ygg-link img {
  width:100%; height:100%; object-fit:cover; display:block;
  transition:transform .25s ease;
}
#<?php echo $uid; ?> .ygg-link:hover img { transform:scale(1.045); }
#<?php echo $uid; ?> figcaption {
  display:flex; gap:8px; align-items:baseline; margin-top:6px;
  font-size:.74rem; line-height:1.35; opacity:.8;
}
#<?php echo $uid; ?> .ygg-fn { overflow-wrap:anywhere; }
#<?php echo $uid; ?> .ygg-dt { margin-left:auto; white-space:nowrap; opacity:.75; }
#<?php echo $uid; ?> .ygg-empty { padding:28px 0; text-align:center; opacity:.7; }

.ygg-box {
  position:fixed; inset:0; z-index:99999; display:flex; align-items:center; justify-content:center;
  background:rgba(0,0,0,.86); padding:24px; cursor:zoom-out;
}
.ygg-box img { max-width:100%; max-height:100%; object-fit:contain; border-radius:6px; }
.ygg-box .ygg-cap {
  position:absolute; left:0; right:0; bottom:14px; text-align:center;
  color:#fff; font-size:.82rem; opacity:.85; pointer-events:none;
}
</style>

<script>
(function () {
  var root = document.getElementById('<?php echo esc_js($uid); ?>');
  if (!root || root.dataset.ready) return;
  root.dataset.ready = '1';

  var grid  = root.querySelector('.ygg-grid');
  var items = Array.prototype.slice.call(root.querySelectorAll('.ygg-item'));
  var btn   = root.querySelector('.ygg-drop-btn');
  var panel = root.querySelector('.ygg-drop');
  var label = root.querySelector('.ygg-drop-label');
  var boxes = Array.prototype.slice.call(panel.querySelectorAll('input[type=checkbox]'));
  var sortSel = root.querySelector('.ygg-sort');
  var query   = root.querySelector('.ygg-q');
  var counter = root.querySelector('.ygg-count');
  var empty   = root.querySelector('.ygg-empty');

  function selectedGroups() {
    return boxes.filter(function (b) { return b.checked; }).map(function (b) { return b.value; });
  }

  function syncLabel() {
    var n = selectedGroups().length;
    label.textContent = (n === 0) ? '모든 group' : (n + '개 group 선택');
  }

  function apply() {
    var sel = selectedGroups();
    var q   = (query.value || '').trim().toLowerCase();
    var shown = 0;

    items.forEach(function (el) {
      var okGroup = (sel.length === 0) || sel.indexOf(el.dataset.group) !== -1;
      var okText  = (q === '') || el.dataset.name.indexOf(q) !== -1;
      var ok = okGroup && okText;
      el.hidden = !ok;
      if (ok) shown++;
    });

    var mode = sortSel.value;
    var order = items.slice().sort(function (a, b) {
      if (mode.indexOf('date') === 0) {
        var da = +a.dataset.date, db = +b.dataset.date;
        if (da !== db) return (mode === 'date_desc') ? db - da : da - db;
        return a.dataset.name.localeCompare(b.dataset.name);
      }
      var c = a.dataset.name.localeCompare(b.dataset.name);
      return (mode === 'name_desc') ? -c : c;
    });
    var frag = document.createDocumentFragment();
    order.forEach(function (el) { frag.appendChild(el); });
    grid.appendChild(frag);

    counter.textContent = shown + ' / ' + items.length;
    empty.hidden = shown !== 0;
    syncLabel();
  }

  function openPanel(on) {
    panel.hidden = !on;
    btn.setAttribute('aria-expanded', on ? 'true' : 'false');
  }

  btn.addEventListener('click', function (e) {
    e.stopPropagation();
    openPanel(panel.hidden);
  });
  panel.addEventListener('click', function (e) { e.stopPropagation(); });
  document.addEventListener('click', function () { openPanel(false); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') openPanel(false); });

  boxes.forEach(function (b) { b.addEventListener('change', apply); });
  panel.querySelectorAll('.ygg-mini').forEach(function (m) {
    m.addEventListener('click', function () {
      var on = m.dataset.all === '1';
      boxes.forEach(function (b) { b.checked = on; });
      apply();
    });
  });
  sortSel.addEventListener('change', apply);
  var t;
  query.addEventListener('input', function () { clearTimeout(t); t = setTimeout(apply, 120); });

  if (root.dataset.lightbox === '1') {
    root.addEventListener('click', function (e) {
      var link = e.target.closest ? e.target.closest('.ygg-link') : null;
      if (!link || e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;
      e.preventDefault();
      var box = document.createElement('div');
      box.className = 'ygg-box';
      var big = document.createElement('img');
      big.src = link.getAttribute('href');
      big.alt = link.querySelector('img').alt;
      var cap = document.createElement('div');
      cap.className = 'ygg-cap';
      cap.textContent = link.closest('.ygg-item').dataset.name;
      box.appendChild(big); box.appendChild(cap);
      document.body.appendChild(box);
      function close() { box.remove(); document.removeEventListener('keydown', onKey); }
      function onKey(ev) { if (ev.key === 'Escape') close(); }
      box.addEventListener('click', close);
      document.addEventListener('keydown', onKey);
    });
  }

  apply();
})();
</script>
    <?php
    return ob_get_clean();
}

function ygg_notice($msg) {
    return '<p style="padding:14px;border:1px solid rgba(200,80,80,.5);border-radius:8px;">'
         . esc_html($msg) . '</p>';
}
