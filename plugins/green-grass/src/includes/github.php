<?php
/**
 * GitHub 계정의 contribution 을 날짜별로 받아 온다.
 *
 * GitHub 은 이 수를 REST 로 주지 않는다. GraphQL 에는 있지만 토큰을 요구하므로,
 * 프로필 화면이 달력을 그릴 때 쓰는 공개 주소를 그대로 읽는다.
 *
 *   https://github.com/users/<login>/contributions?from=YYYY-MM-DD&to=YYYY-MM-DD
 *
 * 토큰이 필요 없는 대신 GitHub 의 화면 구조에 기댄다. 그쪽이 마크업을 바꾸면 이
 * 원본은 멈춘다. 그래서 세 가지 방법으로 수를 찾고, 하나도 안 되면 조용히 0 을
 * 그리는 대신 화면에 오류를 낸다. 잔디가 갑자기 비는 것보다 낫다.
 *
 * 한 번에 한 해까지만 답하므로 구간을 해마다 잘라 여러 번 부른다.
 *
 * @package GreenGrass
 */

defined( 'ABSPATH' ) || exit;

final class GG_GitHub {

	/** 한 번에 부를 수 있는 횟수. 구간이 길어도 이보다 많이 두드리지 않는다. */
	const MAX_CHUNKS = 11;

	/** 받아오지 못했을 때 다시 두드리기까지 기다리는 시간. */
	const FAILURE_TTL = 900;

	/**
	 * 계정 이름이 GitHub 이 허락하는 모양인지.
	 *
	 * 영숫자와 붙임표만, 붙임표로 시작하거나 끝나지 않고, 39자 이하.
	 *
	 * @param string $login 계정 이름.
	 * @return bool
	 */
	public static function is_login( $login ) {
		return 1 === preg_match( '/^[A-Za-z0-9](?:[A-Za-z0-9]|-(?=[A-Za-z0-9])){0,38}$/', (string) $login );
	}

	/**
	 * 날짜별 contribution 수.
	 *
	 * @param string            $login 계정 이름.
	 * @param DateTimeImmutable $from  시작일.
	 * @param DateTimeImmutable $to    끝일.
	 * @param int               $ttl   캐시 시간(초).
	 * @return array|WP_Error 'YYYY-MM-DD' => 건수.
	 */
	public static function contributions( $login, DateTimeImmutable $from, DateTimeImmutable $to, $ttl ) {
		$login = trim( (string) $login );
		if ( '' === $login ) {
			return new WP_Error( 'gg_no_login', 'GitHub 계정 이름을 적어야 한다.' );
		}
		if ( ! self::is_login( $login ) ) {
			return new WP_Error( 'gg_bad_login', 'GitHub 계정 이름이 아니다: ' . $login );
		}

		$key = 'gg_gh_' . md5( $login . '|' . $from->format( 'Y-m-d' ) . '|' . $to->format( 'Y-m-d' ) );
		$hit = get_transient( $key );
		if ( is_array( $hit ) ) {
			// 실패도 캐시한다. 계정 이름을 잘못 적어 둔 페이지가 매 조회마다 GitHub 을 두드리면 안 된다.
			return isset( $hit['error'] )
				? new WP_Error( 'gg_cached_failure', (string) $hit['error'] )
				: $hit['days'];
		}

		$counts = array();
		foreach ( self::chunks( $from, $to ) as $chunk ) {
			$part = self::fetch_year( $login, $chunk['from'], $chunk['to'] );
			if ( is_wp_error( $part ) ) {
				set_transient( $key, array( 'error' => $part->get_error_message() ), self::FAILURE_TTL );
				return $part;
			}
			foreach ( $part as $date => $count ) {
				// 해를 자른 자리에서 같은 날이 두 번 올 수 있다. 큰 쪽이 온전한 답이다.
				if ( ! isset( $counts[ $date ] ) || $count > $counts[ $date ] ) {
					$counts[ $date ] = $count;
				}
			}
		}

		$ttl = max( 60, (int) $ttl );
		set_transient( $key, array( 'days' => $counts ), $ttl );
		return $counts;
	}

	/**
	 * 구간을 해마다 자른다. GitHub 이 한 번에 한 해까지만 답하기 때문이다.
	 *
	 * @param DateTimeImmutable $from 시작일.
	 * @param DateTimeImmutable $to   끝일.
	 * @return array from/to 쌍의 목록.
	 */
	private static function chunks( DateTimeImmutable $from, DateTimeImmutable $to ) {
		$out    = array();
		$cursor = $from;
		while ( $cursor <= $to && count( $out ) < self::MAX_CHUNKS ) {
			$end = $cursor->modify( '+1 year' )->modify( '-1 day' );
			if ( $end > $to ) {
				$end = $to;
			}
			$out[]  = array( 'from' => $cursor, 'to' => $end );
			$cursor = $end->modify( '+1 day' );
		}
		return $out;
	}

	/**
	 * 한 해치를 받아 읽는다.
	 *
	 * @param string            $login 계정 이름.
	 * @param DateTimeImmutable $from  시작일.
	 * @param DateTimeImmutable $to    끝일.
	 * @return array|WP_Error
	 */
	private static function fetch_year( $login, DateTimeImmutable $from, DateTimeImmutable $to ) {
		$url = add_query_arg(
			array( 'from' => $from->format( 'Y-m-d' ), 'to' => $to->format( 'Y-m-d' ) ),
			'https://github.com/users/' . rawurlencode( $login ) . '/contributions'
		);

		$res = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => array(
					'User-Agent'       => 'wp-green-grass/' . GG_VERSION . ' (+https://github.com/ykim2718/WordPress)',
					'Accept'           => 'text/html',
					'X-Requested-With' => 'XMLHttpRequest',
				),
			)
		);

		if ( is_wp_error( $res ) ) {
			error_log( '[green-grass] the contributions request failed: ' . $res->get_error_message() );
			return new WP_Error( 'gg_gh_unreachable', 'GitHub 에 닿지 못했다: ' . $res->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 404 === $code ) {
			return new WP_Error( 'gg_gh_no_user', 'GitHub 에 그런 계정이 없다: ' . $login );
		}
		if ( 200 !== $code ) {
			error_log( '[green-grass] contributions HTTP ' . $code . ' url=' . $url );
			return new WP_Error( 'gg_gh_http', 'GitHub 이 ' . $code . ' 으로 답했다.' );
		}

		$html = (string) wp_remote_retrieve_body( $res );
		$days = self::parse( $html );
		if ( is_wp_error( $days ) ) {
			return $days;
		}

		// 자른 구간 밖의 날은 버린다. GitHub 은 요청한 날짜를 그 해의 달력으로 넓혀 답한다.
		$start = $from->format( 'Y-m-d' );
		$end   = $to->format( 'Y-m-d' );
		$out   = array();
		foreach ( $days as $date => $count ) {
			if ( $date >= $start && $date <= $end ) {
				$out[ $date ] = $count;
			}
		}
		return $out;
	}

	/**
	 * 달력 조각에서 날짜와 건수를 뽑는다.
	 *
	 * 건수가 어디에 적히는지는 몇 해 사이에 두 번 바뀌었다. 셋 다 본다.
	 *   1. 칸 자체의 data-count
	 *   2. 칸의 id 를 가리키는 <tool-tip> 의 글 ("3 contributions on ...")
	 *   3. 둘 다 없으면 data-level. 0~4 라 실제 수는 아니지만 짙기는 맞는다.
	 *
	 * @param string $html 받아 온 조각.
	 * @return array|WP_Error
	 */
	private static function parse( $html ) {
		// preg_match_all 은 찾은 수를 돌려준다. 1 과 견주면 칸이 하나뿐일 때만 통과한다.
		if ( ! preg_match_all( '/<td\b([^>]*\bdata-date="\d{4}-\d{2}-\d{2}"[^>]*)>/i', $html, $cells, PREG_PATTERN_ORDER ) ) {
			error_log( '[green-grass] no day cells in the contributions markup; GitHub may have changed it' );
			return new WP_Error( 'gg_gh_shape', 'GitHub 이 보낸 달력을 읽지 못했다. 화면 구조가 바뀐 것 같다.' );
		}

		$tips     = self::tooltips( $html );
		$out      = array();
		$guessed  = 0;
		foreach ( $cells[1] as $attrs ) {
			if ( 1 !== preg_match( '/\bdata-date="(\d{4}-\d{2}-\d{2})"/', $attrs, $m ) ) {
				continue;
			}
			$date  = $m[1];
			$count = null;

			if ( 1 === preg_match( '/\bdata-count="(\d+)"/', $attrs, $m ) ) {
				$count = (int) $m[1];
			} elseif ( 1 === preg_match( '/\bid="([^"]+)"/', $attrs, $m ) && isset( $tips[ $m[1] ] ) ) {
				$count = $tips[ $m[1] ];
			} elseif ( 1 === preg_match( '/\bdata-level="(\d+)"/', $attrs, $m ) ) {
				$count = (int) $m[1];
				$guessed++;
			}

			if ( null !== $count ) {
				$out[ $date ] = $count;
			}
		}

		if ( empty( $out ) ) {
			error_log( '[green-grass] day cells carried no counts at all' );
			return new WP_Error( 'gg_gh_empty', 'GitHub 이 보낸 달력에 건수가 없다.' );
		}
		if ( $guessed > 0 ) {
			// 짙기는 맞지만 칸에 뜨는 수는 0~4 가 된다. 왜 그런지 알 수 있게 남긴다.
			error_log( '[green-grass] ' . $guessed . ' day(s) had no count, so the level was used instead' );
		}
		return $out;
	}

	/**
	 * <tool-tip> 을 칸의 id 별로 모은다.
	 *
	 * @param string $html 받아 온 조각.
	 * @return array id => 건수.
	 */
	private static function tooltips( $html ) {
		if ( ! preg_match_all( '/<tool-tip\b[^>]*\bfor="([^"]+)"[^>]*>(.*?)<\/tool-tip>/is', $html, $found, PREG_SET_ORDER ) ) {
			return array();
		}

		$out = array();
		foreach ( $found as $tip ) {
			$text = html_entity_decode( wp_strip_all_tags( $tip[2] ), ENT_QUOTES, 'UTF-8' );
			// "No contributions on ..." 은 0 이고, 나머지는 앞머리의 수가 건수다.
			if ( 1 === preg_match( '/^\s*(\d[\d,]*)\s/', $text, $m ) ) {
				$out[ $tip[1] ] = (int) str_replace( ',', '', $m[1] );
			} elseif ( 1 === preg_match( '/^\s*no\s+contribution/i', $text ) ) {
				$out[ $tip[1] ] = 0;
			}
		}
		return $out;
	}
}
