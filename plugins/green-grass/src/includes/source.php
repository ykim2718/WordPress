<?php
/**
 * 날짜별로 몇 건인지 세어 온다.
 *
 * 어디서 세든 결과는 같은 모양이다: 'YYYY-MM-DD' => 건수. 하루도 없는 날은 아예
 * 들어 있지 않다. 구간의 빈 날까지 채우는 일은 격자를 만드는 쪽이 한다.
 *
 * @package GreenGrass
 */

defined( 'ABSPATH' ) || exit;

final class GG_Source {

	const ALL = array( 'posts', 'comments', 'github' );

	/**
	 * 설정 화면과 편집기에 보일 이름.
	 *
	 * @param string $name 원본 이름.
	 * @return string
	 */
	public static function label( $name ) {
		$labels = array(
			'posts'    => '이 사이트의 글',
			'comments' => '이 사이트의 댓글',
			'github'   => 'GitHub 계정의 contribution',
		);
		return isset( $labels[ $name ] ) ? $labels[ $name ] : $name;
	}

	/**
	 * 셀 수 있는 글 종류. 관리 화면에 나오는 것만 고른다.
	 *
	 * @return array 이름 => 보일 이름.
	 */
	public static function post_types() {
		$out = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( 'attachment' === $type->name ) {
				continue;   // 첨부는 글이 아니다
			}
			$out[ $type->name ] = isset( $type->labels->name ) ? $type->labels->name : $type->name;
		}
		return $out;
	}

	/**
	 * 쉼표로 적은 글 종류를 읽는다.
	 *
	 * @param string $text 설정에 든 문자열.
	 * @return array
	 */
	public static function parse_post_types( $text ) {
		$known = array_keys( self::post_types() );
		$out   = array();
		foreach ( explode( ',', (string) $text ) as $name ) {
			$name = sanitize_key( trim( $name ) );
			if ( '' === $name || ! in_array( $name, $known, true ) || in_array( $name, $out, true ) ) {
				continue;
			}
			$out[] = $name;
		}
		return $out;
	}

	/**
	 * 날짜별 건수.
	 *
	 * @param array             $args 검증을 마친 인자.
	 * @param DateTimeImmutable $from 시작일.
	 * @param DateTimeImmutable $to   끝일.
	 * @return array|WP_Error 'YYYY-MM-DD' => 건수.
	 */
	public static function counts( array $args, DateTimeImmutable $from, DateTimeImmutable $to ) {
		switch ( $args['source'] ) {
			case 'comments':
				return self::comments( $from, $to );
			case 'github':
				return GG_GitHub::contributions( $args['gh_user'], $from, $to, (int) $args['cache_ttl'] );
			case 'posts':
				return self::posts( $args, $from, $to );
		}
		return new WP_Error( 'gg_bad_source', '무엇을 셀지 알 수 없다: ' . $args['source'] );
	}

	/**
	 * 발행한 글을 날짜별로 센다.
	 *
	 * post_date 는 사이트 표준시로 저장되므로 그대로 잘라 쓴다. post_date_gmt 를 쓰면
	 * 한국에서 저녁에 쓴 글이 전날 칸에 든다.
	 *
	 * @param array             $args 인자.
	 * @param DateTimeImmutable $from 시작일.
	 * @param DateTimeImmutable $to   끝일.
	 * @return array|WP_Error
	 */
	private static function posts( array $args, DateTimeImmutable $from, DateTimeImmutable $to ) {
		global $wpdb;

		$types = self::parse_post_types( $args['post_types'] );
		if ( empty( $types ) ) {
			return new WP_Error( 'gg_no_post_types', '셀 글 종류가 하나도 없다.' );
		}

		$slots = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
		$sql   = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $slots 는 위에서 만든 %s 목록이다.
			"SELECT DATE(post_date) AS d, COUNT(*) AS c
			   FROM {$wpdb->posts}
			  WHERE post_status = 'publish'
			    AND post_type IN ( {$slots} )
			    AND post_date >= %s
			    AND post_date < %s
			  GROUP BY d",
			array_merge( $types, array( self::start_of( $from ), self::day_after( $to ) ) )
		);

		return self::tally( $wpdb->get_results( $sql, ARRAY_A ) );  // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * 승인된 댓글을 날짜별로 센다. 핑백과 트랙백은 사람이 쓴 것이 아니라 빼놓는다.
	 *
	 * @param DateTimeImmutable $from 시작일.
	 * @param DateTimeImmutable $to   끝일.
	 * @return array|WP_Error
	 */
	private static function comments( DateTimeImmutable $from, DateTimeImmutable $to ) {
		global $wpdb;

		$sql = $wpdb->prepare(
			"SELECT DATE(comment_date) AS d, COUNT(*) AS c
			   FROM {$wpdb->comments}
			  WHERE comment_approved = '1'
			    AND comment_type IN ( '', 'comment' )
			    AND comment_date >= %s
			    AND comment_date < %s
			  GROUP BY d",
			self::start_of( $from ),
			self::day_after( $to )
		);

		return self::tally( $wpdb->get_results( $sql, ARRAY_A ) );  // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * 질의 결과를 날짜 => 건수 로 바꾼다.
	 *
	 * @param mixed $rows get_results 의 결과.
	 * @return array|WP_Error
	 */
	private static function tally( $rows ) {
		global $wpdb;

		if ( ! is_array( $rows ) ) {
			$error = $wpdb->last_error ? $wpdb->last_error : 'no rows returned';
			error_log( '[green-grass] the count query failed: ' . $error );
			return new WP_Error( 'gg_query_failed', '세는 중에 데이터베이스가 답하지 않았다.' );
		}

		$out = array();
		foreach ( $rows as $row ) {
			$out[ (string) $row['d'] ] = (int) $row['c'];
		}
		return $out;
	}

	/**
	 * 그 날 자정. 질의의 아래쪽 경계다.
	 *
	 * @param DateTimeImmutable $day 날짜.
	 * @return string
	 */
	private static function start_of( DateTimeImmutable $day ) {
		return $day->format( 'Y-m-d 00:00:00' );
	}

	/**
	 * 다음 날 자정. 끝일을 포함하려면 위쪽 경계가 여기여야 한다.
	 *
	 * BETWEEN 에 23:59:59 를 쓰면 그 1초 사이에 쓴 글이 빠진다.
	 *
	 * @param DateTimeImmutable $day 날짜.
	 * @return string
	 */
	private static function day_after( DateTimeImmutable $day ) {
		return $day->modify( '+1 day' )->format( 'Y-m-d 00:00:00' );
	}

	/**
	 * 칸을 눌렀을 때 갈 곳. 갈 곳이 없으면 빈 문자열.
	 *
	 * @param array  $args 인자.
	 * @param string $date YYYY-MM-DD.
	 * @param int    $count 그 날의 건수.
	 * @return string
	 */
	public static function link( array $args, $date, $count ) {
		if ( 'archive' !== $args['link_mode'] || $count < 1 ) {
			return '';   // 아무것도 없는 날로 보내면 빈 화면이 열린다
		}

		list( $y, $m, $d ) = array_map( 'intval', explode( '-', $date ) );

		if ( 'github' === $args['source'] ) {
			return 'https://github.com/' . rawurlencode( $args['gh_user'] )
				. '?tab=overview&from=' . rawurlencode( $date ) . '&to=' . rawurlencode( $date );
		}
		if ( 'posts' === $args['source'] ) {
			// 날짜 아카이브는 글만 안다. 다른 종류를 세고 있으면 그 종류로 좁혀 준다.
			$types = self::parse_post_types( $args['post_types'] );
			$link  = get_day_link( $y, $m, $d );
			if ( array( 'post' ) !== $types ) {
				$link = add_query_arg( 'post_type', implode( ',', $types ), $link );
			}
			return $link;
		}
		return '';   // 댓글에는 날짜별로 열어 볼 자리가 없다
	}
}
