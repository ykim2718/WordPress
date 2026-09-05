<?php
/**
 * WP Admin 사이드바 메뉴와 설정 화면.
 *
 * 여기서 정한 값이 숏코드와 블록의 기본값이 된다. 숏코드 속성과 블록의 사이드바가
 * 그것을 개별 페이지에서 덮어쓴다.
 *
 * @package GreenGrass
 */

defined( 'ABSPATH' ) || exit;

final class GG_Settings {

	const GROUP = 'gg_settings_group';
	const PAGE  = 'green-grass';

	/**
	 * 훅 등록.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_head', array( __CLASS__, 'admin_icon_style' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_post_gg_flush_cache', array( __CLASS__, 'handle_flush_cache' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( GG_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * 활성화 시 기본값을 심는다.
	 */
	public static function on_activate() {
		if ( false === get_option( GG_OPTION, false ) ) {
			add_option( GG_OPTION, GG_Defaults::options() );
		}
	}

	/**
	 * 플러그인 목록의 설정 링크.
	 *
	 * @param array $links 기존 링크.
	 * @return array
	 */
	public static function action_links( $links ) {
		if ( ! is_array( $links ) ) {
			return $links;
		}
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">설정</a>' );
		return $links;
	}

	/**
	 * 사이드바에 쓸 아이콘. 주소로 준다.
	 *
	 * data URI 로 주면 `wp-admin/js/svg-painter.js` 가 base64 SVG 를 받아 fill 을 관리자
	 * 색상 하나로 덮어 칠한다. 다섯 층의 초록이 한 색이 되어 잔디가 사각형 하나로 보인다.
	 * 주소로 주면 `<img>` 로 걸리고 그 script 는 손대지 않는다.
	 *
	 * @return string
	 */
	private static function menu_icon() {
		if ( ! is_readable( GG_DIR . 'assets/icon.svg' ) ) {
			error_log( '[green-grass] assets/icon.svg is missing or unreadable' );
			return 'dashicons-calendar-alt';
		}
		return GG_URL . 'assets/icon.svg';
	}

	/**
	 * 메뉴 아이콘의 흐림을 걷는다. 관리 화면은 `<img>` 아이콘을 opacity 0.6 으로 눌러 둔다.
	 */
	public static function admin_icon_style() {
		echo '<style>#adminmenu #toplevel_page_' . esc_html( self::PAGE )
			. ' .wp-menu-image img { opacity: 1; padding-top: 7px; }</style>' . "\n";
	}

	/**
	 * 사이드바 최상위 메뉴.
	 */
	public static function add_menu() {
		add_menu_page(
			'Green Grass',
			'Green Grass',
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render_page' ),
			self::menu_icon(),
			82
		);
	}

	/**
	 * 설정 항목 등록.
	 */
	public static function register_settings() {
		register_setting(
			self::GROUP,
			GG_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => GG_Defaults::options(),
			)
		);

		$sections = array(
			'gg_what'  => '무엇을 셀까',
			'gg_when'  => '언제부터 언제까지',
			'gg_look'  => '모양과 색',
			'gg_show'  => '함께 보일 것',
			'gg_cache' => '캐시',
		);
		foreach ( $sections as $id => $title ) {
			add_settings_section( $id, $title, '__return_false', self::PAGE );
		}

		$fields = array(
			array( 'source', '원본', 'gg_what' ),
			array( 'post_types', '셀 글 종류', 'gg_what' ),
			array( 'gh_user', 'GitHub 계정', 'gg_what' ),
			array( 'period', '구간을 정하는 법', 'gg_when' ),
			array( 'months', '거슬러 셀 달 수', 'gg_when' ),
			array( 'dates', '날짜로 정하기', 'gg_when' ),
			array( 'week_start', '한 주의 첫날', 'gg_when' ),
			array( 'orientation', '배치', 'gg_look' ),
			array( 'cell', '칸 크기 (px)', 'gg_look' ),
			array( 'palette', '색', 'gg_look' ),
			array( 'scale', '짙기를 나누는 법', 'gg_look' ),
			array( 'show', '곁들일 것', 'gg_show' ),
			array( 'link_mode', '칸을 눌렀을 때', 'gg_show' ),
			array( 'cache_ttl', '캐시 유지 시간 (초)', 'gg_cache' ),
		);
		foreach ( $fields as $field ) {
			add_settings_field( 'gg_' . $field[0], $field[1], array( __CLASS__, 'field_' . $field[0] ), self::PAGE, $field[2] );
		}
	}

	// --- 저장 ----------------------------------------------------------------

	/**
	 * 저장 전 검증. 값이 틀리면 고쳐 넣지 않고 에러를 띄우고 이전 값을 유지한다.
	 *
	 * @param mixed $input 폼 입력.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$current = GG_Calendar::options();

		if ( ! is_array( $input ) ) {
			add_settings_error( GG_OPTION, 'gg_bad_input', '설정 입력이 배열이 아니다. 저장하지 않았다.' );
			error_log( '[green-grass] settings input was not an array' );
			return $current;
		}

		$out    = $current;
		$errors = array();

		$words = array(
			'source'      => GG_Source::ALL,
			'orientation' => array( 'horizontal', 'vertical' ),
			'period'      => array( 'months', 'dates' ),
			'week_start'  => array( 'sunday', 'monday' ),
			'palette'     => array( 'github', 'custom' ),
			'scale'       => array( 'quantile', 'linear' ),
			'link_mode'   => array( 'archive', 'none' ),
		);
		foreach ( $words as $key => $allowed ) {
			$value = isset( $input[ $key ] ) ? strtolower( trim( (string) $input[ $key ] ) ) : '';
			if ( in_array( $value, $allowed, true ) ) {
				$out[ $key ] = $value;
			} else {
				$errors[] = $key . ' 값이 잘못됐다: ' . $value;
			}
		}

		$numbers = array(
			'months'    => array( 1, 120 ),
			'cell'      => array( GG_Calendar::MIN_CELL, GG_Calendar::MAX_CELL ),
			'gap'       => array( 0, 12 ),
			'radius'    => array( 0, 20 ),
			'cache_ttl' => array( 0, YEAR_IN_SECONDS ),
		);
		foreach ( $numbers as $key => $bounds ) {
			$raw = isset( $input[ $key ] ) ? trim( (string) $input[ $key ] ) : '';
			if ( 1 !== preg_match( '/^\d+$/', $raw ) ) {
				$errors[] = $key . ' 는 0 이상의 정수여야 한다: ' . $raw;
				continue;
			}
			$value = (int) $raw;
			if ( $value < $bounds[0] || $value > $bounds[1] ) {
				$errors[] = sprintf( '%s 는 %d 과 %d 사이여야 한다: %d', $key, $bounds[0], $bounds[1], $value );
				continue;
			}
			$out[ $key ] = $value;
		}

		// 체크박스는 켠 것만 온다. key 가 없으면 껐다는 뜻이다.
		foreach ( array( 'show_months', 'show_days', 'show_legend', 'show_total' ) as $key ) {
			$out[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
		}

		foreach ( array( 'color', 'empty_color' ) as $key ) {
			$value = isset( $input[ $key ] ) ? trim( (string) $input[ $key ] ) : '';
			if ( GG_Palette::is_color( $value ) ) {
				$out[ $key ] = GG_Palette::normalize( $value );
			} else {
				$errors[] = $key . ' 는 #rrggbb 여야 한다: ' . $value;
			}
		}

		foreach ( array( 'from', 'to' ) as $key ) {
			$value = isset( $input[ $key ] ) ? trim( (string) $input[ $key ] ) : '';
			if ( '' !== $value && null === GG_Range::parse( $value ) ) {
				$errors[] = $key . ' 는 YYYY-MM-DD 형식의 실재하는 날이어야 한다: ' . $value;
				continue;
			}
			$out[ $key ] = $value;
		}

		$login = isset( $input['gh_user'] ) ? trim( (string) $input['gh_user'] ) : '';
		if ( '' !== $login && ! GG_GitHub::is_login( $login ) ) {
			$errors[] = 'GitHub 계정 이름이 아니다: ' . $login;
		} else {
			$out['gh_user'] = $login;
		}

		$types = array();
		foreach ( (array) ( isset( $input['post_types'] ) ? $input['post_types'] : array() ) as $type ) {
			$type = sanitize_key( trim( (string) $type ) );
			if ( '' !== $type && ! in_array( $type, $types, true ) ) {
				$types[] = $type;
			}
		}
		$out['post_types'] = implode( ',', GG_Source::parse_post_types( implode( ',', $types ) ) );

		// 고른 것과 필요한 것이 어긋나면 잔디가 통째로 오류가 된다. 저장하기 전에 잡는다.
		if ( 'github' === $out['source'] && '' === $out['gh_user'] ) {
			$errors[] = 'GitHub 을 세려면 계정 이름을 적어야 한다.';
		}
		if ( 'posts' === $out['source'] && '' === $out['post_types'] ) {
			$errors[] = '글을 세려면 글 종류를 하나 이상 골라야 한다.';
		}
		if ( 'dates' === $out['period'] && '' === $out['from'] ) {
			$errors[] = '날짜로 구간을 정하려면 시작일을 적어야 한다.';
		}

		if ( ! empty( $errors ) ) {
			foreach ( $errors as $i => $message ) {
				add_settings_error( GG_OPTION, 'gg_bad_' . $i, $message );
			}
			error_log( '[green-grass] settings rejected: ' . implode( ' / ', $errors ) );
			return $current;
		}

		// 값이 바뀌면 세어 둔 것도 낡는다.
		GG_Calendar::flush_cache();
		return $out;
	}

	/**
	 * 캐시 비우기 단추.
	 */
	public static function handle_flush_cache() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '권한이 없다.', 403 );
		}
		check_admin_referer( 'gg_flush_cache' );
		GG_Calendar::flush_cache();
		wp_safe_redirect( add_query_arg( 'gg_flushed', '1', admin_url( 'admin.php?page=' . self::PAGE ) ) );
		exit;
	}

	// --- 필드 ----------------------------------------------------------------

	/**
	 * 폼 이름.
	 *
	 * @param string $key 키.
	 * @return string
	 */
	private static function name( $key ) {
		return GG_OPTION . '[' . $key . ']';
	}

	/**
	 * 지금 값.
	 *
	 * @param string $key 키.
	 * @return mixed
	 */
	private static function value( $key ) {
		$options = GG_Calendar::options();
		return isset( $options[ $key ] ) ? $options[ $key ] : '';
	}

	/**
	 * 라디오 묶음.
	 *
	 * @param string $key    키.
	 * @param array  $labels value => label.
	 * @param string $help   설명.
	 */
	private static function radio_field( $key, array $labels, $help = '' ) {
		$current = self::value( $key );
		foreach ( $labels as $value => $label ) {
			printf(
				'<label style="margin-right:16px"><input type="radio" name="%s" value="%s" %s> %s</label>',
				esc_attr( self::name( $key ) ),
				esc_attr( $value ),
				checked( $current, $value, false ),
				esc_html( $label )
			);
		}
		if ( '' !== $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
	}

	/**
	 * 숫자 칸.
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

	/**
	 * 체크박스 하나.
	 *
	 * @param string $key   키.
	 * @param string $label 이름.
	 */
	private static function checkbox_field( $key, $label ) {
		printf(
			'<label style="display:inline-block;margin:0 16px 4px 0"><input type="checkbox" name="%s" value="1" %s> %s</label>',
			esc_attr( self::name( $key ) ),
			checked( (int) self::value( $key ), 1, false ),
			esc_html( $label )
		);
	}

	public static function field_source() {
		$labels = array();
		foreach ( GG_Source::ALL as $name ) {
			$labels[ $name ] = GG_Source::label( $name );
		}
		self::radio_field( 'source', $labels, '무엇을 세든 그리는 방식은 같다. 날짜별 건수 하나면 되기 때문이다.' );
	}

	public static function field_post_types() {
		$chosen = GG_Source::parse_post_types( self::value( 'post_types' ) );
		foreach ( GG_Source::post_types() as $name => $label ) {
			printf(
				'<label style="display:inline-block;margin:0 16px 4px 0"><input type="checkbox" name="%s[]" value="%s" %s> %s</label>',
				esc_attr( self::name( 'post_types' ) ),
				esc_attr( $name ),
				checked( in_array( $name, $chosen, true ), true, false ),
				esc_html( $label )
			);
		}
		echo '<p class="description">원본이 “이 사이트의 글” 일 때만 쓴다. 발행한 것만 세고 초안과 비공개는 세지 않는다.</p>';
	}

	public static function field_gh_user() {
		printf(
			'<input type="text" name="%s" value="%s" class="regular-text" placeholder="ykim2718" autocomplete="off">',
			esc_attr( self::name( 'gh_user' ) ),
			esc_attr( (string) self::value( 'gh_user' ) )
		);
		echo '<p class="description">원본이 GitHub 일 때만 쓴다. 프로필이 공개인 계정이어야 하고, 토큰은 필요 없다. '
			. 'GitHub 이 프로필 화면을 그릴 때 쓰는 공개 주소를 그대로 읽기 때문에, 그쪽 화면 구조가 바뀌면 잠시 멈출 수 있다.</p>';
	}

	public static function field_period() {
		self::radio_field(
			'period',
			array( 'months' => '오늘로부터 거슬러', 'dates' => '적어 둔 날짜로' ),
			'거슬러 세면 잔디가 날마다 한 칸씩 움직이고, 날짜로 정하면 그 구간에 멈춘다.'
		);
	}

	public static function field_months() {
		self::number_field( 'months', 1, 120, '12 면 오늘까지 꼭 한 해다. 위에서 “오늘로부터 거슬러” 를 골랐을 때만 쓴다.' );
	}

	public static function field_dates() {
		foreach ( array( 'from' => '시작', 'to' => '끝' ) as $key => $label ) {
			printf(
				'<label style="margin-right:16px">%s <input type="date" name="%s" value="%s"></label>',
				esc_html( $label ),
				esc_attr( self::name( $key ) ),
				esc_attr( (string) self::value( $key ) )
			);
		}
		echo '<p class="description">위에서 “적어 둔 날짜로” 를 골랐을 때만 쓴다. 끝을 비우면 오늘까지다. '
			. '양끝을 모두 포함하며, 한 번에 ' . (int) GG_Range::MAX_DAYS . '일까지 그린다.</p>';
	}

	public static function field_week_start() {
		self::radio_field( 'week_start', array( 'sunday' => '일요일', 'monday' => '월요일' ), '첫 줄에 올 요일. 요일 이름은 월·수·금만 적는다.' );
	}

	public static function field_orientation() {
		self::radio_field(
			'orientation',
			array( 'horizontal' => '가로 (주가 오른쪽으로)', 'vertical' => '세로 (주가 아래로)' ),
			'가로는 한 해가 800px 쯤 되어 본문 폭을 다 쓴다. 세로는 좁은 자리나 사이드바에 맞는다.'
		);
	}

	public static function field_cell() {
		self::number_field( 'cell', GG_Calendar::MIN_CELL, GG_Calendar::MAX_CELL, '칸 한 변' );
		echo ' &nbsp; ';
		self::number_field( 'gap', 0, 12, '칸 사이' );
		echo ' &nbsp; ';
		self::number_field( 'radius', 0, 20, '모서리' );
		echo '<p class="description">칸이 커지면 요일과 달 이름도 따라 커진다.</p>';
	}

	public static function field_palette() {
		self::radio_field( 'palette', array( 'github' => 'GitHub 의 초록', 'custom' => '고른 색' ) );
		printf(
			'<p><label>가장 짙은 칸 <input type="color" name="%s" value="%s"></label> &nbsp; '
				. '<label>빈 칸 <input type="color" name="%s" value="%s"></label></p>',
			esc_attr( self::name( 'color' ) ),
			esc_attr( (string) self::value( 'color' ) ),
			esc_attr( self::name( 'empty_color' ) ),
			esc_attr( (string) self::value( 'empty_color' ) )
		);
		echo '<p class="description">“고른 색” 은 가장 짙은 칸에서 색상과 채도를 두고 밝기만 올려 네 층을 만든다. '
			. '빈 칸의 색은 두 경우 모두 쓴다.</p>';
	}

	public static function field_scale() {
		self::radio_field(
			'scale',
			array( 'quantile' => '나온 건수를 사분위로', 'linear' => '가장 많은 날을 4 로 두고 고르게' ),
			'하루만 유난히 많은 날이 있으면 고르게 나눈 쪽은 나머지가 전부 옅어진다. 사분위는 그런 날에 흔들리지 않는다.'
		);
	}

	public static function field_show() {
		self::checkbox_field( 'show_months', '달 이름' );
		self::checkbox_field( 'show_days', '요일 이름' );
		self::checkbox_field( 'show_legend', 'Less–More 눈금' );
		self::checkbox_field( 'show_total', '구간과 합계 한 줄' );
	}

	public static function field_link_mode() {
		self::radio_field(
			'link_mode',
			array( 'archive' => '그 날의 목록을 연다', 'none' => '아무 데도 가지 않는다' ),
			'글을 세고 있으면 그 날의 아카이브로, GitHub 을 세고 있으면 그 날의 프로필로 간다. '
				. '댓글에는 날짜로 열어 볼 자리가 없어 늘 링크가 없다. 아무것도 없는 날에도 링크는 걸지 않는다.'
		);
	}

	public static function field_cache_ttl() {
		self::number_field( 'cache_ttl', 0, YEAR_IN_SECONDS, '0 이면 캐시하지 않는다. GitHub 을 셀 때만 쓴다.' );
	}

	// --- 화면 ----------------------------------------------------------------

	/**
	 * 설정 화면.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '권한이 없다.', 403 );
		}

		if ( isset( $_GET['gg_flushed'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>캐시를 비웠다.</p></div>';
		}

		// 최상위 메뉴 페이지는 검증 오류가 자동으로 뜨지 않는다. 직접 띄운다.
		settings_errors();
		?>
		<div class="wrap">
			<h1 style="display:flex;align-items:center;gap:14px;margin-bottom:.4em">
				<img src="<?php echo esc_url( GG_URL . 'assets/icon.svg' ); ?>" alt=""
					width="56" height="56" style="flex:0 0 56px">
				<span>Green Grass</span>
			</h1>

			<p>하루를 칸 하나로 두고, 그 날 몇 건이었는지를 칸의 짙기로 그린다. GitHub 프로필의
				그 잔디다. 세는 것은 이 사이트의 글이거나 댓글이거나, 아니면 GitHub 계정의
				contribution 이고, 무엇을 세든 그리는 방법은 같다.</p>

			<p>짙기는 다섯 층이다. 0 은 하루도 없는 날이고 나머지 넷은 그 구간에 나온 건수를
				나눈 것이라, 절대적인 수가 아니라 <strong>그 사람의 평소에 견준 많고 적음</strong>이다.
				같은 두 건이라도 뜸한 해에는 짙고 바쁜 해에는 옅다.</p>

			<h2>버전</h2>
			<p>이 사이트에서 도는 판은 <strong><?php echo esc_html( GG_VERSION ); ?></strong> 이다.
				새 판은 플러그인 화면에 알림으로 뜬다.</p>

			<h2>숏코드</h2>
			<p><code>[green_grass]</code> — 속성으로 아래 설정을 개별 페이지에서 덮어쓸 수 있다.</p>
			<table class="widefat striped" style="max-width:56em">
				<thead><tr><th>예</th><th>무엇이 되는가</th></tr></thead>
				<tbody>
					<tr><td><code>[green_grass]</code></td><td>설정 그대로</td></tr>
					<tr><td><code>[green_grass orientation="vertical"]</code></td><td>주가 아래로 흐른다</td></tr>
					<tr><td><code>[green_grass from="2026-01-01" to="2026-06-30"]</code></td><td>그 구간만. <code>period</code> 는 적지 않아도 된다</td></tr>
					<tr><td><code>[green_grass source="github" user="ykim2718"]</code></td><td>그 계정의 contribution</td></tr>
					<tr><td><code>[green_grass cell="16" palette="custom" color="#1f4e79"]</code></td><td>큰 칸에 파랑</td></tr>
				</tbody>
			</table>
			<p class="description">블록 삽입기의 <strong>yRocket</strong> 묶음에 있는
				<strong>Green Grass</strong> 블록도 같은 것을 그리고, 오른쪽 사이드바에 같은 항목이 있다.</p>

			<form method="post" action="options.php">
				<?php
				settings_fields( self::GROUP );
				do_settings_sections( self::PAGE );
				submit_button();
				?>
			</form>

			<hr>
			<h2>지금 하기</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="gg_flush_cache">
				<?php wp_nonce_field( 'gg_flush_cache' ); ?>
				<?php submit_button( '캐시 비우기', 'secondary', 'submit', false ); ?>
			</form>

			<hr>
			<h2>미리보기</h2>
			<?php
			// 지금 설정 그대로 그려 본다. 출력은 GG_Calendar::render 안에서 전부 이스케이프된다.
			// wp_kses_post 를 걸면 인라인 크기와 색이 지워져 미리보기가 실물과 달라진다.
			// 링크만 뺀다. 여기서 칸을 누르면 설정 화면을 떠난다.
			echo do_shortcode( '[green_grass link="none"]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			?>
		</div>
		<?php
	}
}
