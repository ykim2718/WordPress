<?php
/**
 * Plugin Name:       Key Word Cloud
 * Plugin URI:        https://github.com/ykim2718/WordPress
 * Description:       Builds a word cloud from the content or the excerpt of your posts. Stopwords and Korean particle stripping are configurable, and clicking a word opens the post list for it.
 * Version:           1.3.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            yRocket
 * License:           GPL-2.0-or-later
 * Text Domain:       key-word-cloud
 *
 * 사용법:
 *   [wpwordcloud]
 *   [wpwordcloud source="excerpt" category="ai" max="40" min_count="2" color_end="#b3202e"]
 *
 * 옵션은 WP Admin 사이드바의 Key Word Cloud 메뉴에서 정하고, 숏코드 속성이 그것을 덮어쓴다.
 * 잘못된 속성은 기본값으로 되돌리지 않고 화면에 오류로 드러낸다.
 *
 * @package KeyWordCloud
 */

if (!defined('ABSPATH')) exit;

define('KWC_VERSION', '1.3.0');
define('KWC_FILE', __FILE__);
define('KWC_DIR', plugin_dir_path(__FILE__));
define('KWC_URL', plugin_dir_url(__FILE__));
define('KWC_OPTION', 'kwc_settings');

require_once KWC_DIR . 'includes/defaults.php';
require_once KWC_DIR . 'includes/tokenizer.php';
require_once KWC_DIR . 'includes/cloud.php';
require_once KWC_DIR . 'includes/shortcode.php';
require_once KWC_DIR . 'includes/topics.php';
require_once KWC_DIR . 'includes/block.php';
require_once KWC_DIR . 'includes/settings.php';
require_once KWC_DIR . 'includes/updater.php';

/**
 * mbstring 은 한국어 조사 분리와 글자수 계산의 전제다.
 * 없으면 조용히 이상한 결과를 내는 대신 관리자에게 드러낸다.
 */
function kwc_requirements_met() {
    return function_exists('mb_strlen') && function_exists('mb_substr');
}

add_action('admin_notices', function () {
    if (kwc_requirements_met() || !current_user_can('activate_plugins')) return;
    echo '<div class="notice notice-error"><p><strong>Key Word Cloud</strong>: PHP <code>mbstring</code> 확장이 없다. 단어 구름을 생성할 수 없다.</p></div>';
});

if (!kwc_requirements_met()) {
    error_log('[key-word-cloud] mbstring extension is missing; word cloud disabled');
}

add_action('init', array('KWC_Shortcode', 'register'));
add_action('init', array('KWC_Cloud', 'register_assets'));
add_action('init', array('KWC_Block', 'register'));
add_action('rest_api_init', array('KWC_Topics', 'register_routes'));

if (is_admin()) {
    KWC_Settings::init();
}

register_activation_hook(__FILE__, array('KWC_Settings', 'on_activate'));

/** 설정을 지우지 않고 캐시만 비운다. 비활성화할 때도 한 번 돈다. */
function kwc_flush_cache() {
    global $wpdb;
    $wpdb->query(
        "DELETE FROM {$wpdb->options}
          WHERE option_name LIKE '_transient_kwc_%'
             OR option_name LIKE '_transient_timeout_kwc_%'"
    );
}

register_deactivation_hook(__FILE__, 'kwc_flush_cache');
