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
	 * 고른 언어로 그릴 이름을 고른다.
	 *
	 * 파이프라인이 두 언어의 이름을 붙여 주면 한글로 쓴 글에서 나온 topic 도 영어
	 * 구름에 나온다. 언어를 고르는 일이 글의 절반을 감추는 일이 되지 않게 하려는 것이다.
	 * 이름이 하나뿐인 예전 자료는 글자로 가르던 예전 방식을 그대로 쓴다.
	 *
	 * @param array  $topic    label 과 labels 를 가진 topic.
	 * @param string $language en | ko | both.
	 * @return string|null 그릴 이름. 이 언어로는 그리지 않을 topic 이면 null.
	 */
	public static function label_for( array $topic, $language ) {
		$native = isset( $topic['label'] ) ? (string) $topic['label'] : '';
		if ( 'both' === $language ) {
			return $native;
		}
		$labels = isset( $topic['labels'] ) && is_array( $topic['labels'] ) ? $topic['labels'] : array();
		if ( isset( $labels[ $language ] ) && '' !== $labels[ $language ] ) {
			return (string) $labels[ $language ];
		}
		// 번역이 없으면 감출 수밖에 없다. 영어 구름에 한글을 그리면 고른 것과 다른 것이 나온다.
		return self::matches( $native, $language ) ? $native : null;
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
