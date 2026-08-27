<?php
/**
 * 글을 모아 단어 빈도를 세고 단어 구름 HTML 을 만든다.
 *
 * @package KeyWordCloud
 */

defined( 'ABSPATH' ) || exit;

final class KWC_Cloud {

	const TRANSIENT_PREFIX = 'kwc_';

	/**
	 * 프런트엔드 CSS 등록. 숏코드가 실제로 쓰일 때만 enqueue 한다.
	 */
	public static function register_assets() {
		wp_register_style( 'key-word-cloud', KWC_URL . 'assets/kwc.css', array(), KWC_VERSION );
	}

	/**
	 * 저장된 설정 + 기본값.
	 *
	 * @return array
	 */
	public static function options() {
		$saved = get_option( KWC_OPTION, array() );
		if ( ! is_array( $saved ) ) {
			error_log( '[key-word-cloud] option ' . KWC_OPTION . ' is not an array; falling back to defaults' );
			$saved = array();
		}
		return array_merge( KWC_Defaults::options(), $saved );
	}

	/**
	 * 색상 문자열을 #rrggbb 로 정규화한다. 형식이 틀리면 null.
	 *
	 * @param string $color 색상.
	 * @return string|null
	 */
	public static function sanitize_hex( $color ) {
		$color = trim( (string) $color );
		if ( 1 === preg_match( '/^#?([0-9a-fA-F]{6})$/', $color, $m ) ) {
			return '#' . strtolower( $m[1] );
		}
		if ( 1 === preg_match( '/^#?([0-9a-fA-F]{3})$/', $color, $m ) ) {
			$s = strtolower( $m[1] );
			return '#' . $s[0] . $s[0] . $s[1] . $s[1] . $s[2] . $s[2];
		}
		return null;
	}

	/**
	 * 두 색 사이를 선형 보간한다.
	 *
	 * @param string $from   시작 색 (#rrggbb).
	 * @param string $to     끝 색 (#rrggbb).
	 * @param float  $weight 0..1.
	 * @return string
	 */
	private static function mix_color( $from, $to, $weight ) {
		$weight = max( 0.0, min( 1.0, (float) $weight ) );
		$out    = '#';
		for ( $i = 0; $i < 3; $i++ ) {
			$a    = hexdec( substr( $from, 1 + $i * 2, 2 ) );
			$b    = hexdec( substr( $to, 1 + $i * 2, 2 ) );
			$out .= str_pad( dechex( (int) round( $a + ( $b - $a ) * $weight ) ), 2, '0', STR_PAD_LEFT );
		}
		return $out;
	}

	/**
	 * 대상 글을 읽어 단어 빈도와 문서 빈도를 센다.
	 *
	 * counts 는 전체 등장 횟수, doc_freq 는 그 단어가 나온 글의 수다.
	 * 둘이 있어야 TF-IDF 를 계산할 수 있다.
	 *
	 * @param array $args 정규화된 인자.
	 * @return array counts(word => count, 빈도 내림차순), doc_freq(word => 글 수),
	 *               docs(단어를 하나라도 담은 글 수), scanned, empty_source.
	 */
	public static function count_words( array $args ) {
		$query_args = array(
			'post_type'              => $args['post_types'],
			'post_status'            => 'publish',
			'posts_per_page'         => (int) $args['scan_limit'],
			'ignore_sticky_posts'    => true,
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'orderby'                => 'date',
			'order'                  => 'DESC',
		);
		if ( '' !== $args['category'] ) {
			$query_args['category_name'] = $args['category'];
		}
		if ( '' !== $args['tag'] ) {
			$query_args['tag'] = $args['tag'];
		}

		$posts = get_posts( $query_args );

		$stopwords = array();
		foreach ( $args['stopwords'] as $stopword ) {
			$stopwords[ mb_strtolower( $stopword, 'UTF-8' ) ] = true;
		}

		$counts       = array();
		$doc_freq     = array();
		$docs         = 0;
		$empty_source = 0;

		foreach ( $posts as $post ) {
			if ( 'excerpt' === $args['source'] ) {
				$text = (string) $post->post_excerpt;
				if ( '' === trim( $text ) ) {
					$empty_source++;
					// 기본값은 대체하지 않는다. 대체는 설정에서 명시적으로 켠 경우에만.
					if ( empty( $args['excerpt_fallback'] ) ) {
						continue;
					}
					$text = (string) $post->post_content;
				}
			} else {
				$text = (string) $post->post_content;
				if ( '' === trim( $text ) ) {
					$empty_source++;
					continue;
				}
			}

			$seen = array();
			foreach ( KWC_Tokenizer::tokenize( $text ) as $token ) {
				$word = KWC_Tokenizer::normalize( $token, $stopwords, $args );
				if ( '' === $word ) {
					continue;
				}
				$counts[ $word ] = isset( $counts[ $word ] ) ? $counts[ $word ] + 1 : 1;
				$seen[ $word ]   = true;
			}

			if ( empty( $seen ) ) {
				continue;
			}
			$docs++;
			foreach ( array_keys( $seen ) as $word ) {
				$doc_freq[ $word ] = isset( $doc_freq[ $word ] ) ? $doc_freq[ $word ] + 1 : 1;
			}
		}

		arsort( $counts );

		return array(
			'counts'       => $counts,
			'doc_freq'     => $doc_freq,
			'docs'         => $docs,
			'scanned'      => count( $posts ),
			'empty_source' => $empty_source,
		);
	}

	/**
	 * 단어마다 순위 점수를 매긴다.
	 *
	 * tfidf: (1 + log TF) x log(1 + N / DF).
	 *   모든 글에 두루 나오는 단어는 DF 가 커져 점수가 내려가고,
	 *   몇 글에 몰려 나오는 단어가 올라온다. 그것이 key word 다.
	 *   log(1 + N/DF) 를 쓰므로 DF = N 이어도 0 이 되어 사라지지는 않는다.
	 * count: 등장 횟수 그대로. 예전 동작이다.
	 *
	 * @param array  $counts   word => 전체 등장 횟수.
	 * @param array  $doc_freq word => 그 단어가 나온 글 수.
	 * @param int    $docs     단어를 담은 글 수.
	 * @param string $ranking  tfidf | count.
	 * @return array word => 점수 (내림차순).
	 */
	public static function score_words( array $counts, array $doc_freq, $docs, $ranking ) {
		if ( 'count' === $ranking ) {
			return $counts;
		}
		if ( 'tfidf' !== $ranking ) {
			// 여기까지 온 값은 검증을 거쳤어야 한다. 조용히 한쪽을 고르지 않는다.
			error_log( '[key-word-cloud] unknown ranking: ' . $ranking );
			return $counts;
		}
		if ( $docs < 1 ) {
			error_log( '[key-word-cloud] tfidf needs at least one document; got ' . $docs );
			return $counts;
		}

		$scores = array();
		foreach ( $counts as $word => $tf ) {
			$df = isset( $doc_freq[ $word ] ) ? (int) $doc_freq[ $word ] : 0;
			if ( $df < 1 ) {
				// counts 에 있으면 반드시 어느 글에선가 나왔다. 어긋나면 버그다.
				error_log( '[key-word-cloud] doc_freq missing for ' . $word );
				continue;
			}
			$scores[ $word ] = ( 1 + log( $tf ) ) * log( 1 + $docs / $df );
		}

		arsort( $scores );
		return $scores;
	}

	/**
	 * 단어 구름 HTML 을 만든다.
	 *
	 * @param array $args 정규화된 인자.
	 * @return string
	 */
	public static function render( array $args ) {
		if ( ! kwc_requirements_met() ) {
			return '<p class="kwc-error">Key Word Cloud: PHP mbstring 확장이 없어 단어 구름을 만들 수 없다.</p>';
		}

		$ttl = (int) $args['cache_ttl'];
		$key = self::TRANSIENT_PREFIX . md5( wp_json_encode( $args ) );

		$html = ( $ttl > 0 ) ? get_transient( $key ) : false;
		if ( is_string( $html ) ) {
			wp_enqueue_style( 'key-word-cloud' );
			return $html;
		}

		$result = self::count_words( $args );
		$counts = $result['counts'];

		if ( 0 === $result['scanned'] ) {
			error_log( '[key-word-cloud] no published posts matched post_type=' . implode( ',', $args['post_types'] ) . ' category=' . $args['category'] . ' tag=' . $args['tag'] );
			return '<p class="kwc-error">Key Word Cloud: 조건에 맞는 글이 없다.</p>';
		}

		if ( $result['empty_source'] > 0 ) {
			error_log( sprintf( '[key-word-cloud] %d/%d posts had an empty %s', $result['empty_source'], $result['scanned'], $args['source'] ) );
		}

		// min_count 미만 제거.
		$min_count = (int) $args['min_count'];
		if ( $min_count > 1 ) {
			$counts = array_filter(
				$counts,
				function ( $c ) use ( $min_count ) {
					return $c >= $min_count;
				}
			);
		}

		if ( empty( $counts ) ) {
			// 빈 결과를 빈 화면으로 넘기지 않는다. 0 건은 그 자체로 신호다.
			$why = ( 'excerpt' === $args['source'] && $result['empty_source'] === $result['scanned'] )
				? '글 ' . $result['scanned'] . '개 모두 요약문(Excerpt)이 비어 있다.'
				: '글 ' . $result['scanned'] . '개를 읽었지만 조건(min_len=' . (int) $args['min_len'] . ', min_count=' . $min_count . ')을 넘는 단어가 없다.';
			error_log( '[key-word-cloud] empty cloud: ' . $why );
			return '<p class="kwc-error">Key Word Cloud: 표시할 단어가 없다. ' . esc_html( $why ) . '</p>';
		}

		// TF-IDF 는 희귀할수록 점수를 올리므로, 글 하나에만 있는 오탈자나 코드 조각이
		// 맨 위로 올라온다. 여러 글에 걸쳐 나온 단어만 후보로 남긴다.
		// 하한을 글 개수로 두면 사이트 규모가 달라질 때 깨지므로 비율로 잡는다.
		$min_docs = (int) ceil( $result['docs'] * (int) $args['min_docs_pct'] / 100 );
		if ( $min_docs > 1 ) {
			$doc_freq = $result['doc_freq'];
			$kept     = array();
			foreach ( $counts as $word => $count ) {
				if ( isset( $doc_freq[ $word ] ) && $doc_freq[ $word ] >= $min_docs ) {
					$kept[ $word ] = $count;
				}
			}
			if ( empty( $kept ) ) {
				error_log( '[key-word-cloud] min_docs=' . $min_docs . ' removed every word of ' . count( $counts ) );
				return '<p class="kwc-error">Key Word Cloud: 표시할 단어가 없다. ' . esc_html( '글 ' . $min_docs . '개 이상에 나온 단어가 없다.' ) . '</p>';
			}
			$counts = $kept;
		}

		// 크기 순서는 점수가 정하고, 화면에 적는 숫자는 실제 등장 횟수 그대로 둔다.
		$scores = self::score_words( $counts, $result['doc_freq'], $result['docs'], $args['ranking'] );
		$scores = array_slice( $scores, 0, (int) $args['max_words'], true );

		$max = max( $scores );
		$min = min( $scores );
		// sqrt 스케일이 선형보다 점수 차이를 덜 과장한다.
		$span = sqrt( $max ) - sqrt( $min );

		$items = array();
		foreach ( $scores as $word => $score ) {
			$count  = isset( $counts[ $word ] ) ? (int) $counts[ $word ] : 0;
			$weight = ( $span > 0 ) ? ( ( sqrt( $score ) - sqrt( $min ) ) / $span ) : 1.0;
			$size   = $args['min_size'] + ( $args['max_size'] - $args['min_size'] ) * $weight;
			$color  = self::mix_color( $args['color_start'], $args['color_end'], $weight );
			$style  = sprintf( 'font-size:%.1fpx;color:%s;', $size, $color );
			$title  = ( 'tfidf' === $args['ranking'] )
				? sprintf( '%s — %d회, 글 %d개', $word, $count, isset( $result['doc_freq'][ $word ] ) ? (int) $result['doc_freq'][ $word ] : 0 )
				: sprintf( '%s (%d)', $word, $count );

			if ( 'search' === $args['link_mode'] ) {
				$link_args = array( 's' => $word );
				if ( 1 === count( $args['post_types'] ) ) {
					$link_args['post_type'] = $args['post_types'][0];
				}
				$items[] = sprintf(
					'<a class="kwc-word" href="%s" style="%s" title="%s" data-count="%d">%s</a>',
					esc_url( add_query_arg( $link_args, home_url( '/' ) ) ),
					esc_attr( $style ),
					esc_attr( $title ),
					$count,
					esc_html( $word )
				);
			} else {
				$items[] = sprintf(
					'<span class="kwc-word kwc-word--static" style="%s" title="%s" data-count="%d">%s</span>',
					esc_attr( $style ),
					esc_attr( $title ),
					$count,
					esc_html( $word )
				);
			}
		}

		$html = '<div class="kwc-cloud">' . implode( "\n", $items ) . '</div>';

		if ( $ttl > 0 ) {
			set_transient( $key, $html, $ttl );
		}

		wp_enqueue_style( 'key-word-cloud' );
		return $html;
	}

	/**
	 * 캐시를 무효화한다. salt 를 올려 기존 키를 못 쓰게 만들고, 남은 행도 지운다.
	 */
	public static function flush_cache() {
		$options               = self::options();
		$options['cache_salt'] = (int) $options['cache_salt'] + 1;
		update_option( KWC_OPTION, $options );
		kwc_flush_cache();
	}
}
