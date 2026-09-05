<?php
/**
 * Plugin Name:       Green Grass
 * Plugin URI:        https://github.com/ykim2718/WordPress
 * Description:       Draws a year of activity as GitHub's contribution calendar — a grid of green squares, one per day. Counts this site's posts or comments, or a GitHub account's contributions.
 * Version:           0.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            yRocket
 * License:           GPL-2.0-or-later
 * Text Domain:       green-grass
 *
 * 사용법:
 *   [green_grass]
 *   [green_grass orientation="vertical" from="2026-01-01" to="2026-06-30"]
 *   [green_grass source="github" user="ykim2718"]
 *
 * 옵션은 WP Admin 사이드바의 Green Grass 메뉴에서 정하고, 숏코드 속성이 그것을 덮어쓴다.
 * 잘못된 속성은 기본값으로 되돌리지 않고 화면에 오류로 드러낸다.
 *
 * @package GreenGrass
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GG_VERSION', '0.0.0' );
define( 'GG_FILE', __FILE__ );
define( 'GG_DIR', plugin_dir_path( __FILE__ ) );
define( 'GG_URL', plugin_dir_url( __FILE__ ) );
define( 'GG_OPTION', 'gg_settings' );

require_once GG_DIR . 'includes/defaults.php';
require_once GG_DIR . 'includes/range.php';
require_once GG_DIR . 'includes/palette.php';
require_once GG_DIR . 'includes/source.php';
require_once GG_DIR . 'includes/github.php';
require_once GG_DIR . 'includes/calendar.php';
require_once GG_DIR . 'includes/shortcode.php';
require_once GG_DIR . 'includes/block.php';
require_once GG_DIR . 'includes/settings.php';
require_once GG_DIR . 'includes/updater.php';

add_action( 'init', array( 'GG_Shortcode', 'register' ) );
add_action( 'init', array( 'GG_Calendar', 'register_assets' ) );
add_action( 'init', array( 'GG_Block', 'register' ) );
add_action( 'enqueue_block_editor_assets', array( 'GG_Block', 'enqueue_editor_assets' ) );

if ( is_admin() ) {
	GG_Settings::init();
}

register_activation_hook( __FILE__, array( 'GG_Settings', 'on_activate' ) );

/** 설정은 남기고 세어 둔 날짜만 버린다. 비활성화할 때도 한 번 돈다. */
function gg_flush_cache() {
	global $wpdb;
	$wpdb->query(
		"DELETE FROM {$wpdb->options}
		  WHERE option_name LIKE '_transient_gg_%'
		     OR option_name LIKE '_transient_timeout_gg_%'"
	);
}

register_deactivation_hook( __FILE__, 'gg_flush_cache' );
