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
				'ranking'     => $options['ranking'],
				'min_docs_pct' => $options['min_docs_pct'],
				'source'      => $options['source'],
				'post_type'   => implode( ',', (array) $options['post_types'] ),
				'category'    => '',
				'tag'         => '',
				'limit'       => $options['scan_limit'],
				'max'         => $options['max_words'],
				'min_count'   => $options['min_count'],
				'min_len'     => $options['min_len'],
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
		$source = strtolower( trim( (string) $atts['source'] ) );
		if ( ! in_array( $source, array( 'content', 'excerpt' ), true ) ) {
			return new WP_Error( 'kwc_bad_source', 'source 는 content 또는 excerpt 여야 한다. 받은 값: ' . $atts['source'] );
		}

		$ranking = strtolower( trim( (string) $atts['ranking'] ) );
		if ( ! in_array( $ranking, array( 'tfidf', 'count', 'topics' ), true ) ) {
			return new WP_Error( 'kwc_bad_ranking', 'ranking 은 tfidf, count, topics 중 하나여야 한다. 받은 값: ' . $atts['ranking'] );
		}

		$link = strtolower( trim( (string) $atts['link'] ) );
		if ( ! in_array( $link, array( 'search', 'none' ), true ) ) {
			return new WP_Error( 'kwc_bad_link', 'link 는 search 또는 none 이어야 한다. 받은 값: ' . $atts['link'] );
		}

		$registered = get_post_types( array( 'public' => true ), 'names' );
		$post_types = array();
		foreach ( array_filter( array_map( 'trim', explode( ',', (string) $atts['post_type'] ) ) ) as $type ) {
			if ( ! isset( $registered[ $type ] ) ) {
				return new WP_Error( 'kwc_bad_post_type', '등록되지 않았거나 공개가 아닌 post type: ' . $type );
			}
			$post_types[] = $type;
		}
		if ( empty( $post_types ) ) {
			return new WP_Error( 'kwc_no_post_type', 'post_type 이 비었다.' );
		}

		$ints = array(
			'limit'     => array( 1, 5000 ),
			'max'       => array( 1, 500 ),
			'min_count' => array( 1, 1000 ),
			'min_docs_pct' => array( 0, 100 ),
			'min_len'   => array( 1, 20 ),
			'min_size'  => array( 6, 200 ),
			'max_size'  => array( 6, 200 ),
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
			'ranking'          => $ranking,
			'min_docs_pct'     => $values['min_docs_pct'],
			'source'           => $source,
			'excerpt_fallback' => (int) $options['excerpt_fallback'],
			'post_types'       => $post_types,
			'category'         => sanitize_title( (string) $atts['category'] ),
			'tag'              => sanitize_title( (string) $atts['tag'] ),
			'scan_limit'       => $values['limit'],
			'max_words'        => $values['max'],
			'min_count'        => $values['min_count'],
			'min_len'          => $values['min_len'],
			'min_size'         => $values['min_size'],
			'max_size'         => $values['max_size'],
			'color_start'      => $color_start,
			'color_end'        => $color_end,
			'link_mode'        => $link,
			'cache_ttl'        => $values['cache'],
			'kr_particles_on'  => (int) $options['kr_particles_on'],
			'kr_min_stem'      => (int) $options['kr_min_stem'],
			'kr_particles'     => KWC_Tokenizer::sort_particles( KWC_Defaults::to_list( $options['kr_particles'] ) ),
			'stopwords'        => KWC_Defaults::to_list( $options['stopwords'] ),
			'cache_salt'       => (int) $options['cache_salt'],
		);
	}
}
