<?php
/**
 * [green_grass] 숏코드. 속성이 설정값을 덮어쓴다.
 *
 * 잘못 적은 속성은 기본값으로 되돌리지 않는다. 조용히 되돌리면 왜 안 먹었는지
 * 알 방법이 없어, 같은 값을 몇 번이고 다시 적게 된다. 화면에 오류로 드러낸다.
 *
 * @package GreenGrass
 */

defined( 'ABSPATH' ) || exit;

final class GG_Shortcode {

	const TAG = 'green_grass';

	/** 속성 이름 => 설정값 이름. 편집기 쪽도 이 표를 쓴다. */
	const SETTING_OF = array(
		'source'      => 'source',
		'user'        => 'gh_user',
		'post_types'  => 'post_types',
		'orientation' => 'orientation',
		'period'      => 'period',
		'months'      => 'months',
		'from'        => 'from',
		'to'          => 'to',
		'week_start'  => 'week_start',
		'cell'        => 'cell',
		'gap'         => 'gap',
		'radius'      => 'radius',
		'palette'     => 'palette',
		'color'       => 'color',
		'empty'       => 'empty_color',
		'scale'       => 'scale',
		'show_months' => 'show_months',
		'show_days'   => 'show_days',
		'show_legend' => 'show_legend',
		'show_total'  => 'show_total',
		'link'        => 'link_mode',
		'cache'       => 'cache_ttl',
	);

	/** 참으로 읽을 값. 그 밖의 값은 거짓이 아니라 오류다. */
	const TRUE_WORDS  = array( '1', 'true', 'yes', 'on' );
	const FALSE_WORDS = array( '0', 'false', 'no', 'off', '' );

	/**
	 * 숏코드 등록.
	 */
	public static function register() {
		add_shortcode( self::TAG, array( __CLASS__, 'render' ) );
	}

	/**
	 * 숏코드 진입점.
	 *
	 * @param array|string $atts 숏코드 속성.
	 * @return string
	 */
	public static function render( $atts ) {
		$options = GG_Calendar::options();
		$given   = is_array( $atts ) ? array_change_key_case( $atts, CASE_LOWER ) : array();

		$defaults = array();
		foreach ( self::SETTING_OF as $attribute => $option ) {
			$defaults[ $attribute ] = isset( $options[ $option ] ) ? $options[ $option ] : '';
		}

		$atts = shortcode_atts( $defaults, $given, self::TAG );

		// from 이나 to 를 적었으면 그 날짜를 쓰겠다는 뜻이다. period 까지 함께 적게 하면
		// 잊기 쉽고, 잊으면 적어 둔 날짜가 조용히 무시된다.
		if ( ! isset( $given['period'] ) && ( isset( $given['from'] ) || isset( $given['to'] ) ) ) {
			$atts['period'] = 'dates';
		}

		$args = self::normalize( $atts );
		if ( is_wp_error( $args ) ) {
			return GG_Calendar::error( $args->get_error_message() );
		}

		return GG_Calendar::render( $args );
	}

	/**
	 * 속성을 검증해 내부 인자로 바꾼다.
	 *
	 * @param array $atts shortcode_atts 결과.
	 * @return array|WP_Error
	 */
	public static function normalize( array $atts ) {
		$out    = array();
		$errors = array();

		$words = array(
			'source'      => GG_Source::ALL,
			'orientation' => array( 'horizontal', 'vertical' ),
			'period'      => array( 'months', 'dates' ),
			'week_start'  => array( 'sunday', 'monday' ),
			'palette'     => array( 'github', 'custom' ),
			'scale'       => array( 'quantile', 'linear' ),
			'link'        => array( 'archive', 'none' ),
		);
		foreach ( $words as $name => $allowed ) {
			$value = strtolower( trim( (string) $atts[ $name ] ) );
			if ( in_array( $value, $allowed, true ) ) {
				$out[ $name ] = $value;
			} else {
				$errors[] = $name . ' 는 ' . implode( ' 또는 ', $allowed ) . ' 여야 한다: ' . $value;
			}
		}

		$numbers = array(
			'months' => array( 1, 120 ),
			'cell'   => array( GG_Calendar::MIN_CELL, GG_Calendar::MAX_CELL ),
			'gap'    => array( 0, 12 ),
			'radius' => array( 0, 20 ),
			'cache'  => array( 0, YEAR_IN_SECONDS ),
		);
		foreach ( $numbers as $name => $bounds ) {
			$raw = trim( (string) $atts[ $name ] );
			if ( 1 !== preg_match( '/^\d+$/', $raw ) ) {
				$errors[] = $name . ' 는 0 이상의 정수여야 한다: ' . $raw;
				continue;
			}
			$value = (int) $raw;
			if ( $value < $bounds[0] || $value > $bounds[1] ) {
				$errors[] = sprintf( '%s 는 %d 과 %d 사이여야 한다: %d', $name, $bounds[0], $bounds[1], $value );
				continue;
			}
			$out[ $name ] = $value;
		}

		foreach ( array( 'show_months', 'show_days', 'show_legend', 'show_total' ) as $name ) {
			$value = strtolower( trim( (string) $atts[ $name ] ) );
			if ( in_array( $value, self::TRUE_WORDS, true ) ) {
				$out[ $name ] = 1;
			} elseif ( in_array( $value, self::FALSE_WORDS, true ) ) {
				$out[ $name ] = 0;
			} else {
				$errors[] = $name . ' 는 1 또는 0 이어야 한다: ' . $value;
			}
		}

		foreach ( array( 'color' => 'color', 'empty' => 'empty' ) as $name ) {
			$value = trim( (string) $atts[ $name ] );
			if ( GG_Palette::is_color( $value ) ) {
				$out[ $name ] = GG_Palette::normalize( $value );
			} else {
				$errors[] = $name . ' 는 #rrggbb 여야 한다: ' . $value;
			}
		}

		// 날짜는 여기서 모양만 보고, 앞뒤가 맞는지는 GG_Range 가 본다. 구간을 정하는
		// 규칙이 두 곳에 나뉘면 한쪽만 고치는 날이 온다.
		foreach ( array( 'from', 'to' ) as $name ) {
			$value = trim( (string) $atts[ $name ] );
			if ( '' !== $value && null === GG_Range::parse( $value ) ) {
				$errors[] = $name . ' 는 YYYY-MM-DD 형식의 실재하는 날이어야 한다: ' . $value;
				continue;
			}
			$out[ $name ] = $value;
		}
		// from 자체가 틀렸으면 위에서 이미 알렸다. 없다고 한 번 더 말하지 않는다.
		if ( 'dates' === ( isset( $out['period'] ) ? $out['period'] : '' ) && isset( $out['from'] ) && '' === $out['from'] ) {
			$errors[] = '날짜로 구간을 정하려면 from 을 적어야 한다.';
		}

		$user = trim( (string) $atts['user'] );
		if ( '' !== $user && ! GG_GitHub::is_login( $user ) ) {
			$errors[] = 'user 가 GitHub 계정 이름이 아니다: ' . $user;
		}
		$out['user'] = $user;
		if ( 'github' === ( isset( $out['source'] ) ? $out['source'] : '' ) && '' === $user ) {
			$errors[] = 'source="github" 에는 user 가 있어야 한다.';
		}

		$types = GG_Source::parse_post_types( $atts['post_types'] );
		if ( 'posts' === ( isset( $out['source'] ) ? $out['source'] : '' ) && empty( $types ) ) {
			$errors[] = 'post_types 에 이 사이트에 있는 글 종류가 하나도 없다: ' . $atts['post_types'];
		}
		$out['post_types'] = implode( ',', $types );

		if ( ! empty( $errors ) ) {
			return new WP_Error( 'gg_bad_atts', implode( ' / ', $errors ) );
		}

		// 그리는 쪽은 설정값 이름으로 읽는다. 여기서 한 번만 옮긴다.
		return array(
			'source'      => $out['source'],
			'gh_user'     => $out['user'],
			'post_types'  => $out['post_types'],
			'orientation' => $out['orientation'],
			'period'      => $out['period'],
			'months'      => $out['months'],
			'from'        => $out['from'],
			'to'          => $out['to'],
			'week_start'  => $out['week_start'],
			'cell'        => $out['cell'],
			'gap'         => $out['gap'],
			'radius'      => $out['radius'],
			'palette'     => $out['palette'],
			'color'       => $out['color'],
			'empty_color' => $out['empty'],
			'scale'       => $out['scale'],
			'show_months' => $out['show_months'],
			'show_days'   => $out['show_days'],
			'show_legend' => $out['show_legend'],
			'show_total'  => $out['show_total'],
			'link_mode'   => $out['link'],
			'cache_ttl'   => $out['cache'],
		);
	}
}
