<?php
/**
 * 낱말을 눌렀을 때 열리는 목록.
 *
 * 구름의 글자 크기는 topic 의 이름과 구절이 걸린 글 수다. 그런데 누르면 열리는 것은
 * 이름으로 하는 보통 검색이라, 구절로 걸린 글이 목록에 없었다. tooltip 은 12 개라 하고
 * 목록은 3 개인 일이 그래서 생긴다.
 *
 * 그래서 목록을 센 것에서 만든다. 주소에 topic 의 본디 이름을 얹어 보내고, 검색 질의를
 * 그 topic 이 걸린 글로 바꾼다. 화면의 제목은 검색어 그대로 남는다.
 *
 * @package KeyWordCloud
 */

defined( 'ABSPATH' ) || exit;

final class KWC_Search {

	/** 주소에 topic 의 본디 이름을 싣는 자리. 보이는 이름은 번역될 수 있어 쓸 수 없다. */
	const VAR = 'kwc';

	/**
	 * 낱말이 가리킬 주소.
	 *
	 * @param string $text   구름에 그려진 이름. 검색어이자 화면의 제목이 된다.
	 * @param string $label  topic 의 본디 이름. 비면 보통 검색이 된다.
	 * @param array  $hidden 뺄 분류의 번호들, 앞에 빼기표가 붙은 채로.
	 * @return string
	 */
	public static function url( $text, $label, array $hidden ) {
		$args = array( 's' => $text );
		if ( '' !== (string) $label ) {
			$args[ self::VAR ] = $label;
		}
		if ( ! empty( $hidden ) ) {
			$args['cat'] = implode( ',', $hidden );
		}
		return add_query_arg( $args, home_url( '/' ) );
	}

	/**
	 * 검색 질의를 그 topic 이 걸린 글로 바꾼다.
	 *
	 * @param WP_Query $query 도는 질의.
	 */
	public static function narrow( $query ) {
		if ( is_admin() || ! $query->is_main_query() || ! $query->is_search() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 읽기만 하는 목록이다.
		$label = isset( $_GET[ self::VAR ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::VAR ] ) ) : '';
		if ( '' === $label ) {
			return;
		}
		$options = KWC_Cloud::options();
		$sources = KWC_Sources::parse( isset( $options['sources'] ) ? $options['sources'] : '' );
		if ( null === $sources ) {
			// 뒤진 자리가 없으면 구름의 수도 파이프라인이 보낸 것이다. 목록을 우리가 정할 수 없다.
			return;
		}
		$ids = KWC_Sources::post_ids( $label, $sources );
		if ( null === $ids ) {
			// 모르는 이름이면 손대지 않는다. 주소는 사람이 고칠 수 있는 것이다.
			return;
		}
		// 빈 배열을 주면 워드프레스는 조건이 없는 줄 알고 글 전부를 돌려준다.
		$query->set( 'post__in', empty( $ids ) ? array( 0 ) : $ids );
		$query->set( 'kwc_narrowed', true );
	}

	/**
	 * 이름으로 하는 검색 조건을 뺀다. 글은 이미 골라 두었다.
	 *
	 * @param string   $search 검색 조건 SQL.
	 * @param WP_Query $query  도는 질의.
	 * @return string
	 */
	public static function drop_search_clause( $search, $query ) {
		return $query->get( 'kwc_narrowed' ) ? '' : $search;
	}
}

add_action( 'pre_get_posts', array( 'KWC_Search', 'narrow' ) );
add_filter( 'posts_search', array( 'KWC_Search', 'drop_search_clause' ), 10, 2 );
