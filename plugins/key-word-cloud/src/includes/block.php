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

	const NAME = 'key-word-cloud/word-cloud';

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
