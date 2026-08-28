<?php
/**
 * 저장된 topic 을 구름으로 그린다.
 *
 * 본문을 읽지 않는다. GPU 가 있는 기계의 파이프라인이 만들어 REST 로 올려 둔 것을
 * 꺼내 크기와 색만 입힌다.
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
	 * 구름 HTML 을 만든다.
	 *
	 * @param array $args 정규화된 인자.
	 * @return string
	 */
	public static function render( array $args ) {
		$ttl = (int) $args['cache_ttl'];
		$key = self::TRANSIENT_PREFIX . md5( wp_json_encode( $args ) );

		$html = ( $ttl > 0 ) ? get_transient( $key ) : false;
		if ( is_string( $html ) ) {
			wp_enqueue_style( 'key-word-cloud' );
			return $html;
		}

		$topics = KWC_Topics::stored();
		if ( empty( $topics ) ) {
			// 아직 아무것도 안 올라왔다는 것과 구름이 비었다는 것은 다른 일이다.
			error_log( '[key-word-cloud] nothing to draw: no topics have been uploaded yet' );
			return '<p class="kwc-error">Key Word Cloud: 올라온 topic 이 없다. '
				. esc_html( 'tools/push_topics.py 로 먼저 올려라.' ) . '</p>';
		}

		$min_posts = (int) $args['min_posts'];
		$entries   = array();
		$too_few   = 0;
		$other_language = 0;
		foreach ( $topics as $topic ) {
			$posts = (int) $topic['posts'];
			if ( $posts < $min_posts ) {
				$too_few++;
				continue;
			}
			if ( ! KWC_Language::matches( $topic['label'], $args['language'] ) ) {
				$other_language++;
				continue;
			}
			$phrases   = isset( $topic['phrases'] ) ? (array) $topic['phrases'] : array();
			$entries[] = array(
				'text'  => (string) $topic['label'],
				'posts' => $posts,
				'title' => sprintf(
					'%s — 글 %d개%s',
					$topic['label'],
					$posts,
					empty( $phrases ) ? '' : ' · ' . implode( ', ', array_slice( $phrases, 0, 6 ) )
				),
			);
			if ( count( $entries ) >= (int) $args['max_words'] ) {
				break;
			}
		}

		if ( empty( $entries ) ) {
			$why = sprintf(
				'topic %d개 중 %d개는 글 %d개 미만이고 %d개는 %s 가 아니다.',
				count( $topics ), $too_few, $min_posts, $other_language, $args['language']
			);
			error_log( '[key-word-cloud] no topic survived: ' . $why );
			return '<p class="kwc-error">Key Word Cloud: 표시할 topic 이 없다. ' . esc_html( $why ) . '</p>';
		}

		$html = self::draw( $entries, $args );

		if ( $ttl > 0 ) {
			set_transient( $key, $html, $ttl );
		}

		wp_enqueue_style( 'key-word-cloud' );
		return $html;
	}

	/**
	 * 항목 목록을 구름 HTML 로 바꾼다.
	 *
	 * @param array $entries text, posts, title 을 가진 항목들.
	 * @param array $args    정규화된 인자.
	 * @return string
	 */
	private static function draw( array $entries, array $args ) {
		$counts = array_column( $entries, 'posts' );
		$max    = max( $counts );
		$min    = min( $counts );
		// sqrt 스케일이 선형보다 차이를 덜 과장한다.
		$span = sqrt( $max ) - sqrt( $min );

		$items = array();
		foreach ( $entries as $entry ) {
			$weight = ( $span > 0 ) ? ( ( sqrt( $entry['posts'] ) - sqrt( $min ) ) / $span ) : 1.0;
			$size   = $args['min_size'] + ( $args['max_size'] - $args['min_size'] ) * $weight;
			$style  = sprintf(
				'font-size:%.1fpx;color:%s;',
				$size,
				self::mix_color( $args['color_start'], $args['color_end'], $weight )
			);

			if ( 'search' === $args['link_mode'] ) {
				$items[] = sprintf(
					'<a class="kwc-word" href="%s" style="%s" title="%s" data-count="%d">%s</a>',
					esc_url( add_query_arg( array( 's' => $entry['text'] ), home_url( '/' ) ) ),
					esc_attr( $style ),
					esc_attr( $entry['title'] ),
					(int) $entry['posts'],
					esc_html( $entry['text'] )
				);
			} else {
				$items[] = sprintf(
					'<span class="kwc-word kwc-word--static" style="%s" title="%s" data-count="%d">%s</span>',
					esc_attr( $style ),
					esc_attr( $entry['title'] ),
					(int) $entry['posts'],
					esc_html( $entry['text'] )
				);
			}
		}

		return '<div class="kwc-cloud">' . implode( "\n", $items ) . '</div>';
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
