<?php
/**
 * 잔디를 그린다.
 *
 * 가로와 세로는 같은 격자를 축만 바꿔 놓은 것이다. 바깥틀은 두 줄 두 칸이고,
 * 왼쪽 위는 늘 비어 있다. 가로면 위가 달 이름이고 왼쪽이 요일, 세로면 그 반대다.
 *
 *   ┌──────┬──────────┐
 *   │ 빈칸 │  띠 A     │
 *   ├──────┼──────────┤
 *   │ 띠 B │  칸들     │
 *   └──────┴──────────┘
 *
 * @package GreenGrass
 */

defined( 'ABSPATH' ) || exit;

final class GG_Calendar {

	/** 칸 한 변의 px 이 이 사이를 벗어나면 격자가 아니라 다른 것이 된다. */
	const MIN_CELL = 6;
	const MAX_CELL = 40;

	/**
	 * CSS 등록. 실제로 싣는 것은 그릴 때다.
	 */
	public static function register_assets() {
		wp_register_style( 'green-grass', GG_URL . 'assets/green-grass.css', array(), GG_VERSION );
	}

	/**
	 * 저장된 설정에 기본값을 덮어 채운 것.
	 *
	 * @return array
	 */
	public static function options() {
		$saved = get_option( GG_OPTION, array() );
		if ( ! is_array( $saved ) ) {
			error_log( '[green-grass] ' . GG_OPTION . ' is not an array; using the defaults' );
			$saved = array();
		}
		return array_merge( GG_Defaults::options(), $saved );
	}

	/**
	 * 캐시를 비운다.
	 */
	public static function flush_cache() {
		if ( function_exists( 'gg_flush_cache' ) ) {
			gg_flush_cache();
		}
	}

	/**
	 * 건수를 0~4 의 짙기로 바꿀 경계를 정한다.
	 *
	 * linear 는 가장 많은 날을 4 로 두고 넷으로 고르게 나눈다. 하루만 유난히 많으면
	 * 나머지가 전부 1 층으로 눌린다.
	 *
	 * quantile 은 나온 건수의 종류를 늘어놓고 사분위로 나눈다. 같은 수가 몇 번
	 * 나왔는지는 보지 않으므로 하루짜리 극단값이 층을 무너뜨리지 않는다.
	 *
	 * @param array  $counts 날짜 => 건수.
	 * @param string $scale  quantile | linear.
	 * @return array 1, 2, 3 층의 위쪽 경계. 이 값까지가 그 층이다.
	 */
	public static function thresholds( array $counts, $scale ) {
		$values = array();
		foreach ( $counts as $count ) {
			if ( $count > 0 ) {
				$values[] = (int) $count;
			}
		}
		if ( empty( $values ) ) {
			return array( 1, 2, 3 );   // 아무것도 없으면 경계도 뜻이 없다
		}

		if ( 'linear' === $scale ) {
			$max = max( $values );
			return array(
				max( 1, (int) ceil( $max * 0.25 ) ),
				max( 2, (int) ceil( $max * 0.50 ) ),
				max( 3, (int) ceil( $max * 0.75 ) ),
			);
		}

		$values = array_values( array_unique( $values ) );
		sort( $values );
		$last = count( $values ) - 1;

		$out = array();
		foreach ( array( 0.25, 0.50, 0.75 ) as $at ) {
			$out[] = (int) $values[ (int) round( $last * $at ) ];
		}
		// 경계가 겹치면 층이 사라진다. 종류가 서넛뿐일 때 그렇게 된다.
		sort( $out );
		return $out;
	}

	/**
	 * 건수 하나를 짙기로.
	 *
	 * @param int   $count      건수.
	 * @param array $thresholds thresholds() 의 결과.
	 * @return int 0~4.
	 */
	public static function level( $count, array $thresholds ) {
		if ( $count < 1 ) {
			return 0;
		}
		if ( $count <= $thresholds[0] ) {
			return 1;
		}
		if ( $count <= $thresholds[1] ) {
			return 2;
		}
		if ( $count <= $thresholds[2] ) {
			return 3;
		}
		return 4;
	}

	/**
	 * 요일 줄에 적을 이름. GitHub 처럼 월·수·금만 적는다. 일곱을 다 적으면 칸보다
	 * 글자가 커져 줄이 어긋난다.
	 *
	 * @param string $week_start sunday | monday.
	 * @return array 줄 번호(0~6) => 이름. 적지 않는 줄은 들어 있지 않다.
	 */
	public static function weekday_labels( $week_start ) {
		$first = GG_Range::first_weekday( $week_start );

		// 요일 이름은 로케일이 정한다. 2024-01-07 은 일요일이라 거기서부터 세면 된다.
		$sunday = new DateTimeImmutable( '2024-01-07 12:00:00', GG_Range::timezone() );

		$out = array();
		for ( $row = 0; $row < 7; $row++ ) {
			$weekday = ( $first + $row ) % 7;
			if ( ! in_array( $weekday, array( 1, 3, 5 ), true ) ) {
				continue;   // 월·수·금만
			}
			$day           = $sunday->modify( '+' . $weekday . ' days' );
			$out[ $row ]   = wp_date( 'D', $day->getTimestamp() );
		}
		return $out;
	}

	/**
	 * 칸 하나에 붙일 설명.
	 *
	 * @param array  $args  인자.
	 * @param string $date  YYYY-MM-DD.
	 * @param int    $count 건수.
	 * @return string
	 */
	private static function caption( array $args, $date, $count ) {
		$stamp = GG_Range::parse( $date );
		$when  = ( null === $stamp ) ? $date : wp_date( get_option( 'date_format' ), $stamp->getTimestamp() );

		if ( $count < 1 ) {
			/* translators: %s: 날짜 */
			return sprintf( esc_html__( 'No activity on %s', 'green-grass' ), $when );
		}

		if ( 'comments' === $args['source'] ) {
			/* translators: 1: 건수, 2: 날짜 */
			$format = _n( '%1$s comment on %2$s', '%1$s comments on %2$s', $count, 'green-grass' );
		} elseif ( 'github' === $args['source'] ) {
			/* translators: 1: 건수, 2: 날짜 */
			$format = _n( '%1$s contribution on %2$s', '%1$s contributions on %2$s', $count, 'green-grass' );
		} else {
			/* translators: 1: 건수, 2: 날짜 */
			$format = _n( '%1$s post on %2$s', '%1$s posts on %2$s', $count, 'green-grass' );
		}
		return sprintf( $format, number_format_i18n( $count ), $when );
	}

	/**
	 * 격자 위의 한 줄 요약.
	 *
	 * @param array             $args  인자.
	 * @param int               $total 건수의 합.
	 * @param DateTimeImmutable $from  시작일.
	 * @param DateTimeImmutable $to    끝일.
	 * @return string
	 */
	private static function summary( array $args, $total, DateTimeImmutable $from, DateTimeImmutable $to ) {
		if ( 'comments' === $args['source'] ) {
			/* translators: %s: 건수 */
			$what = sprintf( _n( '%s comment', '%s comments', $total, 'green-grass' ), number_format_i18n( $total ) );
		} elseif ( 'github' === $args['source'] ) {
			/* translators: %s: 건수 */
			$what = sprintf( _n( '%s contribution', '%s contributions', $total, 'green-grass' ), number_format_i18n( $total ) );
		} else {
			/* translators: %s: 건수 */
			$what = sprintf( _n( '%s post', '%s posts', $total, 'green-grass' ), number_format_i18n( $total ) );
		}

		$format = get_option( 'date_format' );
		/* translators: 1: 건수를 적은 구절, 2: 시작일, 3: 끝일 */
		return sprintf(
			esc_html__( '%1$s between %2$s and %3$s', 'green-grass' ),
			$what,
			wp_date( $format, $from->getTimestamp() ),
			wp_date( $format, $to->getTimestamp() )
		);
	}

	/**
	 * 그린다.
	 *
	 * @param array $args 검증을 마친 인자.
	 * @return string HTML.
	 */
	public static function render( array $args ) {
		$span = GG_Range::resolve( $args );
		if ( is_wp_error( $span ) ) {
			return self::error( $span->get_error_message() );
		}

		$counts = GG_Source::counts( $args, $span['from'], $span['to'] );
		if ( is_wp_error( $counts ) ) {
			return self::error( $counts->get_error_message() );
		}

		wp_enqueue_style( 'green-grass' );

		$grid       = GG_Range::grid( $span['from'], $span['to'], $args['week_start'] );
		$vertical   = ( 'vertical' === $args['orientation'] );
		$colors     = GG_Palette::levels( $args['palette'], $args['color'], $args['empty_color'] );
		$thresholds = self::thresholds( $counts, $args['scale'] );

		$style = sprintf(
			'--gg-cell:%dpx;--gg-gap:%dpx;--gg-radius:%dpx;--gg-weeks:%d;',
			(int) $args['cell'],
			(int) $args['gap'],
			(int) $args['radius'],
			(int) $grid['weeks']
		);
		foreach ( $colors as $level => $color ) {
			$style .= sprintf( '--gg-l%d:%s;', (int) $level, $color );
		}

		$total = 0;
		foreach ( $grid['days'] as $day ) {
			$total += isset( $counts[ $day['date'] ] ) ? (int) $counts[ $day['date'] ] : 0;
		}

		$out  = '<div class="gg ' . ( $vertical ? 'gg-vertical' : 'gg-horizontal' ) . '" style="' . esc_attr( $style ) . '">';
		$out .= '<div class="gg-inner"><div class="gg-scroll"><div class="gg-body">';
		$out .= '<div class="gg-corner" aria-hidden="true"></div>';

		$months   = $args['show_months'] ? self::months_strip( $grid, $vertical ) : '<div class="gg-strip-off" aria-hidden="true"></div>';
		$weekdays = $args['show_days'] ? self::weekdays_strip( $args, $vertical ) : '<div class="gg-strip-off" aria-hidden="true"></div>';

		// 두 띠의 순서만 바꾸면 축이 바뀐다. 격자를 두 번 만들 일이 없다.
		$out .= $vertical ? $weekdays . $months : $months . $weekdays;
		$out .= self::cells( $args, $grid, $counts, $thresholds, $vertical );
		$out .= '</div></div>';

		if ( $args['show_legend'] ) {
			$out .= self::legend();
		}
		if ( $args['show_total'] ) {
			$out .= '<p class="gg-total">' . esc_html( self::summary( $args, $total, $span['from'], $span['to'] ) ) . '</p>';
		}

		$out .= '</div></div>';
		return $out;
	}

	/**
	 * 칸들.
	 *
	 * @param array $args       인자.
	 * @param array $grid       GG_Range::grid() 의 결과.
	 * @param array $counts     날짜 => 건수.
	 * @param array $thresholds 짙기의 경계.
	 * @param bool  $vertical   세로 배치인가.
	 * @return string
	 */
	private static function cells( array $args, array $grid, array $counts, array $thresholds, $vertical ) {
		$out = '<div class="gg-grid">';

		foreach ( $grid['days'] as $day ) {
			$count = isset( $counts[ $day['date'] ] ) ? (int) $counts[ $day['date'] ] : 0;
			$level = self::level( $count, $thresholds );

			// 세로면 주가 줄이 되고 요일이 칸이 된다. 가로면 그 반대다. 격자에 자리를
			// 하나하나 적어 두는 이유는 구간이 주 중간에서 시작할 때 앞자리를 비워야
			// 하는데, 흐름에 맡기면 그 빈자리가 생기지 않기 때문이다.
			$row = $vertical ? $day['week'] : $day['day'];
			$col = $vertical ? $day['day'] : $day['week'];

			$caption = self::caption( $args, $day['date'], $count );
			$link    = GG_Source::link( $args, $day['date'], $count );

			$attrs = sprintf(
				' class="gg-day gg-l%d" style="grid-row:%d;grid-column:%d" data-date="%s" data-count="%d" title="%s"',
				$level,
				$row + 1,
				$col + 1,
				esc_attr( $day['date'] ),
				$count,
				esc_attr( $caption )
			);

			if ( '' !== $link ) {
				$out .= '<a href="' . esc_url( $link ) . '" aria-label="' . esc_attr( $caption ) . '"' . $attrs . '></a>';
			} else {
				$out .= '<span role="img" aria-label="' . esc_attr( $caption ) . '"' . $attrs . '></span>';
			}
		}

		return $out . '</div>';
	}

	/**
	 * 달 이름 띠.
	 *
	 * @param array $grid     GG_Range::grid() 의 결과.
	 * @param bool  $vertical 세로 배치인가.
	 * @return string
	 */
	private static function months_strip( array $grid, $vertical ) {
		$out = '<div class="gg-months" aria-hidden="true">';
		foreach ( GG_Range::months( $grid['days'] ) as $month ) {
			$out .= sprintf(
				'<span class="gg-month" style="%s:%d / span %d">%s</span>',
				$vertical ? 'grid-row' : 'grid-column',
				(int) $month['start'] + 1,
				(int) $month['span'],
				esc_html( $month['label'] )
			);
		}
		return $out . '</div>';
	}

	/**
	 * 요일 띠.
	 *
	 * @param array $args     인자.
	 * @param bool  $vertical 세로 배치인가.
	 * @return string
	 */
	private static function weekdays_strip( array $args, $vertical ) {
		$out = '<div class="gg-weekdays" aria-hidden="true">';
		foreach ( self::weekday_labels( $args['week_start'] ) as $row => $label ) {
			$out .= sprintf(
				'<span class="gg-weekday" style="%s:%d">%s</span>',
				$vertical ? 'grid-column' : 'grid-row',
				(int) $row + 1,
				esc_html( $label )
			);
		}
		return $out . '</div>';
	}

	/**
	 * 옅음에서 짙음까지의 눈금.
	 *
	 * @return string
	 */
	private static function legend() {
		$out = '<p class="gg-legend"><span class="gg-legend-text">' . esc_html__( 'Less', 'green-grass' ) . '</span>';
		for ( $level = 0; $level <= 4; $level++ ) {
			$out .= '<span class="gg-day gg-l' . $level . '" aria-hidden="true"></span>';
		}
		return $out . '<span class="gg-legend-text">' . esc_html__( 'More', 'green-grass' ) . '</span></p>';
	}

	/**
	 * 오류. 조용히 빈 자리를 남기지 않는다.
	 *
	 * @param string $message 무엇이 잘못됐는지.
	 * @return string
	 */
	public static function error( $message ) {
		error_log( '[green-grass] ' . $message );
		return '<p class="gg-error">[green_grass] ' . esc_html( $message ) . '</p>';
	}
}
