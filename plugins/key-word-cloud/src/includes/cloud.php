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

	/** 이웃을 갈라 보이게 하는 색. 개수는 CSS 의 .kwc-word--cN 과 맞춰야 한다. */
	const PALETTE_SIZE = 5;

	/**
	 * 프런트엔드 자산 등록. 숏코드가 실제로 쓰일 때만 enqueue 한다.
	 */
	public static function register_assets() {
		wp_register_style( 'key-word-cloud', KWC_URL . 'assets/kwc.css', array(), KWC_VERSION );
		wp_register_script( 'key-word-cloud', KWC_URL . 'assets/kwc.js', array(), KWC_VERSION, true );
	}

	/**
	 * 구름을 그릴 때 CSS 와 script 를 함께 싣는다.
	 *
	 * script 는 편집자만이 아니라 모든 방문자에게 필요하다. 타원의 높이를 내용에 맞춰
	 * 넣는 일이 거기에 있기 때문이다.
	 */
	private static function enqueue_assets() {
		wp_enqueue_style( 'key-word-cloud' );
		wp_enqueue_script( 'key-word-cloud' );
	}

	/**
	 * 새로고침 단추와 그 script 를 붙인다. 글을 고칠 수 있는 사람에게만 보인다.
	 *
	 * @return string 단추의 HTML. 권한이 없으면 빈 문자열.
	 */
	private static function refresh_button() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return '';
		}
		wp_localize_script( 'key-word-cloud', 'KWC_REFRESH', array(
			'url'   => rest_url( KWC_Topics::NAMESPACE . '/refresh' ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
		) );
		return '<button type="button" class="kwc-refresh" title="'
			. esc_attr( '지금 topic 을 다시 받아온다' ) . '">새로고침</button>';
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
		// 새로고침 단추가 붙는지는 보는 사람에 따라 다르다. 키에 넣지 않으면 편집자가
		// 손님용으로 캐시된 HTML 을 받아 단추가 사라진다.
		$key = self::TRANSIENT_PREFIX . md5(
			wp_json_encode( $args ) . '|' . ( current_user_can( 'edit_posts' ) ? 'editor' : 'guest' )
		);

		$html = ( $ttl > 0 ) ? get_transient( $key ) : false;
		if ( is_string( $html ) ) {
			self::enqueue_assets();
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
				'tip'   => sprintf(
					'글 %d개%s',
					$posts,
					empty( $phrases ) ? '' : "\n" . implode( ' · ', array_slice( $phrases, 0, 8 ) )
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

		self::enqueue_assets();
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

		// 타원으로 깎을 때는 큰 글자가 가운데로 가야 모양이 산다.
		if ( 'ellipse' === $args['shape'] ) {
			$entries = self::centre_heaviest( $entries );
		}

		$items = array();
		$index = 0;
		foreach ( $entries as $entry ) {
			$weight = ( $span > 0 ) ? ( ( sqrt( $entry['posts'] ) - sqrt( $min ) ) / $span ) : 1.0;
			$size   = $args['min_size'] + ( $args['max_size'] - $args['min_size'] ) * $weight;

			$class = 'kwc-word';
			if ( 'palette' === $args['color_mode'] ) {
				$class .= ' kwc-word--c' . ( $index % self::PALETTE_SIZE );
				$style  = sprintf( 'font-size:%.1fpx;', $size );
			} else {
				$style = sprintf(
					'font-size:%.1fpx;color:%s;',
					$size,
					self::mix_color( $args['color_start'], $args['color_end'], $weight )
				);
			}
			$index++;

			if ( 'search' === $args['link_mode'] ) {
				$items[] = sprintf(
					'<a class="%s" href="%s" style="%s" data-tip="%s" data-count="%d">%s</a>',
					esc_attr( $class ),
					esc_url( add_query_arg( array( 's' => $entry['text'] ), home_url( '/' ) ) ),
					esc_attr( $style ),
					esc_attr( $entry['tip'] ),
					(int) $entry['posts'],
					esc_html( $entry['text'] )
				);
			} else {
				$items[] = sprintf(
					'<span class="%s kwc-word--static" style="%s" data-tip="%s" data-count="%d">%s</span>',
					esc_attr( $class ),
					esc_attr( $style ),
					esc_attr( $entry['tip'] ),
					(int) $entry['posts'],
					esc_html( $entry['text'] )
				);
			}
		}

		$cloud_class = ( 'ellipse' === $args['shape'] ) ? 'kwc-cloud kwc-cloud--ellipse' : 'kwc-cloud';

		return '<div class="kwc">' . self::refresh_button()
			. '<div class="' . esc_attr( $cloud_class ) . '">' . implode( "\n", $items ) . '</div></div>';
	}

	/**
	 * 큰 것을 가운데에, 작은 것을 양 끝에 오도록 다시 늘어놓는다.
	 *
	 * 타원은 가운데가 넓고 끝이 좁다. 빈도순 그대로 두면 큰 글자가 위쪽 좁은 곳에
	 * 몰려 모양이 무너진다.
	 *
	 * @param array $entries 글 수 내림차순으로 정렬된 항목들.
	 * @return array
	 */
	private static function centre_heaviest( array $entries ) {
		$left  = array();
		$right = array();
		foreach ( $entries as $position => $entry ) {
			if ( 0 === $position % 2 ) {
				$left[] = $entry;   // 큰 것부터 번갈아 담아
			} else {
				$right[] = $entry;
			}
		}
		// 한쪽을 뒤집어 이으면 작은 것 - 큰 것 - 작은 것 순서가 된다.
		return array_merge( array_reverse( $left ), $right );
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
