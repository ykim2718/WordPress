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
	/**
	 * 구름 왼쪽 위의 판 번호. 어느 판이 그리고 있는지 화면에서 바로 보이게 한다.
	 *
	 * @return string
	 */
	private static function version_mark() {
		return '<span class="kwc-version">' . esc_html( KWC_VERSION ) . '</span>';
	}

	private static function refresh_button() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return '';
		}
		wp_localize_script( 'key-word-cloud', 'KWC_REFRESH', array(
			'url'   => rest_url( KWC_Topics::NAMESPACE . '/refresh' ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
		) );
		return '<button type="button" class="kwc-refresh" title="'
			. esc_attr__( 'Fetch the published topics now', 'key-word-cloud' ) . '">'
			. esc_html__( 'Refresh', 'key-word-cloud' ) . '</button>';
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
	 * 타원의 목표 가로세로 비를 읽는다.
	 *
	 * @param mixed $ratio 입력.
	 * @return float|null 0.5..5 의 수. 벗어나거나 수가 아니면 null.
	 */
	public static function sanitize_ratio( $ratio ) {
		$ratio = trim( (string) $ratio );
		if ( 1 !== preg_match( '/^\d+(\.\d+)?$/', $ratio ) ) {
			return null;
		}
		$value = (float) $ratio;
		if ( $value < 0.5 || $value > 5.0 ) {
			return null;
		}
		return $value;
	}

	/**
	 * 고를 수 있는 분야와 각 분야에 든 topic 수.
	 *
	 * 목록은 설정이 정하고 자료가 정하지 않는다. 자료에서 읽으면 아직 topic 이 하나도
	 * 들지 않은 분야가 화면에서 사라지고, 그 분야를 고를 수 없으니 topic 도 영영 안 는다.
	 * 설정이 비어 있을 때만 자료에 있는 이름을 쓴다.
	 *
	 * @return array 이름 => topic 수.
	 */
	public static function known_fields() {
		$counts  = KWC_Topics::fields();
		$options = self::options();
		$listed  = self::parse_fields( isset( $options['field_list'] ) ? $options['field_list'] : '' );
		if ( null === $listed || empty( $listed ) ) {
			return $counts;
		}
		$known = array();
		foreach ( $listed as $name ) {
			$known[ $name ] = isset( $counts[ $name ] ) ? $counts[ $name ] : 0;
		}
		// 목록에서 빠졌는데 topic 이 달고 있는 이름은 조용히 감추지 않는다.
		foreach ( $counts as $name => $count ) {
			if ( ! isset( $known[ $name ] ) ) {
				$known[ $name ] = $count;
			}
		}
		return $known;
	}

	/**
	 * 그릴 분야 목록을 읽는다.
	 *
	 * 빈 문자열과 `*` 는 둘 다 "모든 분야" 다. 값이 있으면 그 분야가 붙은 topic 만
	 * 그린다. topic 하나가 여러 분야에 속할 수 있으므로 하나라도 겹치면 통과한다.
	 *
	 * @param mixed $value 쉼표로 이은 분야 이름.
	 * @return array|null 정규화된 이름들. 모든 분야면 null.
	 */
	public static function parse_fields( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value || '*' === $value ) {
			return null;
		}
		$fields = array();
		foreach ( explode( ',', $value ) as $field ) {
			$field = KWC_Topics::normalize_field( $field );
			if ( '' !== $field && ! in_array( $field, $fields, true ) ) {
				$fields[] = $field;
			}
		}
		// 쉼표만 적은 값을 "모든 분야" 로 읽으면 고른 것과 반대가 나온다.
		return empty( $fields ) ? array() : $fields;
	}

	/**
	 * 손으로 적은 font-family 를 CSS 에 넣어도 되는 형태로 줄인다.
	 *
	 * 이 값은 style 속성으로 들어가므로 글꼴 이름에 쓰이는 글자만 남긴다.
	 * 중괄호나 세미콜론, 괄호가 들어오면 선언을 벗어날 수 있으므로 버린다.
	 *
	 * @param string $family 입력.
	 * @return string 쓸 수 있는 형태. 남는 것이 없으면 빈 문자열.
	 */
	public static function sanitize_font_family( $family ) {
		$family = trim( (string) $family );
		if ( '' === $family ) {
			return '';
		}
		// 글자, 숫자, 공백, 그리고 쉼표 하이픈 밑줄 따옴표만 남긴다.
		$clean = preg_replace( '/[^\p{L}\p{N} ,\'"_-]/u', '', $family );
		if ( null === $clean ) {
			error_log( '[key-word-cloud] preg_replace failed while cleaning a font family' );
			return '';
		}
		$clean = trim( preg_replace( '/\s+/u', ' ', $clean ) );
		if ( $clean !== $family ) {
			error_log( '[key-word-cloud] font family was trimmed to a safe form: ' . $family . ' -> ' . $clean );
		}
		return $clean;
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
		// 판 번호도 키에 넣는다. 구름에 그 번호가 적혀 있으므로 캐시가 남으면 옛 번호를
		// 보여 주게 되고, 새 판이 다르게 그리는 경우에도 옛 그림이 남는다.
		$key = self::TRANSIENT_PREFIX . md5(
			wp_json_encode( $args ) . '|' . ( current_user_can( 'edit_posts' ) ? 'editor' : 'guest' )
				. '|' . KWC_VERSION
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
			return '<p class="kwc-error">Key Word Cloud: '
				. esc_html__( 'no topics have been uploaded yet.', 'key-word-cloud' ) . '</p>';
		}

		$min_posts = (int) $args['min_posts'];
		$wanted    = self::parse_fields( $args['fields'] );
		$sources   = KWC_Sources::parse( $args['sources'] );
		$counted   = ( null === $sources ) ? array() : KWC_Sources::counts( $topics, $sources );
		$entries   = array();
		$drawn     = array();
		$too_few   = 0;
		$other_language = 0;
		$other_field    = 0;
		$not_found      = 0;
		foreach ( $topics as $topic ) {
			$posts = (int) $topic['posts'];
			// 자리를 골랐으면 파이프라인이 보낸 수를 믿지 않고 지금 글을 뒤져 다시 센다.
			// 그 뒤에 쓴 글이 반영되고, 지운 글은 빠진다.
			if ( null !== $sources ) {
				$label = (string) $topic['label'];
				$posts = isset( $counted[ $label ] ) ? (int) $counted[ $label ] : 0;
				if ( 0 === $posts ) {
					$not_found++;
					continue;
				}
			}
			if ( $posts < $min_posts ) {
				$too_few++;
				continue;
			}
			$text = KWC_Language::label_for( $topic, $args['language'] );
			if ( null === $text ) {
				$other_language++;
				continue;
			}
			// 서로 다른 두 topic 이 같은 이름으로 번역될 수 있다. 같은 낱말이 구름에 두 번
			// 나오면 고장으로 보이므로, 글 수가 큰 쪽만 남긴다. topic 을 합치지는 않는다.
			// 한 글이 두 topic 에 걸려 있으면 합계가 글 수보다 커지기 때문이다.
			$key = KWC_Topics::normalize_field( $text );
			if ( isset( $drawn[ $key ] ) ) {
				continue;
			}
			$drawn[ $key ] = true;
			if ( null !== $wanted
				&& empty( array_intersect( $wanted, (array) ( isset( $topic['fields'] ) ? $topic['fields'] : array() ) ) ) ) {
				$other_field++;
				continue;
			}
			$phrases   = isset( $topic['phrases'] ) ? (array) $topic['phrases'] : array();
			$entries[] = array(
				'text'  => $text,
				'posts' => $posts,
				'tip'   => sprintf(
					/* translators: 1: number of posts, 2: a colon and the phrases the topic was folded from */
					_n( '%1$d post%2$s', '%1$d posts%2$s', $posts, 'key-word-cloud' ),
					$posts,
					// 구절이 없으면 콜론도 없다. 뒤에 아무것도 없는 콜론은 문장이 잘린 것처럼 보인다.
					empty( $phrases ) ? '' : ":\n" . implode( ' · ', array_slice( $phrases, 0, 8 ) )
				),
			);
		}

		// 다시 센 뒤에는 차례가 달라진다. 큰 것부터 세우고 나서 자른다. 여기서 자르지 않고
		// 세는 도중에 자르면, 지금은 큰 topic 이 옛 차례에 밀려 빠진다.
		usort( $entries, function ( $a, $b ) {
			return $b['posts'] <=> $a['posts'];
		} );
		$entries = array_slice( $entries, 0, (int) $args['max_words'] );

		if ( empty( $entries ) ) {
			$why = sprintf(
				/* translators: 1: topics held, 2: how many came from too few posts, 3: that floor, 4: how many are in another language, 5: the chosen language */
				__( 'of %1$d topics, %2$d come from fewer than %3$d posts and %4$d are not %5$s.', 'key-word-cloud' ),
				count( $topics ), $too_few, $min_posts, $other_language, $args['language']
			);
			if ( null !== $sources ) {
				$why .= ' ' . sprintf(
					/* translators: 1: how many were not found, 2: the places that were searched */
					__( '%1$d are in none of the places searched (%2$s).', 'key-word-cloud' ),
					$not_found,
					implode( ', ', $sources )
				);
			}
			if ( null !== $wanted ) {
				$why .= ' ' . sprintf(
					/* translators: 1: how many are outside the chosen fields, 2: the chosen fields */
					__( '%1$d are outside the chosen fields (%2$s).', 'key-word-cloud' ),
					$other_field,
					empty( $wanted ) ? '-' : implode( ', ', $wanted )
				);
			}
			error_log( '[key-word-cloud] no topic survived: ' . $why );
			return '<p class="kwc-error">Key Word Cloud: '
				. esc_html__( 'nothing to draw — ', 'key-word-cloud' ) . esc_html( $why ) . '</p>';
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

		// 감춘 분류의 글은 세지 않았다. 낱말을 눌러 나오는 목록에서도 빼야 수와 목록이 맞는다.
		$hidden = array();
		foreach ( KWC_Sources::restricted() as $term ) {
			$hidden[] = '-' . (int) $term['term_id'];
		}

		// 큰 것부터 넘긴다. 타원일 때는 kwc.js 가 이 차례를 보고 가운데부터 채운다.
		$items = array();
		$index = 0;
		foreach ( $entries as $entry ) {
			$weight = ( $span > 0 ) ? ( ( sqrt( $entry['posts'] ) - sqrt( $min ) ) / $span ) : 1.0;
			$size   = $args['min_size'] + ( $args['max_size'] - $args['min_size'] ) * $weight;

			// 크기는 --kwc-scale 을 곱해서 낸다. 칸이 좁을 때 kwc.js 가 이 값을 낮춰
			// 글자를 줄이고, 그래야 한 줄에 더 담겨 타원이 세로로 길어지지 않는다.
			$class = 'kwc-word';
			if ( 'palette' === $args['color_mode'] ) {
				$class .= ' kwc-word--c' . ( $index % self::PALETTE_SIZE );
				$style  = sprintf( 'font-size:calc(%.1fpx * var(--kwc-scale, 1));', $size );
			} else {
				$style = sprintf(
					'font-size:calc(%.1fpx * var(--kwc-scale, 1));color:%s;',
					$size,
					self::mix_color( $args['color_start'], $args['color_end'], $weight )
				);
			}
			$index++;

			if ( 'search' === $args['link_mode'] ) {
				$query = array( 's' => $entry['text'] );
				if ( ! empty( $hidden ) ) {
					$query['cat'] = implode( ',', $hidden );
				}
				$items[] = sprintf(
					'<a class="%s" href="%s" style="%s" data-tip="%s" data-count="%d">%s</a>',
					esc_attr( $class ),
					esc_url( add_query_arg( $query, home_url( '/' ) ) ),
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

		$cloud_class = 'kwc-cloud';
		if ( 'ellipse' === $args['shape'] ) {
			$cloud_class .= ' kwc-cloud--ellipse';
		}

		$styles = array();
		if ( 'custom' === $args['font'] ) {
			$styles[] = 'font-family:' . $args['font_custom'];
		} elseif ( 'theme' !== $args['font'] ) {
			$cloud_class .= ' kwc-cloud--font-' . $args['font'];
		}

		// 폭을 px 로 주더라도 좁은 화면에서는 칸을 넘지 않게 100% 로 묶는다.
		if ( $args['width_px'] > 0 ) {
			$styles[] = sprintf( 'width:min(%dpx, 100%%)', $args['width_px'] );
			$styles[] = 'margin-inline:auto';
		}
		// 내용이 목표보다 짧아도 상자는 그 높이를 지키게 한다.
		if ( $args['height_px'] > 0 ) {
			$styles[] = sprintf( 'min-height:%dpx', $args['height_px'] );
		}

		// 크기 목표는 script 가 읽는다. 높이를 px 로 주면 그것이 목표가 되고 비는 쓰이지 않는다.
		$data = '';
		if ( 'ellipse' === $args['shape'] ) {
			$data = sprintf( ' data-ratio="%.2f"', $args['ratio'] );
			if ( $args['height_px'] > 0 ) {
				$data .= sprintf( ' data-height="%d"', $args['height_px'] );
			}
		}

		$style_attr = empty( $styles ) ? '' : ' style="' . esc_attr( implode( ';', $styles ) . ';' ) . '"';

		return '<div class="kwc">' . self::version_mark() . self::refresh_button()
			. '<div class="' . esc_attr( $cloud_class ) . '"' . $data . $style_attr . '>'
			. implode( "\n", $items ) . '</div></div>';
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
