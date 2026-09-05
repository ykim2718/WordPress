<?php
/**
 * Gutenberg block. 숏코드와 같은 코드 경로를 쓴다.
 *
 * block 속성 이름은 숏코드 속성 이름과 같고, 값은 전부 문자열이다. 빈 문자열은
 * "설정 화면의 값을 쓴다" 는 뜻이므로 기본값이 두 곳에 복사되지 않는다.
 *
 * @package GreenGrass
 */

defined( 'ABSPATH' ) || exit;

final class GG_Block {

	const NAME     = 'green-grass/green-grass';
	const CATEGORY = 'yrocket';

	/** register_block_type 이 붙여 준 editor script 의 handle. 설정값을 실어 보낼 자리다. */
	private static $editor_handle = '';

	/**
	 * 인서터의 yRocket 묶음. block.json 의 category 가 이 slug 를 가리킨다.
	 *
	 * 등록되지 않은 category 를 가리키면 block 이 인서터에서 사라지므로,
	 * 이 filter 가 없으면 block 도 없는 셈이다. 같은 묶음을 쓰는 다른 플러그인이
	 * 이미 만들어 두었으면 그대로 둔다.
	 *
	 * @param array $categories 기존 category 목록.
	 * @return array
	 */
	public static function add_category( $categories ) {
		if ( ! is_array( $categories ) ) {
			error_log( '[green-grass] block categories filter received a non-array' );
			return $categories;
		}
		foreach ( $categories as $category ) {
			if ( isset( $category['slug'] ) && self::CATEGORY === $category['slug'] ) {
				return $categories;
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
			error_log( '[green-grass] register_block_type() is missing; the block is unavailable on this WordPress version' );
			return;
		}

		$dir = GG_DIR . 'blocks/green-grass';
		if ( ! file_exists( $dir . '/block.json' ) ) {
			// 파일이 빠진 채 배포되면 block 만 조용히 사라진다. 로그에 남긴다.
			error_log( '[green-grass] block.json not found at ' . $dir );
			return;
		}

		// 편집 화면의 미리보기는 REST 로 오므로 stylesheet 를 따로 걸어 주어야 격자가 유지된다.
		$registered = register_block_type(
			$dir,
			array(
				'render_callback' => array( __CLASS__, 'render' ),
				'style'           => 'green-grass',
				'editor_style'    => 'green-grass',
			)
		);
		if ( false === $registered ) {
			error_log( '[green-grass] register_block_type failed for ' . self::NAME );
			return;
		}
		// handle 은 block.json 의 이름에서 만들어진다. 짐작해 적으면 이름이 바뀌는 날 조용히 어긋난다.
		if ( ! empty( $registered->editor_script_handles ) ) {
			self::$editor_handle = (string) $registered->editor_script_handles[0];
		}
	}

	/**
	 * 편집 화면에도 잔디의 CSS 를 싣고, 설정값을 함께 보낸다.
	 *
	 * 칸을 비우면 설정값을 쓴다. 편집기가 "설정값을 쓴다" 고만 적으면 그 값이 무엇인지
	 * 보려고 설정 화면을 따로 열어야 하므로, 값 자체를 실어 보내 사이드바에 적는다.
	 */
	public static function enqueue_editor_assets() {
		wp_enqueue_style( 'green-grass' );

		if ( '' === self::$editor_handle ) {
			error_log( '[green-grass] the block editor script has no handle; the saved settings are unavailable' );
			return;
		}

		$options  = GG_Calendar::options();
		$settings = array();
		foreach ( GG_Shortcode::SETTING_OF as $attribute => $option ) {
			$settings[ $attribute ] = isset( $options[ $option ] ) ? (string) $options[ $option ] : '';
		}

		$sources = array();
		foreach ( GG_Source::ALL as $name ) {
			$sources[] = array( 'name' => $name, 'label' => GG_Source::label( $name ) );
		}

		$types = array();
		foreach ( GG_Source::post_types() as $name => $label ) {
			$types[] = array( 'name' => $name, 'label' => $label );
		}

		wp_localize_script(
			self::$editor_handle,
			'GG_BLOCK',
			array(
				'settings'  => $settings,
				'sources'   => $sources,
				'postTypes' => $types,
				'minCell'   => GG_Calendar::MIN_CELL,
				'maxCell'   => GG_Calendar::MAX_CELL,
			)
		);
	}

	/**
	 * 이 렌더링이 편집 화면의 미리보기인가.
	 *
	 * 미리보기는 block-renderer 경로로 오고 그 요청만 context=edit 을 달고 온다.
	 * REST 인지만 보면 headless 로 본문을 받아 가는 쪽까지 같이 걸린다.
	 *
	 * @return bool
	 */
	private static function is_editor_preview() {
		if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
			return false;
		}
		$context = isset( $_GET['context'] ) ? sanitize_text_field( wp_unslash( $_GET['context'] ) ) : '';
		return 'edit' === $context;
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

		// 편집 캔버스는 iframe 이다. 그 안에서 칸을 누르면 캔버스가 아카이브로 떠나고,
		// 편집기는 제 문서를 잃어 다시 읽기 전까지 깨진 채로 남는다. 미리보기에서는
		// 링크를 걸지 않는다. 실제 화면의 링크는 그대로다.
		if ( self::is_editor_preview() ) {
			$atts['link'] = 'none';
		}

		$html = GG_Shortcode::render( $atts );

		$wrapper = function_exists( 'get_block_wrapper_attributes' )
			? get_block_wrapper_attributes()
			: 'class="wp-block-green-grass-green-grass"';
		return '<div ' . $wrapper . '>' . $html . '</div>';
	}
}

// category 는 편집 화면이 열릴 때 필요하므로 block 등록과 무관하게 건다.
add_filter( 'block_categories_all', array( 'GG_Block', 'add_category' ) );
