<?php
/**
 * 업데이트를 저장소에서 바로 받는다. tag도 release도 쓰지 않는다.
 *
 * CI가 plugins/dist/ 에 두 파일을 만들어 둔다.
 *   version.json                 {"version":"1.0.1","updated":"..."}
 *   github-image-gallery.zip     플러그인 폴더만 담은 압축
 *
 * 여기서는 raw.githubusercontent.com에서 version.json 하나만 읽어 버전을 비교하고,
 * 새 버전이면 zip 주소를 워드프레스에 넘긴다. API 한도를 쓰지 않는다.
 *
 * 새 버전 내보내기 = 헤더의 Version과 GIG_VERSION을 올려서 push. 그게 전부다.
 */

if (!defined('ABSPATH')) exit;

define('GIG_REPO', 'ykim2718/WordPress');
define('GIG_BRANCH', 'main');
define('GIG_SLUG', 'github-image-gallery');

function gig_dist_url($file) {
    return 'https://raw.githubusercontent.com/' . GIG_REPO . '/' . GIG_BRANCH . '/plugins/dist/' . $file;
}

/** 저장소에 올라와 있는 최신 버전. 캐시 6시간. */
function gig_remote_version() {
    $key = 'gig_ver_' . md5(GIG_REPO . GIG_BRANCH);
    $hit = get_transient($key);
    if (is_array($hit)) return $hit;

    $res = wp_remote_get(gig_dist_url('version.json'), array(
        'timeout' => 12,
        'headers' => array('User-Agent' => 'wp-github-image-gallery/' . GIG_VERSION),
    ));

    $out = array();
    if (!is_wp_error($res) && (int) wp_remote_retrieve_response_code($res) === 200) {
        $data = json_decode(wp_remote_retrieve_body($res), true);
        if (is_array($data) && !empty($data['version'])) {
            $out = array(
                'version' => (string) $data['version'],
                'package' => gig_dist_url('github-image-gallery.zip'),
                'date'    => (string) ($data['updated'] ?? ''),
                'notes'   => (string) ($data['notes'] ?? ''),
            );
        }
    }

    // 실패했을 때 짧게 캐시해서 매 요청마다 두드리지 않게 한다
    set_transient($key, $out, $out ? 6 * HOUR_IN_SECONDS : 2 * HOUR_IN_SECONDS);
    return $out;
}

add_filter('pre_set_site_transient_update_plugins', function ($transient) {
    if (!is_object($transient)) return $transient;

    $rel  = gig_remote_version();
    $file = plugin_basename(GIG_FILE);
    $home = 'https://github.com/' . GIG_REPO;

    if (!empty($rel['version']) && version_compare($rel['version'], GIG_VERSION, '>')) {
        $transient->response[$file] = (object) array(
            'id'          => GIG_REPO,
            'slug'        => GIG_SLUG,
            'plugin'      => $file,
            'new_version' => $rel['version'],
            'url'         => $home,
            'package'     => $rel['package'],
            'tested'      => get_bloginfo('version'),
        );
    } else {
        $transient->no_update[$file] = (object) array(
            'id'          => GIG_REPO,
            'slug'        => GIG_SLUG,
            'plugin'      => $file,
            'new_version' => GIG_VERSION,
            'url'         => $home,
            'package'     => '',
        );
    }
    return $transient;
});

add_filter('plugins_api', function ($result, $action, $args) {
    if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== GIG_SLUG) {
        return $result;
    }
    $rel = gig_remote_version();
    return (object) array(
        'name'          => 'GitHub Image Gallery',
        'slug'          => GIG_SLUG,
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

/** zip 안의 폴더 이름을 플러그인 폴더 이름에 맞춘다 */
add_filter('upgrader_source_selection', function ($source, $remote_source, $upgrader, $hook_extra = null) {
    if (empty($hook_extra['plugin']) || $hook_extra['plugin'] !== plugin_basename(GIG_FILE)) {
        return $source;
    }
    $want = trailingslashit($remote_source) . GIG_SLUG;
    if (untrailingslashit($source) === $want) return $source;

    global $wp_filesystem;
    if ($wp_filesystem && $wp_filesystem->move($source, $want)) {
        return trailingslashit($want);
    }
    return $source;
}, 10, 4);

add_action('upgrader_process_complete', function ($upgrader, $extra) {
    if (!empty($extra['type']) && $extra['type'] === 'plugin') {
        delete_transient('gig_ver_' . md5(GIG_REPO . GIG_BRANCH));
    }
}, 10, 2);
