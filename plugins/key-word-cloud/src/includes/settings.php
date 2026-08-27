<?php
/**
 * WP Admin 사이드바 메뉴와 설정 화면.
 *
 * @package KeyWordCloud
 */

defined( 'ABSPATH' ) || exit;

final class KWC_Settings {

	const GROUP = 'kwc_settings_group';
	const PAGE  = 'key-word-cloud';

	/**
	 * 훅 등록.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_post_kwc_flush_cache', array( __CLASS__, 'handle_flush_cache' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( KWC_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * 활성화 시 기본값을 심는다.
	 */
	public static function on_activate() {
		if ( false === get_option( KWC_OPTION, false ) ) {
			add_option( KWC_OPTION, KWC_Defaults::options() );
		}
	}

	/**
	 * 플러그인 목록의 Settings 링크.
	 *
	 * @param array $links 기존 링크.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">설정</a>' );
		return $links;
	}

	/**
	 * 사이드바 최상위 메뉴.
	 */
	public static function add_menu() {
		add_menu_page(
			'Key Word Cloud',
			'Key Word Cloud',
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render_page' ),
			'dashicons-tagcloud',
			81
		);
	}

	/**
	 * 설정 항목 등록.
	 */
	public static function register_settings() {
		register_setting(
			self::GROUP,
			KWC_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => KWC_Defaults::options(),
			)
		);

		$sections = array(
			'kwc_source' => '분석 대상',
			'kwc_filter' => '단어 걸러내기',
			'kwc_style'  => '크기와 색상',
			'kwc_cache'  => '캐시',
		);
		foreach ( $sections as $id => $title ) {
			add_settings_section( $id, $title, '__return_false', self::PAGE );
		}

		$fields = array(
			array( 'ranking', '단어 고르는 기준', 'kwc_source', 'field_ranking' ),
			array( 'source', '텍스트 소스', 'kwc_source', 'field_source' ),
			array( 'excerpt_fallback', '요약문이 비면 본문 사용', 'kwc_source', 'field_excerpt_fallback' ),
			array( 'post_types', '대상 post type', 'kwc_source', 'field_post_types' ),
			array( 'scan_limit', '읽을 글 수', 'kwc_source', 'field_scan_limit' ),
			array( 'stopwords', '불용어 (Stopwords)', 'kwc_filter', 'field_stopwords' ),
			array( 'kr_particles_on', '한국어 조사 분리', 'kwc_filter', 'field_kr_particles_on' ),
			array( 'kr_min_stem', '조사 분리 후 최소 어간 길이', 'kwc_filter', 'field_kr_min_stem' ),
			array( 'kr_particles', '조사 목록', 'kwc_filter', 'field_kr_particles' ),
			array( 'min_len', '최소 단어 길이', 'kwc_filter', 'field_min_len' ),
			array( 'min_count', '최소 등장 횟수', 'kwc_filter', 'field_min_count' ),
			array( 'min_docs_pct', 'TF-IDF 최소 문서 비율 (%)', 'kwc_filter', 'field_min_docs_pct' ),
			array( 'max_words', '최대 단어 수', 'kwc_filter', 'field_max_words' ),
			array( 'size', '글자 크기 (px)', 'kwc_style', 'field_size' ),
			array( 'color', '색상 (적음 → 많음)', 'kwc_style', 'field_color' ),
			array( 'link_mode', '단어 클릭 동작', 'kwc_style', 'field_link_mode' ),
			array( 'cache_ttl', '캐시 유지 시간 (초)', 'kwc_cache', 'field_cache_ttl' ),
		);
		foreach ( $fields as $field ) {
			add_settings_field( 'kwc_' . $field[0], $field[1], array( __CLASS__, $field[3] ), self::PAGE, $field[2] );
		}
	}

	/**
	 * 저장 전 검증. 값이 틀리면 고쳐 넣지 않고 에러를 띄우고 이전 값을 유지한다.
	 *
	 * @param mixed $input 폼 입력.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$current = KWC_Cloud::options();

		if ( ! is_array( $input ) ) {
			add_settings_error( KWC_OPTION, 'kwc_bad_input', '설정 입력이 배열이 아니다. 저장하지 않았다.' );
			error_log( '[key-word-cloud] settings input was not an array' );
			return $current;
		}

		$out    = $current;
		$errors = array();

		$ranking = isset( $input['ranking'] ) ? (string) $input['ranking'] : '';
		if ( in_array( $ranking, array( 'tfidf', 'count' ), true ) ) {
			$out['ranking'] = $ranking;
		} else {
			$errors[] = '단어 고르는 기준 값이 잘못됐다: ' . $ranking;
		}

		$source = isset( $input['source'] ) ? (string) $input['source'] : '';
		if ( in_array( $source, array( 'content', 'excerpt' ), true ) ) {
			$out['source'] = $source;
		} else {
			$errors[] = '텍스트 소스 값이 잘못됐다: ' . $source;
		}

		$link = isset( $input['link_mode'] ) ? (string) $input['link_mode'] : '';
		if ( in_array( $link, array( 'search', 'none' ), true ) ) {
			$out['link_mode'] = $link;
		} else {
			$errors[] = '단어 클릭 동작 값이 잘못됐다: ' . $link;
		}

		$out['excerpt_fallback'] = empty( $input['excerpt_fallback'] ) ? 0 : 1;
		$out['kr_particles_on']  = empty( $input['kr_particles_on'] ) ? 0 : 1;

		$registered = get_post_types( array( 'public' => true ), 'names' );
		$types      = array();
		foreach ( (array) ( isset( $input['post_types'] ) ? $input['post_types'] : array() ) as $type ) {
			$type = (string) $type;
			if ( isset( $registered[ $type ] ) ) {
				$types[] = $type;
			} else {
				$errors[] = '알 수 없는 post type: ' . $type;
			}
		}
		if ( empty( $types ) ) {
			$errors[] = 'post type 을 하나 이상 선택해야 한다. 이전 값을 유지한다.';
		} else {
			$out['post_types'] = array_values( array_unique( $types ) );
		}

		$ints = array(
			'scan_limit'  => array( 1, 5000 ),
			'max_words'   => array( 1, 500 ),
			'min_count'    => array( 1, 1000 ),
			'min_docs_pct' => array( 0, 100 ),
			'min_len'     => array( 1, 20 ),
			'min_size'    => array( 6, 200 ),
			'max_size'    => array( 6, 200 ),
			'kr_min_stem' => array( 1, 6 ),
			'cache_ttl'   => array( 0, 604800 ),
		);
		foreach ( $ints as $name => $range ) {
			$raw = isset( $input[ $name ] ) ? trim( (string) $input[ $name ] ) : '';
			if ( 1 !== preg_match( '/^\d+$/', $raw ) ) {
				$errors[] = $name . ' 은 정수여야 한다: ' . $raw;
				continue;
			}
			$value = (int) $raw;
			if ( $value < $range[0] || $value > $range[1] ) {
				$errors[] = sprintf( '%s 는 %d..%d 범위여야 한다: %d', $name, $range[0], $range[1], $value );
				continue;
			}
			$out[ $name ] = $value;
		}
		if ( $out['min_size'] >= $out['max_size'] ) {
			$errors[]         = sprintf( '최소 크기(%d)는 최대 크기(%d)보다 작아야 한다. 크기는 이전 값을 유지한다.', $out['min_size'], $out['max_size'] );
			$out['min_size']  = $current['min_size'];
			$out['max_size']  = $current['max_size'];
		}

		foreach ( array( 'color_start', 'color_end' ) as $name ) {
			$color = KWC_Cloud::sanitize_hex( isset( $input[ $name ] ) ? $input[ $name ] : '' );
			if ( null === $color ) {
				$errors[] = $name . ' 은 #rrggbb 형식이어야 한다.';
				continue;
			}
			$out[ $name ] = $color;
		}

		foreach ( array( 'stopwords', 'kr_particles' ) as $name ) {
			$out[ $name ] = isset( $input[ $name ] ) ? sanitize_textarea_field( (string) $input[ $name ] ) : '';
		}
		if ( $out['kr_particles_on'] && array() === KWC_Defaults::to_list( $out['kr_particles'] ) ) {
			$errors[] = '조사 분리를 켰는데 조사 목록이 비었다.';
		}

		// 설정이 바뀌면 캐시된 구름은 낡은 것이다.
		$out['cache_salt'] = (int) $current['cache_salt'] + 1;

		foreach ( $errors as $i => $message ) {
			add_settings_error( KWC_OPTION, 'kwc_err_' . $i, $message );
			error_log( '[key-word-cloud] settings rejected: ' . $message );
		}

		return $out;
	}

	/**
	 * 캐시 비우기 처리.
	 */
	public static function handle_flush_cache() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '권한이 없다.', 403 );
		}
		check_admin_referer( 'kwc_flush_cache' );
		KWC_Cloud::flush_cache();
		wp_safe_redirect( add_query_arg( 'kwc_flushed', '1', admin_url( 'admin.php?page=' . self::PAGE ) ) );
		exit;
	}

	/**
	 * 현재 설정값 하나를 읽는다.
	 *
	 * @param string $key 키.
	 * @return mixed
	 */
	private static function value( $key ) {
		$options = KWC_Cloud::options();
		return isset( $options[ $key ] ) ? $options[ $key ] : null;
	}

	/**
	 * name 속성 문자열.
	 *
	 * @param string $key 키.
	 * @return string
	 */
	private static function name( $key ) {
		return KWC_OPTION . '[' . $key . ']';
	}

	/**
	 * 숫자 입력 필드 출력.
	 *
	 * @param string $key  키.
	 * @param int    $min  최솟값.
	 * @param int    $max  최댓값.
	 * @param string $help 설명.
	 */
	private static function number_field( $key, $min, $max, $help = '' ) {
		printf(
			'<input type="number" name="%s" value="%s" min="%d" max="%d" step="1" class="small-text">',
			esc_attr( self::name( $key ) ),
			esc_attr( (string) self::value( $key ) ),
			(int) $min,
			(int) $max
		);
		if ( '' !== $help ) {
			echo ' <span class="description">' . esc_html( $help ) . '</span>';
		}
	}

	// --- 필드 렌더러 ---------------------------------------------------------

	public static function field_ranking() {
		$current = self::value( 'ranking' );
		$labels  = array(
			'tfidf' => 'TF-IDF — 그 글들을 특징짓는 단어',
			'count' => '등장 횟수 — 많이 나온 단어',
		);
		foreach ( $labels as $key => $label ) {
			printf(
				'<label style="margin-right:16px"><input type="radio" name="%s" value="%s" %s> %s</label>',
				esc_attr( self::name( 'ranking' ) ),
				esc_attr( $key ),
				checked( $current, $key, false ),
				esc_html( $label )
			);
		}
		echo '<p class="description">TF-IDF 는 모든 글에 두루 나오는 흔한 말의 점수를 낮추고, 몇몇 글에 몰려 나오는 단어를 올린다. 글자 크기는 이 점수를 따르고, 마우스를 올리면 실제 등장 횟수가 보인다.</p>';
	}

	public static function field_source() {
		$current = self::value( 'source' );
		foreach ( array( 'content' => '본문 (Content)', 'excerpt' => '요약문 (Excerpt)' ) as $key => $label ) {
			printf(
				'<label style="margin-right:16px"><input type="radio" name="%s" value="%s" %s> %s</label>',
				esc_attr( self::name( 'source' ) ),
				esc_attr( $key ),
				checked( $current, $key, false ),
				esc_html( $label )
			);
		}
	}

	public static function field_excerpt_fallback() {
		printf(
			'<label><input type="checkbox" name="%s" value="1" %s> 요약문이 비어 있으면 본문으로 대체한다</label>',
			esc_attr( self::name( 'excerpt_fallback' ) ),
			checked( 1, (int) self::value( 'excerpt_fallback' ), false )
		);
		echo '<p class="description">꺼두면 요약문이 빈 글은 그냥 건너뛴다. 건너뛴 개수는 PHP error log 에 남는다.</p>';
	}

	public static function field_post_types() {
		$selected = (array) self::value( 'post_types' );
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			printf(
				'<label style="margin-right:16px"><input type="checkbox" name="%s[]" value="%s" %s> %s</label>',
				esc_attr( self::name( 'post_types' ) ),
				esc_attr( $type->name ),
				checked( in_array( $type->name, $selected, true ), true, false ),
				esc_html( $type->labels->singular_name . ' (' . $type->name . ')' )
			);
		}
	}

	public static function field_scan_limit() {
		self::number_field( 'scan_limit', 1, 5000, '최신 글부터 이만큼만 읽는다. 크게 잡으면 첫 생성이 느려진다.' );
	}

	public static function field_stopwords() {
		printf(
			'<textarea name="%s" rows="8" class="large-text code">%s</textarea>',
			esc_attr( self::name( 'stopwords' ) ),
			esc_textarea( (string) self::value( 'stopwords' ) )
		);
		echo '<p class="description">한 줄에 하나, 또는 쉼표로 구분한다. 조사를 뗀 뒤에도 한 번 더 대조한다.</p>';
	}

	public static function field_kr_particles_on() {
		printf(
			'<label><input type="checkbox" name="%s" value="1" %s> 단어 끝의 조사를 떼어낸다</label>',
			esc_attr( self::name( 'kr_particles_on' ) ),
			checked( 1, (int) self::value( 'kr_particles_on' ), false )
		);
		echo '<p class="description">형태소 분석기가 아니라 규칙 기반이다. \'고양이\' → \'고양\' 같은 오분리가 나면 최소 어간 길이를 올리거나 해당 조사를 목록에서 빼라.</p>';
	}

	public static function field_kr_min_stem() {
		self::number_field( 'kr_min_stem', 1, 6, '조사를 떼고 남는 글자가 이보다 짧아지면 떼지 않는다.' );
	}

	public static function field_kr_particles() {
		printf(
			'<textarea name="%s" rows="6" class="large-text code">%s</textarea>',
			esc_attr( self::name( 'kr_particles' ) ),
			esc_textarea( (string) self::value( 'kr_particles' ) )
		);
		echo '<p class="description">긴 조사가 먼저 매칭된다. 최대 2회까지 반복해서 뗀다 (예: 학교에서는 → 학교).</p>';
	}

	public static function field_min_len() {
		self::number_field( 'min_len', 1, 20, '글자 수 기준.' );
	}

	public static function field_min_count() {
		self::number_field( 'min_count', 1, 1000, '이보다 적게 나온 단어는 버린다.' );
	}

	public static function field_min_docs_pct() {
		self::number_field( 'min_docs_pct', 0, 100, '읽은 글의 이 비율 이상에 나온 단어만 TF-IDF 후보가 된다. 0 이면 제한 없음. 낮추면 한 글에만 있는 오탈자가 올라오고, 높이면 흔한 말만 남는다.' );
	}

	public static function field_max_words() {
		self::number_field( 'max_words', 1, 500, '빈도 상위 N 개만 그린다.' );
	}

	public static function field_size() {
		echo '최소 ';
		self::number_field( 'min_size', 6, 200 );
		echo ' &nbsp; 최대 ';
		self::number_field( 'max_size', 6, 200 );
		echo '<p class="description">빈도에 sqrt 스케일을 적용해 두 값 사이로 배분한다.</p>';
	}

	public static function field_color() {
		foreach ( array( 'color_start' => '적게 나온 단어', 'color_end' => '많이 나온 단어' ) as $key => $label ) {
			printf(
				'<label style="margin-right:16px">%s <input type="color" name="%s" value="%s"></label>',
				esc_html( $label ),
				esc_attr( self::name( $key ) ),
				esc_attr( (string) self::value( $key ) )
			);
		}
	}

	public static function field_link_mode() {
		$current = self::value( 'link_mode' );
		printf( '<select name="%s">', esc_attr( self::name( 'link_mode' ) ) );
		foreach ( array( 'search' => '그 단어로 검색한 글 목록 열기', 'none' => '링크 없음' ) as $key => $label ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $key ), selected( $current, $key, false ), esc_html( $label ) );
		}
		echo '</select>';
	}

	public static function field_cache_ttl() {
		self::number_field( 'cache_ttl', 0, 604800, '0 이면 캐시하지 않는다. 설정을 저장하면 캐시는 자동으로 무효화된다.' );
	}

	// --- 화면 ---------------------------------------------------------------

	/**
	 * 설정 페이지.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '권한이 없다.', 403 );
		}

		if ( isset( $_GET['kwc_flushed'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>캐시를 비웠다.</p></div>';
		}

		// 최상위 메뉴 페이지는 검증 오류가 자동으로 뜨지 않는다. 직접 띄운다.
		settings_errors();
		?>
		<div class="wrap">
			<h1>Key Word Cloud</h1>
			<p>숏코드: <code>[wpwordcloud]</code> — 속성으로 아래 설정을 개별 페이지에서 덮어쓸 수 있다.<br>
				예: <code>[wpwordcloud source="excerpt" max="40" min_count="2" color_end="#b3202e"]</code></p>

			<form method="post" action="options.php">
				<?php
				settings_fields( self::GROUP );
				do_settings_sections( self::PAGE );
				submit_button();
				?>
			</form>

			<hr>
			<h2>캐시</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="kwc_flush_cache">
				<?php wp_nonce_field( 'kwc_flush_cache' ); ?>
				<?php submit_button( '캐시 비우기', 'secondary', 'submit', false ); ?>
			</form>

			<hr>
			<h2>미리보기</h2>
			<?php
			// 현재 설정 그대로 그려 본다. 출력은 KWC_Cloud::render 안에서 전부 이스케이프된다.
			// wp_kses_post 를 걸면 인라인 크기/색상이 지워져 미리보기가 실물과 달라진다.
			echo do_shortcode( '[wpwordcloud]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			?>
		</div>
		<?php
	}
}
