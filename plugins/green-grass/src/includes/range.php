<?php
/**
 * 어느 날부터 어느 날까지를 그릴지 정하고, 그 날들을 격자 위에 앉힌다.
 *
 * 날짜는 UTC 가 아니라 사이트 표준시로 센다. 글이 사이트 표준시로 저장되므로,
 * 그렇게 해야 "어제 저녁에 쓴 글" 이 어제 칸에 든다.
 *
 * @package GreenGrass
 */

defined( 'ABSPATH' ) || exit;

final class GG_Range {

	/** 한 번에 그릴 수 있는 최대 일수. 이보다 길면 칸이 수천 개가 되어 페이지가 무거워진다. */
	const MAX_DAYS = 3660;

	/**
	 * 사이트 표준시.
	 *
	 * @return DateTimeZone
	 */
	public static function timezone() {
		// wp_timezone() 은 5.3 부터다. 그 아래에서는 UTC 로 물러선다.
		return function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
	}

	/**
	 * 사이트 표준시의 오늘 자정.
	 *
	 * @return DateTimeImmutable
	 */
	public static function today() {
		return new DateTimeImmutable( 'today', self::timezone() );
	}

	/**
	 * YYYY-MM-DD 를 날짜로 읽는다. 달력에 없는 날은 거절한다.
	 *
	 * checkdate 로 한 번 더 보는 이유는 DateTimeImmutable 이 2026-02-31 을
	 * 3월 3일로 넘겨 읽기 때문이다. 사용자가 적은 날과 다른 날이 그려지면 안 된다.
	 *
	 * @param string $text 날짜 문자열.
	 * @return DateTimeImmutable|null
	 */
	public static function parse( $text ) {
		$text = trim( (string) $text );
		if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $text, $m ) ) {
			return null;
		}
		if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return null;
		}
		return new DateTimeImmutable( $text . ' 00:00:00', self::timezone() );
	}

	/**
	 * 설정과 속성에서 실제 구간을 정한다.
	 *
	 * @param array $args 검증을 마친 인자.
	 * @return array|WP_Error from/to 를 담은 배열. 둘 다 그 날 자정이고 양끝을 포함한다.
	 */
	public static function resolve( array $args ) {
		$today = self::today();

		if ( 'dates' === $args['period'] ) {
			$from = self::parse( $args['from'] );
			if ( null === $from ) {
				return new WP_Error( 'gg_bad_from', 'from 은 YYYY-MM-DD 형식의 실재하는 날이어야 한다: ' . $args['from'] );
			}
			// 끝을 비워 두는 것은 "오늘까지" 라는 뜻이다. 구간을 열어 두면 매일 한 칸씩 자란다.
			if ( '' === trim( (string) $args['to'] ) ) {
				$to = $today;
			} else {
				$to = self::parse( $args['to'] );
				if ( null === $to ) {
					return new WP_Error( 'gg_bad_to', 'to 는 YYYY-MM-DD 형식의 실재하는 날이어야 한다: ' . $args['to'] );
				}
			}
		} else {
			$to     = $today;
			$months = max( 1, (int) $args['months'] );
			// 오늘에서 N 달을 물러선 다음 하루를 더한다. 12 달이면 오늘까지 꼭 한 해다.
			$from = $to->modify( '-' . $months . ' months' )->modify( '+1 day' );
		}

		if ( $from > $to ) {
			return new WP_Error( 'gg_backwards', '시작일이 끝일보다 늦다: ' . $from->format( 'Y-m-d' ) . ' > ' . $to->format( 'Y-m-d' ) );
		}

		$days = (int) $from->diff( $to )->days + 1;
		if ( $days > self::MAX_DAYS ) {
			return new WP_Error(
				'gg_too_long',
				sprintf( '구간이 %d일이라 너무 길다. 최대 %d일까지 그린다.', $days, self::MAX_DAYS )
			);
		}

		return array( 'from' => $from, 'to' => $to );
	}

	/**
	 * 한 주의 첫 요일. 0 이 일요일이다.
	 *
	 * @param string $week_start sunday | monday.
	 * @return int
	 */
	public static function first_weekday( $week_start ) {
		return ( 'monday' === $week_start ) ? 1 : 0;
	}

	/**
	 * 구간의 날들을 격자 자리와 함께 늘어놓는다.
	 *
	 * 자리는 (주, 요일) 두 수다. 어느 쪽이 가로가 될지는 여기서 정하지 않는다.
	 * 가로 배치와 세로 배치가 같은 계산을 쓰고 마지막에 축만 바꾸게 하려는 것이다.
	 *
	 * @param DateTimeImmutable $from       시작일.
	 * @param DateTimeImmutable $to         끝일.
	 * @param string            $week_start sunday | monday.
	 * @return array 'days' => 날짜별 자리, 'weeks' => 주의 수.
	 */
	public static function grid( DateTimeImmutable $from, DateTimeImmutable $to, $week_start ) {
		$first = self::first_weekday( $week_start );

		// 첫 주의 시작. 시작일이 주 중간이면 그 앞은 빈자리로 남는다.
		$offset = ( (int) $from->format( 'w' ) - $first + 7 ) % 7;
		$origin = $from->modify( '-' . $offset . ' days' );

		$days   = array();
		$cursor = $from;
		while ( $cursor <= $to ) {
			$since = (int) $origin->diff( $cursor )->days;
			$days[] = array(
				'date' => $cursor->format( 'Y-m-d' ),
				'week' => intdiv( $since, 7 ),
				'day'  => $since % 7,
				'time' => $cursor,
			);
			$cursor = $cursor->modify( '+1 day' );
		}

		$weeks = empty( $days ) ? 0 : ( end( $days )['week'] + 1 );
		reset( $days );

		return array( 'days' => $days, 'weeks' => $weeks );
	}

	/**
	 * 주마다 어느 달에 드는지 보아, 달 이름을 붙일 자리를 만든다.
	 *
	 * 한 주는 두 달에 걸칠 수 있으므로 그 주의 첫 날이 든 달을 그 주의 달로 본다.
	 * 달이 바뀌는 첫 주에서 이름이 시작되고, 다음 달이 시작되기 전까지 이어진다.
	 *
	 * @param array $days GG_Range::grid() 의 'days'.
	 * @return array 각 항목은 'label', 'start'(0부터 세는 주), 'span'.
	 */
	public static function months( array $days ) {
		$month_of = array();
		foreach ( $days as $day ) {
			// 한 주에 여러 날이 있으면 먼저 온 날이 그 주의 달을 정한다.
			if ( ! isset( $month_of[ $day['week'] ] ) ) {
				$month_of[ $day['week'] ] = $day['time']->format( 'Y-m' );
			}
		}
		ksort( $month_of );

		$groups = array();
		$last   = null;
		foreach ( $month_of as $week => $key ) {
			if ( $key !== $last ) {
				$groups[] = array( 'key' => $key, 'start' => (int) $week, 'span' => 1 );
				$last     = $key;
				continue;
			}
			$groups[ count( $groups ) - 1 ]['span']++;
		}

		// 한 주짜리 이름은 옆 이름과 붙어 읽히지 않는다. 그런 자리는 비운다. GitHub 도
		// 구간의 첫 달이 며칠만 걸칠 때 이름을 적지 않는다. 다만 달이 하나뿐이면 남긴다.
		$out = array();
		foreach ( $groups as $group ) {
			if ( $group['span'] < 2 && count( $groups ) > 1 ) {
				continue;
			}
			$stamp   = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $group['key'] . '-01 00:00:00', self::timezone() );
			$out[]   = array(
				'label' => ( false === $stamp ) ? $group['key'] : wp_date( 'M', $stamp->getTimestamp() ),
				'start' => $group['start'],
				'span'  => $group['span'],
			);
		}
		return $out;
	}
}
