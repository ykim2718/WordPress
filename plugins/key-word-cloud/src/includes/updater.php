<?php
/**
 * 업데이트를 저장소에서 바로 받는다. tag도 release도 쓰지 않는다.
 *
 * CI 또는 tools/build_plugin_dist.py 가 plugins/key-word-cloud/dist/ 에 두 파일을 만든다.
 *   version.json          {"version":"1.0.0","updated":"...","description":"...","changelog":"..."}
 *   key-word-cloud.zip    src/ 만 담은 압축
 *
 * 여기서는 raw.githubusercontent.com에서 version.json 하나만 읽어 버전을 비교하고,
 * 새 버전이면 zip 주소를 워드프레스에 넘긴다. GitHub API 한도를 쓰지 않는다.
 *
 * 플러그인 목록의 View details 창도 이 version.json 에서 온다.
 *
 * 새 버전 내보내기 = 헤더의 Version과 KWC_VERSION을 올려서 push. 그게 전부다.
 *
 * @package KeyWordCloud
 */

if (!defined('ABSPATH')) exit;

define('KWC_REPO', 'ykim2718/WordPress');
define('KWC_BRANCH', 'main');
define('KWC_SLUG', 'key-word-cloud');

function kwc_dist_url($file) {
    return 'https://raw.githubusercontent.com/' . KWC_REPO . '/' . KWC_BRANCH
         . '/plugins/' . KWC_SLUG . '/dist/' . $file;
}

/** 저장소에 올라와 있는 최신 버전. 캐시 6시간. */
function kwc_remote_version() {
    $key = 'kwc_ver_' . md5(KWC_REPO . KWC_BRANCH);
    $hit = get_transient($key);
    if (is_array($hit)) return $hit;

    $res = wp_remote_get(kwc_dist_url('version.json'), array(
        'timeout' => 12,
        'headers' => array('User-Agent' => 'wp-key-word-cloud/' . KWC_VERSION),
    ));

    $out = array();
    if (is_wp_error($res)) {
        // 조용히 넘기면 업데이트가 몇 달째 안 뜨는 이유를 알 수 없게 된다.
        error_log('[key-word-cloud] version.json request failed: ' . $res->get_error_message());
    } else {
        $code = (int) wp_remote_retrieve_response_code($res);
        if ($code !== 200) {
            error_log('[key-word-cloud] version.json HTTP ' . $code . ' url=' . kwc_dist_url('version.json'));
        } else {
            $data = json_decode(wp_remote_retrieve_body($res), true);
            if (!is_array($data) || empty($data['version'])) {
                error_log('[key-word-cloud] version.json has no version field');
            } else {
                $out = array(
                    'version' => (string) $data['version'],
                    'package' => kwc_dist_url(KWC_SLUG . '.zip'),
                    'date'    => (string) (isset($data['updated']) ? $data['updated'] : ''),
                    'desc'    => (string) (isset($data['description']) ? $data['description'] : ''),
                    'log'     => (string) (isset($data['changelog']) ? $data['changelog'] : ''),
                );
            }
        }
    }

    // 실패했을 때 짧게 캐시해서 매 요청마다 두드리지 않게 한다
    set_transient($key, $out, $out ? 6 * HOUR_IN_SECONDS : 2 * HOUR_IN_SECONDS);
    return $out;
}

add_filter('pre_set_site_transient_update_plugins', function ($transient) {
    if (!is_object($transient)) return $transient;

    $rel  = kwc_remote_version();
    $file = plugin_basename(KWC_FILE);
    $home = 'https://github.com/' . KWC_REPO;

    if (!empty($rel['version']) && version_compare($rel['version'], KWC_VERSION, '>')) {
        $transient->response[$file] = (object) array(
            'id'          => KWC_REPO,
            'slug'        => KWC_SLUG,
            'plugin'      => $file,
            'new_version' => $rel['version'],
            'url'         => $home,
            'package'     => $rel['package'],
            'tested'      => get_bloginfo('version'),
        );
    } else {
        // slug 를 여기 넣어 두어야 플러그인 목록에 View details 링크가 붙는다.
        $transient->no_update[$file] = (object) array(
            'id'          => KWC_REPO,
            'slug'        => KWC_SLUG,
            'plugin'      => $file,
            'new_version' => KWC_VERSION,
            'url'         => $home,
            'package'     => '',
        );
    }
    return $transient;
});

add_filter('plugins_api', function ($result, $action, $args) {
    if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== KWC_SLUG) {
        return $result;
    }
    $rel = kwc_remote_version();
    return (object) array(
        'name'          => 'Key Word Cloud',
        'slug'          => KWC_SLUG,
        'version'       => !empty($rel['version']) ? $rel['version'] : KWC_VERSION,
        'author'        => '<a href="https://github.com/' . KWC_REPO . '">yRocket</a>',
        'homepage'      => 'https://github.com/' . KWC_REPO,
        'download_link' => !empty($rel['package']) ? $rel['package'] : '',
        'last_updated'  => !empty($rel['date']) ? $rel['date'] : '',
        'requires'      => '5.8',
        'requires_php'  => '7.4',
        'tested'        => get_bloginfo('version'),
        'sections'      => array(
            'description' => !empty($rel['desc'])
                ? wp_kses_post($rel['desc'])
                : esc_html__('Draws the topics of your site as a word cloud.', 'key-word-cloud'),
            'changelog'   => !empty($rel['log']) ? wp_kses_post($rel['log']) : '',
        ),
    );
}, 10, 3);

/** zip 안의 폴더 이름을 플러그인 폴더 이름에 맞춘다 */
add_filter('upgrader_source_selection', function ($source, $remote_source, $upgrader, $hook_extra = null) {
    if (empty($hook_extra['plugin']) || $hook_extra['plugin'] !== plugin_basename(KWC_FILE)) {
        return $source;
    }
    $want = trailingslashit($remote_source) . KWC_SLUG;
    if (untrailingslashit($source) === $want) return $source;

    global $wp_filesystem;
    if ($wp_filesystem && $wp_filesystem->move($source, $want)) {
        return trailingslashit($want);
    }
    return $source;
}, 10, 4);

add_action('upgrader_process_complete', function ($upgrader, $extra) {
    if (!empty($extra['type']) && $extra['type'] === 'plugin') {
        delete_transient('kwc_ver_' . md5(KWC_REPO . KWC_BRANCH));
    }
}, 10, 2);
