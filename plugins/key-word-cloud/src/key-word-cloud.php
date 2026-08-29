<?php
/**
 * Plugin Name:       Key Word Cloud
 * Plugin URI:        https://github.com/ykim2718/WordPress
 * Description:       Draws the topics of your site as a word cloud. Topics are prepared elsewhere by a language model and uploaded over REST, so the site only stores and draws them.
 * Version:           2.10.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            yRocket
 * License:           GPL-2.0-or-later
 * Text Domain:       key-word-cloud
 *
 * 사용법:
 *   [wpwordcloud]
 *   [wpwordcloud language="ko" max="30" min_posts="3" color_end="#b3202e"]
 *
 * 옵션은 WP Admin 사이드바의 Key Word Cloud 메뉴에서 정하고, 숏코드 속성이 그것을 덮어쓴다.
 * 잘못된 속성은 기본값으로 되돌리지 않고 화면에 오류로 드러낸다.
 *
 * Topic 자체는 여기서 만들지 않는다. tools/ 의 파이프라인이 GPU 가 있는 기계에서 만들어
 * /wp-json/key-word-cloud/v1/topics 로 올린다.
 *
 * @package KeyWordCloud
 */

if (!defined('ABSPATH')) exit;

define('KWC_VERSION', '2.10.0');
define('KWC_FILE', __FILE__);
define('KWC_DIR', plugin_dir_path(__FILE__));
define('KWC_URL', plugin_dir_url(__FILE__));
define('KWC_OPTION', 'kwc_settings');

require_once KWC_DIR . 'includes/defaults.php';
require_once KWC_DIR . 'includes/language.php';
require_once KWC_DIR . 'includes/topics.php';
require_once KWC_DIR . 'includes/cloud.php';
require_once KWC_DIR . 'includes/shortcode.php';
require_once KWC_DIR . 'includes/block.php';
require_once KWC_DIR . 'includes/settings.php';
require_once KWC_DIR . 'includes/updater.php';

add_action('init', array('KWC_Shortcode', 'register'));
add_action('init', array('KWC_Cloud', 'register_assets'));
add_action('init', array('KWC_Block', 'register'));
add_action('rest_api_init', array('KWC_Topics', 'register_routes'));
add_action('enqueue_block_editor_assets', array('KWC_Block', 'enqueue_editor_assets'));
add_action(KWC_Topics::CRON_HOOK, array('KWC_Topics', 'pull'));

if (is_admin()) {
    KWC_Settings::init();
}

register_activation_hook(__FILE__, array('KWC_Settings', 'on_activate'));

/** 비활성화하면 하루 한 번의 일정도 걷는다. 남겨 두면 없는 코드를 부르게 된다. */
register_deactivation_hook(__FILE__, function () {
    KWC_Topics::schedule(false);
});

/** 설정과 topic 은 남기고 캐시만 비운다. 비활성화할 때도 한 번 돈다. */
function kwc_flush_cache() {
    global $wpdb;
    $wpdb->query(
        "DELETE FROM {$wpdb->options}
          WHERE option_name LIKE '_transient_kwc_%'
             OR option_name LIKE '_transient_timeout_kwc_%'"
    );
}

register_deactivation_hook(__FILE__, 'kwc_flush_cache');
