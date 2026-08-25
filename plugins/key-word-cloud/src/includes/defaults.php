<?php
/**
 * 기본 설정값, 기본 불용어, 기본 한국어 조사 목록.
 *
 * @package KeyWordCloud
 */

defined( 'ABSPATH' ) || exit;

final class KWC_Defaults {

	/**
	 * 설정 기본값.
	 *
	 * @return array
	 */
	public static function options() {
		return array(
			'source'           => 'content',   // content | excerpt
			'excerpt_fallback' => 0,           // excerpt 가 비었을 때 본문으로 대체할지 (기본 off: 조용한 대체 금지)
			'post_types'       => array( 'post' ),
			'scan_limit'       => 300,
			'max_words'        => 60,
			'min_count'        => 1,
			'min_len'          => 2,
			'min_size'         => 12,
			'max_size'         => 44,
			'color_start'      => '#8aa4c8',
			'color_end'        => '#12355b',
			'link_mode'        => 'search',    // search | none
			'kr_particles_on'  => 1,
			'kr_min_stem'      => 2,
			'cache_ttl'        => 3600,
			'stopwords'        => self::stopwords_text(),
			'kr_particles'     => self::particles_text(),
			'cache_salt'       => 1,
		);
	}

	/**
	 * 기본 불용어. 줄바꿈 또는 쉼표로 구분한다.
	 *
	 * @return string
	 */
	public static function stopwords_text() {
		$words = array(
			// 한국어
			'그리고', '그러나', '하지만', '그래서', '또한', '그런데', '따라서', '즉', '및', '등',
			'이것', '그것', '저것', '무엇', '어떤', '어떻게', '이런', '그런', '저런', '이렇게', '그렇게',
			'우리', '저희', '당신', '자신', '자기', '여기', '거기', '저기', '지금', '오늘', '내일', '어제',
			'때문', '위해', '통해', '대해', '관련', '경우', '정도', '가지', '수도', '무슨', '해서', '하는',
			'있다', '없다', '한다', '된다', '이다', '아니다', '같다', '보다', '많다', '적다', '다시', '가장',
			// 영어
			'the', 'a', 'an', 'and', 'or', 'but', 'if', 'then', 'than', 'so', 'of', 'to', 'in', 'on', 'at',
			'by', 'for', 'with', 'from', 'as', 'is', 'am', 'are', 'was', 'were', 'be', 'been', 'being',
			'do', 'does', 'did', 'have', 'has', 'had', 'this', 'that', 'these', 'those', 'it', 'its',
			'we', 'you', 'they', 'he', 'she', 'his', 'her', 'their', 'our', 'your', 'my', 'me', 'us',
			'not', 'no', 'can', 'will', 'would', 'should', 'could', 'may', 'might', 'must', 'there',
			'here', 'what', 'which', 'who', 'when', 'where', 'why', 'how', 'all', 'any', 'some', 'more',
			'most', 'other', 'such', 'only', 'own', 'same', 'too', 'very', 'just', 'about', 'into',
			'over', 'after', 'before', 'up', 'down', 'out', 'off', 'again', 'also',
		);
		return implode( "\n", $words );
	}

	/**
	 * 기본 한국어 조사. 긴 것이 먼저 매칭되도록 사용 시점에 길이순 정렬한다.
	 *
	 * @return string
	 */
	public static function particles_text() {
		$particles = array(
			// 복합 조사 (2 pass 로도 걸러지지만 명시해 두면 1 pass 에 끝난다)
			'에서는', '에서도', '에게는', '에게서', '한테서', '으로는', '으로도', '까지는', '부터는',
			'이라는', '이라고', '이라도', '으로서', '으로써', '에서의', '에게도',
			// 2 음절 조사
			'에서', '에게', '께서', '한테', '이랑', '하고', '까지', '부터', '조차', '마저', '밖에',
			'처럼', '같이', '으로', '로서', '로써', '라도', '이나', '든지', '이며', '라고', '라는',
			'대로', '만큼', '이여', '이요', '보다',
			// 1 음절 조사
			'은', '는', '이', '가', '을', '를', '의', '에', '와', '과', '랑', '도', '만', '뿐',
			'로', '나', '며', '고', '요', '야', '아', '여',
		);
		return implode( "\n", $particles );
	}

	/**
	 * 줄바꿈/쉼표 구분 문자열을 중복 없는 배열로 바꾼다.
	 *
	 * @param string $text 원본 문자열.
	 * @return array
	 */
	public static function to_list( $text ) {
		$parts = preg_split( '/[\r\n,]+/u', (string) $text, -1, PREG_SPLIT_NO_EMPTY );
		if ( false === $parts ) {
			error_log( '[key-word-cloud] preg_split failed while parsing a word list' );
			return array();
		}
		$out = array();
		foreach ( $parts as $part ) {
			$part = trim( $part );
			if ( '' !== $part ) {
				$out[] = $part;
			}
		}
		return array_values( array_unique( $out ) );
	}
}
