<?php
/**
 * Plugin Name:       GitHub Image Gallery
 * Plugin URI:        https://github.com/ykim2718/WordPress
 * Description:       Shows a folder of images from a public GitHub repository as a filterable thumbnail gallery. Groups are derived from the file names and offered as a multi-select dropdown.
 * Version:           1.0.6
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            yRocket
 * License:           GPL-2.0-or-later
 * Text Domain:       github-image-gallery
 *
 * 사용법:
 *   [github_image_gallery github_url="https://github.com/ykim2718/WordPress/tree/main/Images"]
 *
 * 그림 목록은 두 경로로 읽는다.
 *   1) 폴더 안의 index.json  -- tools/build_image_index.py가 CI에서 만들어 둔 것.
 *      raw.githubusercontent.com에서 파일 하나만 받으면 되고 API 한도를 쓰지 않으며,
 *      commit 날짜와 가로세로, 480px 썸네일 경로가 이미 들어 있다.
 *   2) index.json이 없으면 GitHub contents API로 폴더를 훑는다 (시간당 60회 한도).
 */

if (!defined('ABSPATH')) exit;

define('GIG_VERSION', '1.0.6');
define('GIG_FILE', __FILE__);
define('GIG_DIR', plugin_dir_path(__FILE__));
define('GIG_URL', plugin_dir_url(__FILE__));

require_once GIG_DIR . 'includes/gallery.php';
require_once GIG_DIR . 'includes/updater.php';

add_shortcode('github_image_gallery', 'gig_render');

/** shortcode가 실제로 쓰인 페이지에서만 자산을 싣는다 */
function gig_enqueue_assets() {
    if (wp_style_is('gig-gallery', 'enqueued')) return;
    wp_enqueue_style('gig-gallery', GIG_URL . 'assets/gallery.css', array(), GIG_VERSION);
    wp_enqueue_script('gig-gallery', GIG_URL . 'assets/gallery.js', array(), GIG_VERSION, true);
    wp_localize_script('gig-gallery', 'GIG_I18N', array(
        'all'      => __('All groups', 'github-image-gallery'),
        /* translators: %d: number of selected groups */
        'selected' => __('%d selected', 'github-image-gallery'),
        'copyLink' => __('Copy GitHub image link address', 'github-image-gallery'),
        'copyName' => __('Copy file name', 'github-image-gallery'),
        'copyMd'   => __('Copy as Markdown', 'github-image-gallery'),
        'openTab'  => __('Open image in new tab', 'github-image-gallery'),
        'openGh'   => __('Open on GitHub', 'github-image-gallery'),
        'copied'   => __('Copied', 'github-image-gallery'),
        'copyFail' => __('Could not copy', 'github-image-gallery'),
    ));
}

/** 설정 -> 플러그인 목록에서 캐시를 비울 수 있게 한다 */
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    $url = wp_nonce_url(admin_url('plugins.php?gig_flush=1'), 'gig_flush');
    array_unshift($links, '<a href="' . esc_url($url) . '">' . esc_html__('Flush cache', 'github-image-gallery') . '</a>');
    return $links;
});

add_action('admin_init', function () {
    if (empty($_GET['gig_flush']) || !current_user_can('activate_plugins')) return;
    check_admin_referer('gig_flush');
    gig_flush_cache();
    wp_safe_redirect(admin_url('plugins.php'));
    exit;
});

function gig_flush_cache() {
    global $wpdb;
    $wpdb->query(
        "DELETE FROM {$wpdb->options}
          WHERE option_name LIKE '_transient_gig_%'
             OR option_name LIKE '_transient_timeout_gig_%'"
    );
}

register_deactivation_hook(__FILE__, 'gig_flush_cache');
