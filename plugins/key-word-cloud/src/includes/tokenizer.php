<?php
/**
 * 텍스트를 단어로 자르고 한국어 조사를 떼어낸다.
 *
 * @package KeyWordCloud
 */

defined( 'ABSPATH' ) || exit;

final class KWC_Tokenizer {

	/**
	 * HTML/숏코드를 걷어내고 단어 단위로 자른다.
	 *
	 * @param string $text 원문.
	 * @return array 단어 배열.
	 */
	public static function tokenize( $text ) {
		$text = (string) $text;
		if ( '' === trim( $text ) ) {
			return array();
		}

		$text = strip_shortcodes( $text );
		$text = preg_replace( '#<(script|style)[^>]*>.*?</\1>#is', ' ', $text );
		$text = wp_strip_all_tags( $text, true );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// 글자와 숫자만 남기고 나머지는 경계로 본다.
		$tokens = preg_split( '/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		if ( false === $tokens ) {
			// 실패를 삼키면 "단어가 없는 글"로 보인다. 반드시 남긴다.
			error_log( '[key-word-cloud] preg_split failed while tokenizing (PCRE error: ' . preg_last_error() . ')' );
			return array();
		}
		return $tokens;
	}

	/**
	 * 토큰이 한글 음절로만 이루어져 있는가.
	 *
	 * @param string $word 단어.
	 * @return bool
	 */
	public static function is_hangul( $word ) {
		return 1 === preg_match( '/^[\x{AC00}-\x{D7A3}]+$/u', $word );
	}

	/**
	 * 조사 목록을 길이 내림차순으로 정렬한다. '에서는'이 '는'보다 먼저 걸려야 한다.
	 *
	 * @param array $particles 조사 배열.
	 * @return array
	 */
	public static function sort_particles( array $particles ) {
		usort( $particles, function ( $a, $b ) {
			return mb_strlen( $b, 'UTF-8' ) <=> mb_strlen( $a, 'UTF-8' );
		} );
		return $particles;
	}

	/**
	 * 한글 단어 끝의 조사를 떼어낸다.
	 *
	 * 어간이 $min_stem 음절보다 짧아지면 떼지 않는다. '고양이'가 '고양'이 되는 식의
	 * 오분리를 완전히 막지는 못한다 - 형태소 분석기가 아니라 규칙 기반이다.
	 * 오분리가 잦으면 min_stem 을 3 으로 올리거나 해당 조사를 목록에서 빼라.
	 *
	 * @param string $word      단어.
	 * @param array  $particles 길이 내림차순으로 정렬된 조사 배열.
	 * @param int    $min_stem  남길 최소 어간 음절 수.
	 * @param int    $passes    최대 반복 횟수 ('학교' + '에서' + '는' 처럼 겹친 경우).
	 * @return string
	 */
	public static function strip_particles( $word, array $particles, $min_stem, $passes = 2 ) {
		if ( ! self::is_hangul( $word ) ) {
			return $word;
		}

		for ( $i = 0; $i < $passes; $i++ ) {
			$stripped = false;
			$w_len    = mb_strlen( $word, 'UTF-8' );

			foreach ( $particles as $particle ) {
				$p_len = mb_strlen( $particle, 'UTF-8' );
				if ( $p_len < 1 || ( $w_len - $p_len ) < $min_stem ) {
					continue;
				}
				if ( mb_substr( $word, -$p_len, null, 'UTF-8' ) === $particle ) {
					$word     = mb_substr( $word, 0, $w_len - $p_len, 'UTF-8' );
					$stripped = true;
					break;
				}
			}

			if ( ! $stripped ) {
				break;
			}
		}

		return $word;
	}

	/**
	 * 단어를 세는 형태로 정규화한다. 세지 않을 단어는 빈 문자열을 돌려준다.
	 *
	 * @param string $word      단어.
	 * @param array  $stopwords 소문자로 정규화된 불용어 맵 (word => true).
	 * @param array  $args      min_len, kr_particles_on, kr_particles, kr_min_stem.
	 * @return string
	 */
	public static function normalize( $word, array $stopwords, array $args ) {
		$word = mb_strtolower( $word, 'UTF-8' );

		if ( isset( $stopwords[ $word ] ) ) {
			return '';
		}

		if ( ! empty( $args['kr_particles_on'] ) ) {
			$word = self::strip_particles( $word, $args['kr_particles'], (int) $args['kr_min_stem'] );
			// 조사를 떼고 나서 불용어가 되는 경우가 있다 ('우리가' -> '우리').
			if ( isset( $stopwords[ $word ] ) ) {
				return '';
			}
		}

		if ( mb_strlen( $word, 'UTF-8' ) < (int) $args['min_len'] ) {
			return '';
		}

		// 숫자만 있는 토큰은 단어로 보지 않는다.
		if ( 1 === preg_match( '/^\p{N}+$/u', $word ) ) {
			return '';
		}

		return $word;
	}
}
