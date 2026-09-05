<?php
/**
 * 기본 설정값.
 *
 * @package GreenGrass
 */

defined( 'ABSPATH' ) || exit;

final class GG_Defaults {

	/**
	 * 설정 기본값.
	 *
	 * @return array
	 */
	public static function options() {
		return array(
			'source'      => 'posts',     // posts | comments | github
			'post_types'  => 'post',      // source=posts 일 때 셀 글 종류
			'gh_user'     => '',          // source=github 일 때 볼 계정
			'orientation' => 'horizontal', // horizontal | vertical
			'period'      => 'months',    // months | dates
			'months'      => 12,          // period=months 일 때 오늘로부터 거슬러 셀 달 수
			'from'        => '',          // period=dates 일 때 시작일 YYYY-MM-DD
			'to'          => '',          // period=dates 일 때 끝일. 비면 오늘
			'week_start'  => 'sunday',    // sunday | monday
			'cell'        => 12,          // 칸 한 변의 px
			'gap'         => 3,           // 칸 사이 px
			'radius'      => 2,           // 칸 모서리 px
			'palette'     => 'github',    // github | custom
			'color'       => '#216e39',   // palette=custom 일 때 가장 짙은 칸의 색
			'empty_color' => '#ebedf0',   // 하루도 없는 칸의 색
			'scale'       => 'quantile',  // quantile | linear
			'show_months' => 1,
			'show_days'   => 1,
			'show_legend' => 1,
			'show_total'  => 1,
			'link_mode'   => 'archive',   // archive | none
			'cache_ttl'   => 3600,
		);
	}
}
