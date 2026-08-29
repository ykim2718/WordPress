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

	/** 찾은 글을 담아 두는 자리. kwc_ 로 시작해야 캐시 비우기가 같이 지운다. */
	const CACHE_PREFIX = 'kwc_found_';

	/** 감출 분류의 이름. 이 분류에 든 글은 세지 않고, 낱말을 눌러 나오는 목록에도 넣지 않는다. */
	const RESTRICTED = 'restricted';

	/**
	 * 감출 분류와 그 아래 분류들.
	 *
	 * 이름으로 찾는다. 슬러그는 사이트마다 다르게 붙지만 이름은 화면에 보이는 그대로다.
	 * 이름으로 못 찾으면 슬러그로 한 번 더 찾는다. 대소문자는 가리지 않는다.
	 *
	 * @return array term_id 와 term_taxonomy_id 의 쌍들. 그런 분류가 없으면 빈 배열.
	 */
	public static function restricted() {
		static $found = null;
		if ( null !== $found ) {
			return $found;
		}
		$found = array();
		$term  = get_term_by( 'name', self::RESTRICTED, 'category' );
		if ( ! $term || is_wp_error( $term ) ) {
			$term = get_term_by( 'slug', self::RESTRICTED, 'category' );
		}
		if ( ! $term || is_wp_error( $term ) ) {
			return $found;
		}
		// 아래 분류에 든 글도 함께 감춘다. 하나라도 흘리면 감춘 뜻이 없다.
		$ids = array_merge(
			array( $term->term_id ),
			(array) get_term_children( $term->term_id, 'category' )
		);
		foreach ( $ids as $id ) {
			$each = get_term( (int) $id, 'category' );
			if ( $each && ! is_wp_error( $each ) ) {
				$found[] = array(
					'term_id' => (int) $each->term_id,
					'ttid'    => (int) $each->term_taxonomy_id,
				);
			}
		}
		return $found;
	}

	/**
	 * 감춘 분류의 글을 세지 않게 하는 조건.
	 *
	 * @return string 질의 뒤에 붙일 SQL. 감출 분류가 없으면 빈 문자열.
	 */
	private static function hidden_clause() {
		global $wpdb;

		$ttids = wp_list_pluck( self::restricted(), 'ttid' );
		if ( empty( $ttids ) ) {
			return '';
		}
		return " AND ID NOT IN ( SELECT object_id FROM {$wpdb->term_relationships}"
			. ' WHERE term_taxonomy_id IN ( ' . implode( ',', array_map( 'intval', $ttids ) ) . ' ) )';
	}

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
	 * topic 마다 어느 글에 나오는지 한 번에 찾고, 찾은 것을 담아 둔다.
	 *
	 * 글 수만 세지 않고 글 번호를 담는다. 낱말을 누르면 열리는 목록이 바로 이 글들이어야
	 * 하기 때문이다. 수만 세어 두면 목록은 이름만으로 다시 찾게 되고, 구절로 걸린 글이
	 * 목록에서 빠져 tooltip 의 수와 목록의 길이가 어긋난다.
	 *
	 * 74 개를 찾는 데 이 사이트에서 4 초가 걸렸다. 구름의 캐시가 지날 때마다 그 4 초를
	 * 누군가가 기다리게 되므로 찾은 것만 따로 하루 담아 둔다. 담아 둔 것은 topic 이 새로
	 * 올라오거나 캐시를 비우면 함께 지워진다. 색이나 글자 크기를 바꿔도 다시 찾지 않는다.
	 *
	 * @param array $topics  올라온 topic 전부.
	 * @param array $sources 고른 자리.
	 * @return array topic 이름 => 글 번호 배열.
	 */
	public static function matches( array $topics, array $sources ) {
		$status = KWC_Topics::status();
		$key    = self::CACHE_PREFIX . md5(
			// 찾는 규칙이 판마다 달라진다. 판 번호를 넣어야 올린 뒤에 옛것을 쓰지 않는다.
			implode( ',', $sources ) . '|' . $status['updated'] . '|' . count( $topics )
			. '|' . KWC_VERSION
		);

		$found = get_transient( $key );
		if ( is_array( $found ) ) {
			return $found;
		}

		$found = array();
		foreach ( $topics as $topic ) {
			$found[ (string) $topic['label'] ] = self::find_posts( $topic, $sources );
		}
		set_transient( $key, $found, DAY_IN_SECONDS );
		return $found;
	}

	/**
	 * topic 마다 몇 곳에 나오는지.
	 *
	 * @param array $topics  올라온 topic 전부.
	 * @param array $sources 고른 자리.
	 * @return array topic 이름 => 글 수.
	 */
	public static function counts( array $topics, array $sources ) {
		return array_map( 'count', self::matches( $topics, $sources ) );
	}

	/**
	 * 이름이 주어진 topic 이 걸린 글 번호.
	 *
	 * 담아 둔 것에서 꺼내고, 없으면 그 topic 만 다시 찾는다. 캐시가 지났다고 목록이 다른
	 * 글을 보이면 안 되기 때문이다.
	 *
	 * @param string $label   topic 의 본디 이름.
	 * @param array  $sources 고른 자리.
	 * @return array|null 글 번호 배열. 그런 이름의 topic 이 없으면 null.
	 */
	public static function post_ids( $label, array $sources ) {
		$topics = KWC_Topics::stored();
		if ( empty( $topics ) ) {
			return null;
		}
		$found = self::matches( $topics, $sources );
		if ( isset( $found[ $label ] ) ) {
			return $found[ $label ];
		}
		foreach ( $topics as $topic ) {
			if ( (string) $topic['label'] === (string) $label ) {
				return self::find_posts( $topic, $sources );
			}
		}
		return null;
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
		return count( self::find_posts( $topic, $sources ) );
	}

	/**
	 * 고른 자리에서 이 topic 이 나오는 글.
	 *
	 * @param array $topic   topic.
	 * @param array $sources 고른 자리.
	 * @return array 글 번호 배열. 낱말이 없으면 빈 배열.
	 */
	public static function find_posts( array $topic, array $sources ) {
		global $wpdb;

		$terms = self::terms( $topic );
		if ( empty( $terms ) || empty( $sources ) ) {
			return array();
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

		$sql = "SELECT DISTINCT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND ( "
			. implode( ' OR ', $clauses ) . ' )' . self::hidden_clause();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- 자리표시자만 넣고 값은 prepare 가 넣는다.
		$found = $wpdb->get_col( $wpdb->prepare( $sql, $values ) );
		if ( ! is_array( $found ) || '' !== $wpdb->last_error ) {
			// 조용히 빈 것을 돌려주면 topic 이 사라진 이유가 "글이 없어서" 로 보인다.
			error_log( '[key-word-cloud] counting posts failed: ' . $wpdb->last_error );
			return array();
		}
		return array_map( 'intval', $found );
	}
}
