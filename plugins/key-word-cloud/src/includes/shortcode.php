<?php
/**
 * [wpwordcloud] 숏코드. 속성이 설정값을 덮어쓴다.
 *
 * @package KeyWordCloud
 */

defined( 'ABSPATH' ) || exit;

final class KWC_Shortcode {

	/**
	 * 숏코드 등록.
	 */
	public static function register() {
		add_shortcode( 'wpwordcloud', array( __CLASS__, 'render' ) );
	}

	/**
	 * 숏코드 진입점.
	 *
	 * @param array|string $atts 숏코드 속성.
	 * @return string
	 */
	public static function render( $atts ) {
		$options = KWC_Cloud::options();

		$atts = shortcode_atts(
			array(
				'language'    => $options['language'],
				'shape'       => $options['shape'],
				'ratio'       => $options['ratio'],
				'width'       => $options['width_px'],
				'height'      => $options['height_px'],
				'font'        => $options['font'],
				'font_custom' => $options['font_custom'],
				'color_mode'  => $options['color_mode'],
				'max'         => $options['max_words'],
				'min_posts'   => $options['min_posts'],
				'min_size'    => $options['min_size'],
				'max_size'    => $options['max_size'],
				'color_start' => $options['color_start'],
				'color_end'   => $options['color_end'],
				'link'        => $options['link_mode'],
				'cache'       => $options['cache_ttl'],
			),
			$atts,
			'wpwordcloud'
		);

		$args = self::normalize( $atts, $options );
		if ( is_wp_error( $args ) ) {
			// 잘못된 속성을 기본값으로 덮어 쓰면 사용자는 먹은 줄 안다. 드러낸다.
			error_log( '[key-word-cloud] shortcode error: ' . $args->get_error_message() );
			return '<p class="kwc-error">[wpwordcloud] ' . esc_html( $args->get_error_message() ) . '</p>';
		}

		return KWC_Cloud::render( $args );
	}

	/**
	 * 속성을 검증해 내부 인자로 바꾼다. 값이 틀리면 WP_Error 를 돌려준다.
	 *
	 * @param array $atts    shortcode_atts 결과.
	 * @param array $options 설정값.
	 * @return array|WP_Error
	 */
	public static function normalize( array $atts, array $options ) {
		$language = strtolower( trim( (string) $atts['language'] ) );
		if ( ! in_array( $language, array( 'en', 'ko', 'both' ), true ) ) {
			return new WP_Error( 'kwc_bad_language', 'language 는 en, ko, both 중 하나여야 한다. 받은 값: ' . $atts['language'] );
		}

		$shape = strtolower( trim( (string) $atts['shape'] ) );
		if ( ! in_array( $shape, array( 'ellipse', 'block' ), true ) ) {
			return new WP_Error( 'kwc_bad_shape', 'shape 는 ellipse 또는 block 이어야 한다. 받은 값: ' . $atts['shape'] );
		}

		$color_mode = strtolower( trim( (string) $atts['color_mode'] ) );
		if ( ! in_array( $color_mode, array( 'palette', 'gradient' ), true ) ) {
			return new WP_Error( 'kwc_bad_color_mode', 'color_mode 는 palette 또는 gradient 여야 한다. 받은 값: ' . $atts['color_mode'] );
		}

		$font = strtolower( trim( (string) $atts['font'] ) );
		if ( ! in_array( $font, array( 'rounded', 'sans', 'serif', 'mono', 'theme', 'custom' ), true ) ) {
			return new WP_Error( 'kwc_bad_font', 'font 는 rounded, sans, serif, mono, theme, custom 중 하나여야 한다. 받은 값: ' . $atts['font'] );
		}

		$font_custom = KWC_Cloud::sanitize_font_family( $atts['font_custom'] );
		if ( 'custom' === $font && '' === $font_custom ) {
			// 고른 글꼴이 비어 있으면 조용히 기본으로 되돌리지 않고 알린다.
			return new WP_Error( 'kwc_no_font_custom', 'font=custom 인데 font_custom 이 비었거나 쓸 수 없는 값이다.' );
		}

		$ratio = KWC_Cloud::sanitize_ratio( $atts['ratio'] );
		if ( null === $ratio ) {
			return new WP_Error( 'kwc_bad_ratio', 'ratio 는 0.5 에서 5 사이의 수여야 한다. 받은 값: ' . $atts['ratio'] );
		}

		$link = strtolower( trim( (string) $atts['link'] ) );
		if ( ! in_array( $link, array( 'search', 'none' ), true ) ) {
			return new WP_Error( 'kwc_bad_link', 'link 는 search 또는 none 이어야 한다. 받은 값: ' . $atts['link'] );
		}

		$ints = array(
			'max'       => array( 1, 500 ),
			'min_posts' => array( 1, 1000 ),
			'min_size'  => array( 6, 200 ),
			'max_size'  => array( 6, 200 ),
			'width'     => array( 0, 4000 ),
			'height'    => array( 0, 4000 ),
			'cache'     => array( 0, 604800 ),
		);
		$values = array();
		foreach ( $ints as $name => $range ) {
			$raw = trim( (string) $atts[ $name ] );
			if ( '' === $raw || 1 !== preg_match( '/^\d+$/', $raw ) ) {
				return new WP_Error( 'kwc_bad_int', $name . ' 은 정수여야 한다. 받은 값: ' . $atts[ $name ] );
			}
			$value = (int) $raw;
			if ( $value < $range[0] || $value > $range[1] ) {
				return new WP_Error( 'kwc_out_of_range', sprintf( '%s 는 %d..%d 범위여야 한다. 받은 값: %d', $name, $range[0], $range[1], $value ) );
			}
			$values[ $name ] = $value;
		}
		if ( $values['min_size'] >= $values['max_size'] ) {
			return new WP_Error( 'kwc_size_order', sprintf( 'min_size(%d) 는 max_size(%d) 보다 작아야 한다.', $values['min_size'], $values['max_size'] ) );
		}

		$color_start = KWC_Cloud::sanitize_hex( $atts['color_start'] );
		$color_end   = KWC_Cloud::sanitize_hex( $atts['color_end'] );
		if ( null === $color_start || null === $color_end ) {
			return new WP_Error( 'kwc_bad_color', 'color_start / color_end 는 #rrggbb 형식이어야 한다.' );
		}

		return array(
			'language'    => $language,
			'shape'       => $shape,
			'ratio'       => $ratio,
			'width_px'    => $values['width'],
			'height_px'   => $values['height'],
			'font'        => $font,
			'font_custom' => $font_custom,
			'color_mode'  => $color_mode,
			'max_words'   => $values['max'],
			'min_posts'   => $values['min_posts'],
			'min_size'    => $values['min_size'],
			'max_size'    => $values['max_size'],
			'color_start' => $color_start,
			'color_end'   => $color_end,
			'link_mode'   => $link,
			'cache_ttl'   => $values['cache'],
			'cache_salt'  => (int) $options['cache_salt'],
		);
	}
}
