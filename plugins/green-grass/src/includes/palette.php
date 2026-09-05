<?php
/**
 * 다섯 단계의 색.
 *
 * 0 은 하루도 없는 날이고 4 가 가장 짙다. 기본값은 GitHub 이 쓰는 다섯 색을 그대로
 * 적어 둔 것이다. 색 하나에서 계산해 내지 않는 이유는, GitHub 의 층이 흰색을 섞은
 * 것도 밝기를 고르게 올린 것도 아니어서 어떤 식으로 계산해도 그 색이 나오지 않기
 * 때문이다. 첨부한 그림과 같아 보여야 하므로 그냥 적는다.
 *
 * 다른 색을 고르면 그때는 계산한다. 색상과 채도는 두고 밝기만 올린다.
 * 올리는 폭은 GitHub 의 층에서 잰 것이라, 초록을 고르면 기본값과 거의 같아진다.
 *
 * @package GreenGrass
 */

defined( 'ABSPATH' ) || exit;

final class GG_Palette {

	/** GitHub 의 다섯 색. 0 부터 4 까지. */
	const GITHUB = array( '#ebedf0', '#9be9a8', '#40c463', '#30a14e', '#216e39' );

	/** 가장 짙은 층에서 각 층까지 밝기를 얼마나 올릴지. GitHub 의 층에서 쟀다. */
	const LIFT = array( 1 => 48, 2 => 23, 3 => 9, 4 => 0 );

	/** 아무리 옅어도 이보다 밝아지지는 않는다. 흰 바탕에서 사라지지 않게. */
	const MAX_LIGHT = 92;

	/**
	 * #rrggbb 를 0~255 세 값으로.
	 *
	 * @param string $hex 색.
	 * @return array|null r, g, b. 읽을 수 없으면 null.
	 */
	public static function to_rgb( $hex ) {
		$hex = ltrim( trim( (string) $hex ), '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 1 !== preg_match( '/^[0-9a-fA-F]{6}$/', $hex ) ) {
			return null;
		}
		return array(
			hexdec( substr( $hex, 0, 2 ) ),
			hexdec( substr( $hex, 2, 2 ) ),
			hexdec( substr( $hex, 4, 2 ) ),
		);
	}

	/**
	 * 색을 읽을 수 있는지만 본다.
	 *
	 * @param string $hex 색.
	 * @return bool
	 */
	public static function is_color( $hex ) {
		return null !== self::to_rgb( $hex );
	}

	/**
	 * RGB 를 HSL 로. h 는 0~360, s 와 l 은 0~100.
	 *
	 * @param array $rgb r, g, b.
	 * @return array h, s, l.
	 */
	private static function to_hsl( array $rgb ) {
		list( $r, $g, $b ) = array( $rgb[0] / 255, $rgb[1] / 255, $rgb[2] / 255 );
		$max = max( $r, $g, $b );
		$min = min( $r, $g, $b );
		$l   = ( $max + $min ) / 2;

		if ( $max === $min ) {
			return array( 0.0, 0.0, $l * 100 );   // 회색에는 색상이 없다
		}

		$d = $max - $min;
		$s = ( $l > 0.5 ) ? $d / ( 2 - $max - $min ) : $d / ( $max + $min );

		if ( $max === $r ) {
			$h = ( $g - $b ) / $d + ( $g < $b ? 6 : 0 );
		} elseif ( $max === $g ) {
			$h = ( $b - $r ) / $d + 2;
		} else {
			$h = ( $r - $g ) / $d + 4;
		}

		return array( $h * 60, $s * 100, $l * 100 );
	}

	/**
	 * HSL 을 #rrggbb 로.
	 *
	 * @param float $h 0~360.
	 * @param float $s 0~100.
	 * @param float $l 0~100.
	 * @return string
	 */
	private static function from_hsl( $h, $s, $l ) {
		$h = fmod( fmod( $h, 360 ) + 360, 360 ) / 360;
		$s = min( 100, max( 0, $s ) ) / 100;
		$l = min( 100, max( 0, $l ) ) / 100;

		if ( 0.0 === (float) $s ) {
			$channels = array( $l, $l, $l );
		} else {
			$q = ( $l < 0.5 ) ? $l * ( 1 + $s ) : $l + $s - $l * $s;
			$p = 2 * $l - $q;
			$channels = array(
				self::hue_channel( $p, $q, $h + 1 / 3 ),
				self::hue_channel( $p, $q, $h ),
				self::hue_channel( $p, $q, $h - 1 / 3 ),
			);
		}

		$out = '#';
		foreach ( $channels as $channel ) {
			$out .= str_pad( dechex( (int) round( $channel * 255 ) ), 2, '0', STR_PAD_LEFT );
		}
		return $out;
	}

	/**
	 * HSL 되돌리기의 한 채널.
	 *
	 * @param float $p 아래쪽 값.
	 * @param float $q 위쪽 값.
	 * @param float $t 0~1 로 접은 색상.
	 * @return float
	 */
	private static function hue_channel( $p, $q, $t ) {
		if ( $t < 0 ) {
			$t += 1;
		}
		if ( $t > 1 ) {
			$t -= 1;
		}
		if ( $t < 1 / 6 ) {
			return $p + ( $q - $p ) * 6 * $t;
		}
		if ( $t < 1 / 2 ) {
			return $q;
		}
		if ( $t < 2 / 3 ) {
			return $p + ( $q - $p ) * ( 2 / 3 - $t ) * 6;
		}
		return $p;
	}

	/**
	 * 다섯 색을 고른다.
	 *
	 * @param string $mode  github | custom.
	 * @param string $color palette=custom 일 때 가장 짙은 색.
	 * @param string $empty 0 층의 색.
	 * @return array 0 부터 4 까지 다섯 개의 #rrggbb.
	 */
	public static function levels( $mode, $color, $empty ) {
		$zero = self::is_color( $empty ) ? self::normalize( $empty ) : self::GITHUB[0];

		if ( 'custom' !== $mode ) {
			$out    = self::GITHUB;
			$out[0] = $zero;
			return $out;
		}

		$rgb = self::to_rgb( $color );
		if ( null === $rgb ) {
			// 여기까지 잘못된 색이 오면 검증이 샌 것이다. 남겨 두고 기본값으로 그린다.
			error_log( '[green-grass] palette=custom but the colour is unreadable: ' . $color );
			$out    = self::GITHUB;
			$out[0] = $zero;
			return $out;
		}

		list( $h, $s, $l ) = self::to_hsl( $rgb );

		$out = array( $zero );
		foreach ( array( 1, 2, 3, 4 ) as $level ) {
			$out[ $level ] = self::from_hsl( $h, $s, min( self::MAX_LIGHT, $l + self::LIFT[ $level ] ) );
		}
		return $out;
	}

	/**
	 * #abc 든 ABCDEF 든 #abcdef 로 맞춘다.
	 *
	 * @param string $hex 색.
	 * @return string
	 */
	public static function normalize( $hex ) {
		$rgb = self::to_rgb( $hex );
		if ( null === $rgb ) {
			return '';
		}
		return sprintf( '#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2] );
	}
}
