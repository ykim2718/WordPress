<?php
/**
 * Gutenberg block. 숏코드와 같은 코드 경로를 쓴다.
 *
 * block 속성 이름은 숏코드 속성 이름과 같고, 값은 전부 문자열이다.
 * 빈 문자열은 "설정 화면의 값을 쓴다"는 뜻이므로 기본값이 두 곳에 복사되지 않는다.
 *
 * @package KeyWordCloud
 */

defined( 'ABSPATH' ) || exit;

final class KWC_Block {

	const NAME     = 'key-word-cloud/word-cloud';
	const CATEGORY = 'yrocket';

	/**
	 * 인서터의 yRocket 묶음. block.json 의 category 가 이 slug 를 가리킨다.
	 *
	 * 등록되지 않은 category 를 가리키면 block 이 인서터에서 사라지므로,
	 * 이 filter 가 없으면 block 도 없는 셈이다.
	 *
	 * @param array $categories 기존 category 목록.
	 * @return array
	 */
	public static function add_category( $categories ) {
		if ( ! is_array( $categories ) ) {
			error_log( '[key-word-cloud] block categories filter received a non-array' );
			return $categories;
		}
		foreach ( $categories as $category ) {
			if ( isset( $category['slug'] ) && self::CATEGORY === $category['slug'] ) {
				return $categories;   // 이미 누가 만들어 두었다
			}
		}
		$categories[] = array(
			'slug'  => self::CATEGORY,
			'title' => 'yRocket',
			'icon'  => null,
		);
		return $categories;
	}

	/**
	 * block 등록. init 에서 부른다.
	 */
	public static function register() {
		if ( ! function_exists( 'register_block_type' ) ) {
			error_log( '[key-word-cloud] register_block_type() is missing; the block is unavailable on this WordPress version' );
			return;
		}

		$dir = KWC_DIR . 'blocks/word-cloud';
		if ( ! file_exists( $dir . '/block.json' ) ) {
			// 파일이 빠진 채 배포되면 block 만 조용히 사라진다. 로그에 남긴다.
			error_log( '[key-word-cloud] block.json not found at ' . $dir );
			return;
		}

		// 편집 화면의 미리보기는 REST 로 오므로 stylesheet 를 따로 걸어 주어야 가로 배치가 유지된다.
		$registered = register_block_type(
			$dir,
			array(
				'render_callback' => array( __CLASS__, 'render' ),
				'style'           => 'key-word-cloud',
				'editor_style'    => 'key-word-cloud',
			)
		);
		if ( false === $registered ) {
			error_log( '[key-word-cloud] register_block_type failed for ' . self::NAME );
		}
	}

	/**
	 * 서버 렌더링. 값이 있는 속성만 숏코드로 넘긴다.
	 *
	 * @param array $attributes block 속성.
	 * @return string
	 */
	public static function render( $attributes ) {
		$atts = array();
		foreach ( (array) $attributes as $key => $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			$value = trim( (string) $value );
			if ( '' !== $value ) {
				$atts[ $key ] = $value;
			}
		}

		$html = KWC_Shortcode::render( $atts );

		$wrapper = function_exists( 'get_block_wrapper_attributes' ) ? get_block_wrapper_attributes() : 'class="wp-block-key-word-cloud-word-cloud"';
		return '<div ' . $wrapper . '>' . $html . '</div>';
	}
}

// category 는 편집 화면이 열릴 때 필요하므로 block 등록과 무관하게 건다.
add_filter( 'block_categories_all', array( 'KWC_Block', 'add_category' ) );
