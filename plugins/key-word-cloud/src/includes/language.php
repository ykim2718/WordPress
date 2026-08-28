<?php
/**
 * Topic 을 언어로 가른다.
 *
 * 판정은 한 가지다. 한글 음절이 하나라도 있으면 한글이고, 없으면 영어다.
 * 'ats score' 처럼 한글이 섞이지 않은 것은 영어로, '종합소득세 신고' 처럼
 * 한 글자라도 든 것은 한글로 본다.
 *
 * @package KeyWordCloud
 */

defined( 'ABSPATH' ) || exit;

final class KWC_Language {

	/**
	 * 한글 음절이 하나라도 들어 있는가.
	 *
	 * @param string $text 낱말 또는 구절.
	 * @return bool
	 */
	public static function has_hangul( $text ) {
		return 1 === preg_match( '/[\x{AC00}-\x{D7A3}]/u', (string) $text );
	}

	/**
	 * 이 구절을 고른 언어에서 보여줄 것인가.
	 *
	 * @param string $text     구절.
	 * @param string $language en | ko | both.
	 * @return bool
	 */
	public static function matches( $text, $language ) {
		if ( 'both' === $language ) {
			return true;
		}
		if ( 'ko' === $language ) {
			return self::has_hangul( $text );
		}
		if ( 'en' === $language ) {
			return ! self::has_hangul( $text );
		}
		// 검증을 거친 값만 오는 자리다. 어긋나면 감추지 않고 남긴다.
		error_log( '[key-word-cloud] unknown language: ' . $language );
		return true;
	}
}
