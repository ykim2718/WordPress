<?php
/**
 * 글을 직접 뒤져 topic 이 몇 곳에 나오는지 센다.
 *
 * 파이프라인이 보낸 글 수는 파이프라인이 돌던 날의 것이다. 그 뒤에 쓴 글은 세어지지
 * 않고, 지운 글은 계속 세어진다. 여기서는 고른 자리(본문 / 요약문 / 페이지)를 지금
 * 뒤져 다시 센다. 낱말 하나도 걸리지 않는 topic 은 이제 그 자리에 없는 것이므로
 * 그리지 않는다.
 *
 * 모델을 부르지 않는다. 이미 이름과 구절을 가진 topic 을 글에서 찾아보기만 한다.
 *
 * @package KeyWordCloud
 */

defined( 'ABSPATH' ) || exit;

final class KWC_Sources {

	/** 고를 수 있는 자리. */
	const ALL = array( 'post', 'excerpt', 'page' );

	/** 한 topic 을 찾을 때 쓸 낱말 수의 상한. 구절이 많다고 질의가 끝없이 길어지면 안 된다. */
	const MAX_TERMS = 8;

	/** 센 결과를 담아 두는 자리. kwc_ 로 시작해야 캐시 비우기가 같이 지운다. */
	const CACHE_PREFIX = 'kwc_counts_';

	/**
	 * 고른 자리 목록을 읽는다.
	 *
	 * @param mixed $value 쉼표로 이은 이름.
	 * @return array|null 정규화된 이름들. 비었으면 null 이고, 그때는 세지 않는다.
	 */
	public static function parse( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return null;
		}
		$picked = array();
		foreach ( explode( ',', $value ) as $name ) {
			$name = strtolower( trim( $name ) );
			if ( in_array( $name, self::ALL, true ) && ! in_array( $name, $picked, true ) ) {
				$picked[] = $name;
			}
		}
		return empty( $picked ) ? null : $picked;
	}

	/**
	 * 화면에 적을 이름.
	 *
	 * @param string $source 자리 이름.
	 * @return string
	 */
	public static function label( $source ) {
		$labels = array(
			'post'    => __( '글 본문', 'key-word-cloud' ),
			'excerpt' => __( '글 요약문', 'key-word-cloud' ),
			'page'    => __( '페이지', 'key-word-cloud' ),
		);
		return isset( $labels[ $source ] ) ? $labels[ $source ] : $source;
	}

	/**
	 * 이 topic 을 찾을 때 쓸 낱말들.
	 *
	 * 이름과 구절을 함께 쓴다. 이름은 구절 하나를 골라 붙인 것이라 이름만으로 찾으면
	 * 같은 topic 을 다른 말로 쓴 글이 빠진다.
	 *
	 * @param array $topic topic.
	 * @return array
	 */
	private static function terms( array $topic ) {
		$terms = array();
		foreach ( array_merge(
			array( isset( $topic['label'] ) ? $topic['label'] : '' ),
			array_values( (array) ( isset( $topic['labels'] ) ? $topic['labels'] : array() ) ),
			(array) ( isset( $topic['phrases'] ) ? $topic['phrases'] : array() )
		) as $term ) {
			$term = trim( (string) $term );
			// 두 글자 미만은 아무 글에나 걸린다.
			if ( 2 > mb_strlen( $term ) || in_array( $term, $terms, true ) ) {
				continue;
			}
			$terms[] = $term;
			if ( count( $terms ) >= self::MAX_TERMS ) {
				break;
			}
		}
		return $terms;
	}

	/**
	 * topic 마다 몇 곳에 나오는지 한 번에 세고, 센 것을 담아 둔다.
	 *
	 * 74 개를 세는 데 이 사이트에서 4 초가 걸렸다. 구름의 캐시가 지날 때마다 그 4 초를
	 * 누군가가 기다리게 되므로 센 것만 따로 하루 담아 둔다. 담아 둔 것은 topic 이 새로
	 * 올라오거나 캐시를 비우면 함께 지워진다. 색이나 글자 크기를 바꿔도 다시 세지 않는다.
	 *
	 * @param array $topics  올라온 topic 전부.
	 * @param array $sources 고른 자리.
	 * @return array topic 이름 => 글 수.
	 */
	public static function counts( array $topics, array $sources ) {
		$status = KWC_Topics::status();
		$key    = self::CACHE_PREFIX . md5(
			implode( ',', $sources ) . '|' . $status['updated'] . '|' . count( $topics )
		);

		$found = get_transient( $key );
		if ( is_array( $found ) ) {
			return $found;
		}

		$found = array();
		foreach ( $topics as $topic ) {
			$found[ (string) $topic['label'] ] = self::count_posts( $topic, $sources );
		}
		set_transient( $key, $found, DAY_IN_SECONDS );
		return $found;
	}

	/**
	 * 설정된 자리로 미리 세어 둔다. topic 이 새로 저장될 때 부른다.
	 *
	 * 세는 일은 이 사이트에서 23 초가 걸렸다. 그것을 방문자가 기다리게 하지 않으려면 글이
	 * 바뀌는 순간, 곧 topic 을 새로 받은 그 요청에서 세어 두어야 한다. 하루 한 번의
	 * 가져오기와 새로고침 단추가 모두 이 길을 지난다.
	 *
	 * @param array $topics 방금 저장한 topic.
	 */
	public static function warm( array $topics ) {
		$options = KWC_Cloud::options();
		$sources = self::parse( isset( $options['sources'] ) ? $options['sources'] : '' );
		if ( null === $sources || empty( $topics ) ) {
			return;
		}
		$started = microtime( true );
		self::counts( $topics, $sources );
		error_log( sprintf(
			'[key-word-cloud] counted %d topics against %s in %.1fs',
			count( $topics ),
			implode( ',', $sources ),
			microtime( true ) - $started
		) );
	}

	/**
	 * 고른 자리에서 이 topic 이 나오는 글 수.
	 *
	 * @param array $topic   topic.
	 * @param array $sources 고른 자리.
	 * @return int 걸린 글 수. 낱말이 없으면 0.
	 */
	public static function count_posts( array $topic, array $sources ) {
		global $wpdb;

		$terms = self::terms( $topic );
		if ( empty( $terms ) || empty( $sources ) ) {
			return 0;
		}

		// 자리마다 어떤 글종의 어떤 칸을 볼지가 다르다. page 는 본문과 요약문을 함께 본다.
		$columns = array();
		if ( in_array( 'post', $sources, true ) ) {
			$columns['post'] = 'post_content';
		}
		if ( in_array( 'excerpt', $sources, true ) ) {
			$columns['excerpt'] = 'post_excerpt';
		}
		if ( in_array( 'page', $sources, true ) ) {
			$columns['page'] = 'page';
		}

		$clauses = array();
		$values  = array();
		foreach ( $terms as $term ) {
			$like = '%' . $wpdb->esc_like( $term ) . '%';
			foreach ( $columns as $source => $column ) {
				if ( 'page' === $source ) {
					$clauses[] = "( post_type = 'page' AND ( post_content LIKE %s OR post_excerpt LIKE %s ) )";
					$values[]  = $like;
					$values[]  = $like;
					continue;
				}
				$clauses[] = "( post_type = 'post' AND {$column} LIKE %s )";
				$values[]  = $like;
			}
		}

		$sql = "SELECT COUNT(DISTINCT ID) FROM {$wpdb->posts} WHERE post_status = 'publish' AND ( "
			. implode( ' OR ', $clauses ) . ' )';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- 자리표시자만 넣고 값은 prepare 가 넣는다.
		$found = $wpdb->get_var( $wpdb->prepare( $sql, $values ) );
		if ( null === $found ) {
			// 조용히 0 을 돌려주면 topic 이 사라진 이유가 "글이 없어서" 로 보인다.
			error_log( '[key-word-cloud] counting posts failed: ' . $wpdb->last_error );
			return 0;
		}
		return (int) $found;
	}
}
