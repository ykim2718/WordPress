<?php
/**
 * 기본 설정값.
 *
 * @package KeyWordCloud
 */

defined( 'ABSPATH' ) || exit;

final class KWC_Defaults {

	/**
	 * 설정 기본값.
	 *
	 * @return array
	 */
	public static function options() {
		return array(
			'language'    => 'en',        // en | ko | both
			'max_words'   => 60,
			'min_posts'   => 2,           // 이보다 적은 글에서 온 topic 은 그리지 않는다
			'min_size'    => 12,
			'max_size'    => 44,
			'color_start' => '#8aa4c8',
			'color_end'   => '#12355b',
			'link_mode'   => 'search',    // search | none
			'cache_ttl'   => 3600,
			'pull_enabled' => 1,          // 하루 한 번 topic 을 받아올지
			'pull_url'     => 'https://raw.githubusercontent.com/ykim2718/WordPress/main/plugins/key-word-cloud/dist/topics.json',
			'cache_salt'  => 1,
		);
	}
}
