<?php
/**
 * github_image_gallery shortcode: 목록 읽기, group 만들기, 화면 그리기.
 */

if (!defined('ABSPATH')) exit;

/* ------------------------------------------------------------------ *
 * 1. github_url 해석
 * ------------------------------------------------------------------ */
function gig_parse_source($url) {
    $url = trim((string) $url);
    if ($url === '') return new WP_Error('gig_empty', __('The github_url attribute is empty.', 'github-image-gallery'));

    // "owner/repo/path" 축약형
    if (strpos($url, '://') === false) {
        $seg = array_values(array_filter(explode('/', trim($url, '/'))));
        if (count($seg) < 2) return new WP_Error('gig_short', __('Not in owner/repo form.', 'github-image-gallery'));
        return array(
            'owner'  => $seg[0],
            'repo'   => $seg[1],
            'branch' => 'main',
            'path'   => implode('/', array_slice($seg, 2)),
        );
    }

    $p = wp_parse_url($url);
    if (empty($p['host']) || !in_array(strtolower($p['host']), array('github.com', 'www.github.com'), true)) {
        return new WP_Error('gig_host', __('Only github.com URLs are accepted.', 'github-image-gallery'));
    }
    $seg = array_values(array_filter(explode('/', trim($p['path'], '/'))));
    if (count($seg) < 2) return new WP_Error('gig_path', __('Could not find owner/repo in the URL.', 'github-image-gallery'));

    $branch = 'main';
    $path   = '';
    if (isset($seg[2]) && in_array($seg[2], array('tree', 'blob'), true) && isset($seg[3])) {
        $branch = $seg[3];
        $path   = implode('/', array_slice($seg, 4));
    }
    return array('owner' => $seg[0], 'repo' => $seg[1], 'branch' => $branch, 'path' => $path);
}

function gig_raw_base($src) {
    return 'https://raw.githubusercontent.com/'
         . rawurlencode($src['owner']) . '/' . rawurlencode($src['repo']) . '/'
         . rawurlencode($src['branch'])
         . ($src['path'] !== '' ? '/' . implode('/', array_map('rawurlencode', explode('/', $src['path']))) : '');
}

/**
 * 한 폴더를 가리키는 짧은 이름. 캐시 열쇠이자, 한 페이지에 여러 gallery가 있을 때
 * refresh 단추가 그중 어느 것을 가리키는지 말하는 표기이기도 하다.
 */
function gig_source_token($src) {
    return md5(wp_json_encode($src) . '|' . GIG_VERSION);
}

/**
 * refresh 단추가 눌린 요청인가.
 *   ''      이 gallery에게 온 요청이 아니다
 *   'ok'    nonce가 맞다. 캐시를 버린다
 *   'stale' 눌리기는 했으나 nonce가 지났다. 조용히 넘기지 말고 화면에 알린다
 *           (페이지 캐시가 오래된 주소를 물고 있으면 여기로 온다)
 */
function gig_refresh_state($token) {
    if (empty($_GET['gig_refresh']) || sanitize_text_field(wp_unslash($_GET['gig_refresh'])) !== $token) return '';
    $nonce = isset($_GET['_gignonce']) ? sanitize_text_field(wp_unslash($_GET['_gignonce'])) : '';
    return wp_verify_nonce($nonce, 'gig_refresh_' . $token) ? 'ok' : 'stale';
}

/* ------------------------------------------------------------------ *
 * 2. 목록 읽기 -- index.json 우선, 없으면 contents API
 * ------------------------------------------------------------------ */
function gig_http($url, $accept_json = true) {
    $args = array(
        'timeout' => 15,
        'headers' => array('User-Agent' => 'wp-github-image-gallery/' . GIG_VERSION),
    );
    if ($accept_json) $args['headers']['Accept'] = 'application/vnd.github+json';
    if (defined('GITHUB_GALLERY_TOKEN') && GITHUB_GALLERY_TOKEN && strpos($url, 'api.github.com') !== false) {
        $args['headers']['Authorization'] = 'Bearer ' . GITHUB_GALLERY_TOKEN;
    }

    $res = wp_remote_get($url, $args);
    if (is_wp_error($res)) return $res;

    $code = (int) wp_remote_retrieve_response_code($res);
    $body = wp_remote_retrieve_body($res);

    if ($code === 403 && (int) wp_remote_retrieve_header($res, 'x-ratelimit-remaining') === 0) {
        return new WP_Error('gig_rate', __('GitHub API rate limit reached. Using index.json avoids this limit.', 'github-image-gallery'));
    }
    if ($code !== 200) return new WP_Error('gig_http', sprintf(__('GitHub returned %d', 'github-image-gallery'), $code));
    return $body;
}

/**
 * 반환: array('items' => [ name, url, thumb, w, h, date, size ], 'via' => 'index'|'api')
 */
function gig_fetch($src, $cache_min, $force = false) {
    $key = 'gig_l_' . gig_source_token($src);
    if ($force) {
        delete_transient($key);                  // refresh: 캐시를 버리고 새로 받는다
    } else {
        $hit = get_transient($key);
        if (is_array($hit)) return $hit;
    }

    $out = gig_fetch_index($src);
    if (is_wp_error($out) || empty($out['items'])) {
        $api = gig_fetch_api($src);
        if (is_wp_error($api)) {
            // index.json도 API도 실패: 원인은 API 쪽 메시지가 더 구체적이다
            return $api;
        }
        $out = $api;
    }

    set_transient($key, $out, max(60, (int) $cache_min * 60));
    return $out;
}

/** CI가 만들어 둔 index.json */
function gig_fetch_index($src) {
    $body = gig_http(gig_raw_base($src) . '/index.json', false);
    if (is_wp_error($body)) return $body;

    $data = json_decode($body, true);
    if (!is_array($data) || empty($data['items']) || !is_array($data['items'])) {
        return new WP_Error('gig_index', __('index.json is not in the expected shape.', 'github-image-gallery'));
    }

    $base  = gig_raw_base($src);
    $items = array();
    foreach ($data['items'] as $it) {
        if (empty($it['n'])) continue;
        $name  = (string) $it['n'];
        $thumb = !empty($it['t'])
            ? $base . '/' . implode('/', array_map('rawurlencode', explode('/', $it['t'])))
            : $base . '/' . rawurlencode($name);
        $items[] = array(
            'name'  => $name,
            'url'   => $base . '/' . rawurlencode($name),
            'thumb' => $thumb,
            'w'     => (int) ($it['w'] ?? 0),
            'h'     => (int) ($it['ht'] ?? 0),
            'date'  => (int) ($it['d'] ?? 0),
            'size'  => (int) ($it['s'] ?? 0),
        );
    }
    return array('items' => $items, 'via' => 'index', 'generated' => (string) ($data['generated'] ?? ''));
}

/** index.json이 없을 때의 대비책 */
function gig_fetch_api($src) {
    $url = sprintf(
        'https://api.github.com/repos/%s/%s/contents/%s?ref=%s',
        rawurlencode($src['owner']), rawurlencode($src['repo']),
        implode('/', array_map('rawurlencode', array_filter(explode('/', $src['path'])))),
        rawurlencode($src['branch'])
    );
    $body = gig_http($url);
    if (is_wp_error($body)) return $body;

    $rows = json_decode($body, true);
    if (!is_array($rows)) return new WP_Error('gig_shape', __('Could not read the folder listing.', 'github-image-gallery'));

    $ok    = array('jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'bmp');
    $items = array();
    foreach ($rows as $it) {
        if (!is_array($it) || ($it['type'] ?? '') !== 'file') continue;
        if (!in_array(strtolower(pathinfo($it['name'], PATHINFO_EXTENSION)), $ok, true)) continue;
        $items[] = array(
            'name'  => $it['name'],
            'url'   => $it['download_url'],
            'thumb' => $it['download_url'],       // 축소본이 없다: 원본을 그대로 쓴다
            'w'     => 0,
            'h'     => 0,
            'date'  => 0,                          // 날짜는 index.json에만 있다
            'size'  => (int) ($it['size'] ?? 0),
        );
    }
    return array('items' => $items, 'via' => 'api', 'generated' => '');
}

/* ------------------------------------------------------------------ *
 * 3. 파일명에서 group 뽑기
 * ------------------------------------------------------------------ */
function gig_build_groups($names, $depth, $min) {
    $depth = max(1, (int) $depth);
    $min   = max(2, (int) $min);

    $tally = array();
    foreach ($names as $n) {
        $seg = explode('-', pathinfo($n, PATHINFO_FILENAME));
        $top = min($depth, count($seg) - 1);        // 이름 전체가 group이 되지는 않게
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
        for ($k = 1; $k <= $top; $k++) {            // 조건을 만족하는 가장 긴 prefix
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
        foreach ($rows as $r) {                     // 이름 한 마디는 group 밖에 남겨 둔다
            while (count($lcp) >= count($r)) array_pop($lcp);
        }
        if (count($lcp) > 0) $rename[$g] = implode('-', $lcp);
    }
    foreach ($map as $n => $g) {
        if (isset($rename[$g])) $map[$n] = $rename[$g];
    }
    return $map;
}

function gig_group_label($slug) {
    if ($slug === '_other') return __('Other', 'github-image-gallery');
    return ucwords(str_replace('-', ' ', $slug));
}

/* ------------------------------------------------------------------ *
 * 4. 본체
 * ------------------------------------------------------------------ */
function gig_render($atts) {
    $a = shortcode_atts(array(
        'github_url'   => '',
        'column'       => 4,
        'gap'          => 14,
        'ratio'        => '4/3',     // 'auto'면 그림의 실제 비율을 쓴다
        'group_depth'  => 2,
        'min_group'    => 2,
        'groups'       => '',
        'sort'         => 'date_desc',   // 날짜가 없는 목록이면 아래에서 name_asc로 내려온다
        'sort_by_date' => 0,
        'show_date'    => 0,
        'show_name'    => 1,
        'show_search'  => 1,
        'show_refresh' => 1,
        'lightbox'     => 1,
        'context_menu' => 1,
        'show_version'  => 1,
        'limit'        => 0,
        'cache'        => 60,
    ), $atts, 'github_image_gallery');

    $src = gig_parse_source($a['github_url']);
    if (is_wp_error($src)) return gig_notice($src->get_error_message());

    $token   = gig_source_token($src);
    $refresh = gig_refresh_state($token);

    $data = gig_fetch($src, $a['cache'], $refresh === 'ok');
    if (is_wp_error($data)) return gig_notice($data->get_error_message());

    $files = $data['items'];
    if (empty($files)) return gig_notice(__('No images found in this folder.', 'github-image-gallery'));

    $sort = in_array($a['sort'], array('name_asc', 'name_desc', 'date_desc', 'date_asc'), true)
          ? $a['sort'] : 'date_desc';
    if ((int) $a['sort_by_date'] === 1) $sort = 'date_desc';

    $has_dates = false;
    foreach ($files as $f) { if ($f['date'] > 0) { $has_dates = true; break; } }
    if (!$has_dates && strpos($sort, 'date') === 0) $sort = 'name_asc';

    $gmap = gig_build_groups(wp_list_pluck($files, 'name'), $a['group_depth'], $a['min_group']);

    $gcount = array();
    foreach ($gmap as $g) $gcount[$g] = isset($gcount[$g]) ? $gcount[$g] + 1 : 1;
    uksort($gcount, function ($x, $y) {              // Other는 항상 끝으로
        if ($x === '_other') return 1;
        if ($y === '_other') return -1;
        return strcasecmp($x, $y);
    });

    $preset = array_filter(array_map('trim', explode(',', (string) $a['groups'])));

    usort($files, function ($p, $q) use ($sort) {
        if (strpos($sort, 'date') === 0) {
            if ($p['date'] !== $q['date']) {
                return ($sort === 'date_desc') ? ($q['date'] - $p['date']) : ($p['date'] - $q['date']);
            }
            return strcasecmp($p['name'], $q['name']);
        }
        $c = strcasecmp($p['name'], $q['name']);
        return ($sort === 'name_desc') ? -$c : $c;
    });

    if ((int) $a['limit'] > 0) $files = array_slice($files, 0, (int) $a['limit']);

    gig_enqueue_assets();

    $uid   = 'gig-' . substr(md5(wp_json_encode($a) . $src['path'] . wp_rand()), 0, 8);
    $col   = max(1, min(8, (int) $a['column']));
    $gap   = max(0, (int) $a['gap']);
    $auto  = ($a['ratio'] === 'auto');
    $ratio = $auto ? '4/3' : (preg_replace('#[^0-9/\. ]#', '', (string) $a['ratio']) ?: '4/3');

    ob_start();
    ?>
<div class="gig" id="<?php echo esc_attr($uid); ?>"
     data-lightbox="<?php echo ((int) $a['lightbox'] === 1) ? '1' : '0'; ?>"
     data-menu="<?php echo ((int) $a['context_menu'] === 1) ? '1' : '0'; ?>"
     data-blob="<?php echo esc_attr('https://github.com/' . $src['owner'] . '/' . $src['repo'] . '/blob/' . $src['branch'] . ($src['path'] !== '' ? '/' . $src['path'] : '')); ?>"
     style="--gig-col:<?php echo (int) $col; ?>;--gig-gap:<?php echo (int) $gap; ?>px;--gig-ratio:<?php echo esc_attr($ratio); ?>">

  <span class="gig-anchor" id="<?php echo esc_attr('gig-at-' . $token); ?>"></span>

  <?php if ((int) $a['show_version'] === 1) : ?>
  <div class="gig-ver">version <?php echo esc_html(GIG_VERSION); ?></div>
  <?php endif; ?>

  <div class="gig-bar">
    <div class="gig-field gig-groups">
      <button type="button" class="gig-drop-btn" aria-expanded="false" aria-haspopup="true">
        <span class="gig-drop-label"><?php esc_html_e('All groups', 'github-image-gallery'); ?></span>
        <span class="gig-caret" aria-hidden="true">&#9662;</span>
      </button>
      <div class="gig-drop" role="group" aria-label="<?php esc_attr_e('Select groups', 'github-image-gallery'); ?>" hidden>
        <div class="gig-drop-head">
          <button type="button" class="gig-mini" data-all="1"><?php esc_html_e('Select all', 'github-image-gallery'); ?></button>
          <button type="button" class="gig-mini" data-all="0"><?php esc_html_e('Clear', 'github-image-gallery'); ?></button>
        </div>
        <ul class="gig-drop-list">
          <?php foreach ($gcount as $slug => $n) : ?>
          <li><label>
            <input type="checkbox" value="<?php echo esc_attr($slug); ?>" <?php checked(in_array($slug, $preset, true)); ?>>
            <span class="gig-gname"><?php echo esc_html(gig_group_label($slug)); ?></span>
            <span class="gig-gnum"><?php echo (int) $n; ?></span>
          </label></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>

    <div class="gig-field">
      <label class="gig-lab" for="<?php echo esc_attr($uid); ?>-sort"><?php esc_html_e('Sort', 'github-image-gallery'); ?></label>
      <select id="<?php echo esc_attr($uid); ?>-sort" class="gig-sort">
        <option value="name_asc"  <?php selected($sort, 'name_asc');  ?>><?php esc_html_e('Name A → Z', 'github-image-gallery'); ?></option>
        <option value="name_desc" <?php selected($sort, 'name_desc'); ?>><?php esc_html_e('Name Z → A', 'github-image-gallery'); ?></option>
        <?php if ($has_dates) : ?>
        <option value="date_desc" <?php selected($sort, 'date_desc'); ?>><?php esc_html_e('Newest first', 'github-image-gallery'); ?></option>
        <option value="date_asc"  <?php selected($sort, 'date_asc');  ?>><?php esc_html_e('Oldest first', 'github-image-gallery'); ?></option>
        <?php endif; ?>
      </select>
    </div>

    <?php if ((int) $a['show_search'] === 1) : ?>
    <div class="gig-field gig-grow">
      <label class="gig-lab" for="<?php echo esc_attr($uid); ?>-q"><?php esc_html_e('Search', 'github-image-gallery'); ?></label>
      <input id="<?php echo esc_attr($uid); ?>-q" class="gig-q" type="search"
             placeholder="<?php esc_attr_e('Filter by name', 'github-image-gallery'); ?>" autocomplete="off">
    </div>
    <?php endif; ?>

    <?php if ((int) $a['show_refresh'] === 1) :
        // 목록 캐시를 버리고 GitHub에서 다시 받아오는 주소. nonce로 이 페이지에서 온 것만 받는다.
        $refresh_url = add_query_arg(array(
            'gig_refresh' => $token,
            '_gignonce'   => wp_create_nonce('gig_refresh_' . $token),
        )) . '#gig-at-' . $token;    // uid는 새로 그릴 때마다 달라진다: 자리표는 token으로 둔다
    ?>
    <div class="gig-field">
      <a class="gig-refresh" href="<?php echo esc_url($refresh_url); ?>" rel="nofollow"
         title="<?php esc_attr_e('Read the folder from GitHub again, ignoring the cached listing', 'github-image-gallery'); ?>">
        <span class="gig-refresh-icon" aria-hidden="true">&#8635;</span>
        <span><?php esc_html_e('Refresh', 'github-image-gallery'); ?></span>
      </a>
    </div>
    <?php endif; ?>

    <div class="gig-count" aria-live="polite"></div>
  </div>

  <?php if ($refresh === 'ok') : ?>
  <p class="gig-flash" role="status"><?php esc_html_e('Listing read from GitHub again.', 'github-image-gallery'); ?></p>
  <?php elseif ($refresh === 'stale') : ?>
  <p class="gig-flash gig-flash-warn" role="status"><?php esc_html_e('That refresh link had expired, so the cached listing is still showing. Reload the page and press Refresh again.', 'github-image-gallery'); ?></p>
  <?php endif; ?>

  <div class="gig-grid">
    <?php foreach ($files as $f) :
        $g     = isset($gmap[$f['name']]) ? $gmap[$f['name']] : '_other';
        $alt   = ucwords(str_replace('-', ' ', pathinfo($f['name'], PATHINFO_FILENAME)));
        $style = ($auto && $f['w'] > 0 && $f['h'] > 0) ? 'aspect-ratio:' . (int) $f['w'] . '/' . (int) $f['h'] : '';
    ?>
    <figure class="gig-item"
            data-name="<?php echo esc_attr(strtolower($f['name'])); ?>"
            data-group="<?php echo esc_attr($g); ?>"
            data-date="<?php echo (int) $f['date']; ?>">
      <a class="gig-link" href="<?php echo esc_url($f['url']); ?>"
         <?php if ($style) echo 'style="' . esc_attr($style) . '"'; ?>
         target="_blank" rel="noopener noreferrer">
        <img src="<?php echo esc_url($f['thumb']); ?>" alt="<?php echo esc_attr($alt); ?>"
             <?php if ($f['w'] > 0) : ?>width="<?php echo (int) $f['w']; ?>" height="<?php echo (int) $f['h']; ?>"<?php endif; ?>
             loading="lazy" decoding="async">
      </a>
      <?php if ((int) $a['show_name'] === 1 || ((int) $a['show_date'] === 1 && $f['date'])) : ?>
      <figcaption>
        <?php if ((int) $a['show_name'] === 1) : ?>
          <span class="gig-fn" title="<?php echo esc_attr($f['name']); ?>"><?php echo esc_html($f['name']); ?></span>
        <?php endif; ?>
        <?php if ((int) $a['show_date'] === 1 && $f['date']) : ?>
          <span class="gig-dt"><?php echo esc_html(date_i18n(get_option('date_format'), $f['date'])); ?></span>
        <?php endif; ?>
      </figcaption>
      <?php endif; ?>
    </figure>
    <?php endforeach; ?>
  </div>

  <p class="gig-empty" hidden><?php esc_html_e('No images match the current filter.', 'github-image-gallery'); ?></p>

  <?php if ($data['via'] === 'api' && current_user_can('manage_options')) : ?>
  <p class="gig-hint"><?php esc_html_e('index.json이 없어 GitHub API로 읽었습니다. 날짜 Sort과 썸네일 축소가 꺼집니다. (관리자에게만 보이는 안내)', 'github-image-gallery'); ?></p>
  <?php endif; ?>
</div>
    <?php
    return ob_get_clean();
}

function gig_notice($msg) {
    return '<p class="gig-error" style="padding:14px;border:1px solid rgba(200,80,80,.5);border-radius:8px;">'
         . esc_html($msg) . '</p>';
}
