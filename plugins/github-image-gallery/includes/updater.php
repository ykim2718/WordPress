<?php
/**
 * GitHub release에서 플러그인 업데이트를 받아온다.
 *
 * 저장소 전체가 플러그인이 아니라 plugins/github-image-gallery/ 하위만 플러그인이므로,
 * GitHub이 자동 생성하는 source zip은 쓸 수 없다. .github/workflows/plugin-release.yml이
 * 그 폴더만 담은 github-image-gallery.zip을 만들어 release asset으로 붙이고,
 * 여기서는 그 asset을 내려받는다.
 *
 * release tag는 plugin-v1.0.1 처럼 GIG_TAG_PREFIX로 시작해야 한다. 저장소에 다른 tag가
 * 섞여 있어도 이 접두사로 걸러 낸다.
 */

if (!defined('ABSPATH')) exit;

define('GIG_REPO', 'ykim2718/WordPress');
define('GIG_TAG_PREFIX', 'plugin-v');
define('GIG_ASSET', 'github-image-gallery.zip');

/** 최신 release 정보 (버전, zip 주소, 공개일). 캐시 12시간. */
function gig_latest_release() {
    $key = 'gig_rel_' . md5(GIG_REPO . GIG_TAG_PREFIX);
    $hit = get_transient($key);
    if (is_array($hit)) return $hit;

    $args = array(
        'timeout' => 12,
        'headers' => array(
            'Accept'     => 'application/vnd.github+json',
            'User-Agent' => 'wp-github-image-gallery/' . GIG_VERSION,
        ),
    );
    if (defined('GITHUB_GALLERY_TOKEN') && GITHUB_GALLERY_TOKEN) {
        $args['headers']['Authorization'] = 'Bearer ' . GITHUB_GALLERY_TOKEN;
    }

    $res = wp_remote_get('https://api.github.com/repos/' . GIG_REPO . '/releases?per_page=20', $args);
    if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) {
        set_transient($key, array(), 2 * HOUR_IN_SECONDS);   // 실패해도 매번 두드리지 않게
        return array();
    }

    $found = array();
    foreach ((array) json_decode(wp_remote_retrieve_body($res), true) as $rel) {
        if (!is_array($rel) || !empty($rel['draft']) || !empty($rel['prerelease'])) continue;
        $tag = (string) ($rel['tag_name'] ?? '');
        if (strpos($tag, GIG_TAG_PREFIX) !== 0) continue;

        foreach ((array) ($rel['assets'] ?? array()) as $asset) {
            if (($asset['name'] ?? '') !== GIG_ASSET) continue;
            $found = array(
                'version' => substr($tag, strlen(GIG_TAG_PREFIX)),
                'package' => (string) $asset['browser_download_url'],
                'date'    => (string) ($rel['published_at'] ?? ''),
                'notes'   => (string) ($rel['body'] ?? ''),
                'url'     => (string) ($rel['html_url'] ?? ''),
            );
            break 2;                                          // release 목록은 최신순이다
        }
    }

    set_transient($key, $found, 12 * HOUR_IN_SECONDS);
    return $found;
}

/** 업데이트 목록에 끼워 넣기 */
add_filter('pre_set_site_transient_update_plugins', function ($transient) {
    if (!is_object($transient)) return $transient;

    $rel = gig_latest_release();
    if (empty($rel['version']) || empty($rel['package'])) return $transient;

    $slug = 'github-image-gallery';
    $file = plugin_basename(GIG_FILE);

    if (version_compare($rel['version'], GIG_VERSION, '>')) {
        $transient->response[$file] = (object) array(
            'id'          => GIG_REPO,
            'slug'        => $slug,
            'plugin'      => $file,
            'new_version' => $rel['version'],
            'url'         => $rel['url'],
            'package'     => $rel['package'],
            'tested'      => get_bloginfo('version'),
        );
    } else {
        $transient->no_update[$file] = (object) array(
            'id'          => GIG_REPO,
            'slug'        => $slug,
            'plugin'      => $file,
            'new_version' => GIG_VERSION,
            'url'         => 'https://github.com/' . GIG_REPO,
            'package'     => '',
        );
    }
    return $transient;
});

/** "자세히 보기" 창 */
add_filter('plugins_api', function ($result, $action, $args) {
    if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== 'github-image-gallery') {
        return $result;
    }
    $rel = gig_latest_release();
    return (object) array(
        'name'          => 'GitHub Image Gallery',
        'slug'          => 'github-image-gallery',
        'version'       => !empty($rel['version']) ? $rel['version'] : GIG_VERSION,
        'author'        => '<a href="https://github.com/' . GIG_REPO . '">yRocket</a>',
        'homepage'      => 'https://github.com/' . GIG_REPO,
        'download_link' => !empty($rel['package']) ? $rel['package'] : '',
        'last_updated'  => !empty($rel['date']) ? $rel['date'] : '',
        'sections'      => array(
            'description' => esc_html__('GitHub 저장소의 이미지 폴더를 thumbnail gallery로 보여 준다.', 'github-image-gallery'),
            'changelog'   => !empty($rel['notes']) ? wp_kses_post(nl2br($rel['notes'])) : '',
        ),
    );
}, 10, 3);

/**
 * zip 안의 폴더 이름을 플러그인 폴더 이름에 맞춘다.
 * 이 단계가 없으면 압축이 풀린 폴더 이름 그대로 새 플러그인이 생겨 버린다.
 */
add_filter('upgrader_source_selection', function ($source, $remote_source, $upgrader, $hook_extra = null) {
    if (empty($hook_extra['plugin']) || $hook_extra['plugin'] !== plugin_basename(GIG_FILE)) {
        return $source;
    }
    $want = trailingslashit($remote_source) . 'github-image-gallery';
    if (untrailingslashit($source) === $want) return $source;

    global $wp_filesystem;
    if ($wp_filesystem && $wp_filesystem->move($source, $want)) {
        return trailingslashit($want);
    }
    return $source;
}, 10, 4);

/** 업데이트를 누른 직후에는 캐시된 release 정보를 버린다 */
add_action('upgrader_process_complete', function ($upgrader, $extra) {
    if (!empty($extra['type']) && $extra['type'] === 'plugin') {
        delete_transient('gig_rel_' . md5(GIG_REPO . GIG_TAG_PREFIX));
    }
}, 10, 2);
