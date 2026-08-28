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
		add_action( 'admin_post_kwc_pull_now', array( __CLASS__, 'handle_pull_now' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( KWC_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * 활성화 시 기본값을 심는다.
	 */
	public static function on_activate() {
		if ( false === get_option( KWC_OPTION, false ) ) {
			add_option( KWC_OPTION, KWC_Defaults::options() );
		}
		$options = KWC_Cloud::options();
		KWC_Topics::schedule( ! empty( $options['pull_enabled'] ) );
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
			'kwc_pick'  => '무엇을 그릴까',
			'kwc_style' => '크기와 색상',
			'kwc_pull'  => '하루 한 번 가져오기',
			'kwc_cache' => '캐시',
		);
		foreach ( $sections as $id => $title ) {
			add_settings_section( $id, $title, '__return_false', self::PAGE );
		}

		$fields = array(
			array( 'language', '언어', 'kwc_pick', 'field_language' ),
			array( 'min_posts', '최소 글 수', 'kwc_pick', 'field_min_posts' ),
			array( 'max_words', '최대 topic 수', 'kwc_pick', 'field_max_words' ),
			array( 'shape', '모양', 'kwc_style', 'field_shape' ),
			array( 'ratio', '타원 가로:세로', 'kwc_style', 'field_ratio' ),
			array( 'font', '글꼴', 'kwc_style', 'field_font' ),
			array( 'color_mode', '색 쓰는 법', 'kwc_style', 'field_color_mode' ),
			array( 'size', '글자 크기 (px)', 'kwc_style', 'field_size' ),
			array( 'color', '색상 (적음 → 많음)', 'kwc_style', 'field_color' ),
			array( 'link_mode', 'topic 클릭 동작', 'kwc_style', 'field_link_mode' ),
			array( 'pull_enabled', '하루 한 번 받아오기', 'kwc_pull', 'field_pull_enabled' ),
			array( 'pull_url', '가져올 주소', 'kwc_pull', 'field_pull_url' ),
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

		$language = isset( $input['language'] ) ? (string) $input['language'] : '';
		if ( in_array( $language, array( 'en', 'ko', 'both' ), true ) ) {
			$out['language'] = $language;
		} else {
			$errors[] = '언어 값이 잘못됐다: ' . $language;
		}

		foreach ( array( 'shape' => array( 'ellipse', 'block' ), 'color_mode' => array( 'palette', 'gradient' ) ) as $name => $allowed ) {
			$value = isset( $input[ $name ] ) ? (string) $input[ $name ] : '';
			if ( in_array( $value, $allowed, true ) ) {
				$out[ $name ] = $value;
			} else {
				$errors[] = $name . ' 값이 잘못됐다: ' . $value;
			}
		}

		$font = isset( $input['font'] ) ? (string) $input['font'] : '';
		if ( in_array( $font, array( 'rounded', 'sans', 'serif', 'mono', 'theme', 'custom' ), true ) ) {
			$out['font'] = $font;
		} else {
			$errors[] = '글꼴 값이 잘못됐다: ' . $font;
		}

		$out['font_custom'] = KWC_Cloud::sanitize_font_family(
			isset( $input['font_custom'] ) ? $input['font_custom'] : ''
		);
		if ( 'custom' === $out['font'] && '' === $out['font_custom'] ) {
			$errors[] = '글꼴을 직접 적기로 했는데 적은 것이 비었거나 쓸 수 없는 값이다.';
		}

		$ratio = KWC_Cloud::sanitize_ratio( isset( $input['ratio'] ) ? $input['ratio'] : '' );
		if ( null === $ratio ) {
			$errors[] = '타원 가로:세로 는 0.5 에서 5 사이의 수여야 한다: ' . ( isset( $input['ratio'] ) ? $input['ratio'] : '' );
		} else {
			$out['ratio'] = (string) $ratio;
		}

		$link = isset( $input['link_mode'] ) ? (string) $input['link_mode'] : '';
		if ( in_array( $link, array( 'search', 'none' ), true ) ) {
			$out['link_mode'] = $link;
		} else {
			$errors[] = 'topic 클릭 동작 값이 잘못됐다: ' . $link;
		}

		$ints = array(
			'max_words' => array( 1, 500 ),
			'min_posts' => array( 1, 1000 ),
			'min_size'  => array( 6, 200 ),
			'max_size'  => array( 6, 200 ),
			'cache_ttl' => array( 0, 604800 ),
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
			$errors[]        = sprintf( '최소 크기(%d)는 최대 크기(%d)보다 작아야 한다. 크기는 이전 값을 유지한다.', $out['min_size'], $out['max_size'] );
			$out['min_size'] = $current['min_size'];
			$out['max_size'] = $current['max_size'];
		}

		foreach ( array( 'color_start', 'color_end' ) as $name ) {
			$color = KWC_Cloud::sanitize_hex( isset( $input[ $name ] ) ? $input[ $name ] : '' );
			if ( null === $color ) {
				$errors[] = $name . ' 은 #rrggbb 형식이어야 한다.';
				continue;
			}
			$out[ $name ] = $color;
		}

		$out['pull_enabled'] = empty( $input['pull_enabled'] ) ? 0 : 1;

		$url = isset( $input['pull_url'] ) ? trim( (string) $input['pull_url'] ) : '';
		if ( '' === $url ) {
			$out['pull_url'] = '';
			if ( $out['pull_enabled'] ) {
				$errors[] = '하루 한 번 받아오기를 켰는데 가져올 주소가 비었다.';
			}
		} elseif ( ! wp_http_validate_url( $url ) ) {
			$errors[] = '가져올 주소가 URL 이 아니다: ' . $url;
		} else {
			$out['pull_url'] = esc_url_raw( $url );
		}

		KWC_Topics::schedule( (bool) $out['pull_enabled'] );

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
	 * 지금 바로 한 번 받아온다. 하루를 기다리지 않고 확인할 때 쓴다.
	 */
	public static function handle_pull_now() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '권한이 없다.', 403 );
		}
		check_admin_referer( 'kwc_pull_now' );
		$done = KWC_Topics::pull();
		wp_safe_redirect( add_query_arg(
			'kwc_pulled',
			is_wp_error( $done ) ? rawurlencode( $done->get_error_message() ) : (int) $done['stored'],
			admin_url( 'admin.php?page=' . self::PAGE )
		) );
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

	/**
	 * 라디오 묶음 출력.
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

	// --- 필드 렌더러 ---------------------------------------------------------

	public static function field_language() {
		self::radio_field(
			'language',
			array( 'en' => '영어', 'ko' => '한글', 'both' => '혼재' ),
			'한글이 한 자라도 있으면 한글로 본다.'
		);
	}

	public static function field_min_posts() {
		self::number_field( 'min_posts', 1, 1000, '이보다 적은 글에서 온 topic 은 그리지 않는다.' );
	}

	public static function field_max_words() {
		self::number_field( 'max_words', 1, 500, '글 수 상위 N 개만 그린다.' );
	}

	public static function field_shape() {
		self::radio_field(
			'shape',
			array( 'ellipse' => '타원', 'block' => '네모' ),
			'타원은 CSS shape-outside 로 깎는다. 지원하지 않는 브라우저에서는 네모로 보인다.'
		);
	}

	public static function field_font() {
		self::radio_field(
			'font',
			array(
				'rounded' => '둥근 (기본)',
				'sans'    => '고딕',
				'serif'   => '명조',
				'mono'    => '고정폭',
				'theme'   => '테마 글꼴',
				'custom'  => '직접 적기',
			),
			'글꼴을 내려받지 않고 기기에 있는 것만 쓴다. 둥근 글꼴은 Apple 기기에 있고, Windows 에는 없어 부드러운 고딕으로 내려간다.'
		);
		printf(
			'<p><input type="text" name="%s" value="%s" class="regular-text code" placeholder="Nunito, sans-serif"> '
				. '<span class="description">직접 적기를 골랐을 때만 쓰인다.</span></p>',
			esc_attr( self::name( 'font_custom' ) ),
			esc_attr( (string) self::value( 'font_custom' ) )
		);
	}

	public static function field_ratio() {
		printf(
			'<input type="text" name="%s" value="%s" class="small-text" inputmode="decimal">',
			esc_attr( self::name( 'ratio' ) ),
			esc_attr( (string) self::value( 'ratio' ) )
		);
		echo ' <span class="description">0.5 ~ 5. 2 면 가로가 세로의 두 배다.</span>';
		echo '<p class="description">칸이 좁아 이 비율이 안 나오면 글자를 줄여 맞춘다. 0.55 배까지만 줄이고, 그래도 모자라면 세로로 길어진다.</p>';
	}

	public static function field_color_mode() {
		self::radio_field(
			'color_mode',
			array( 'palette' => '여러 색으로 구분', 'gradient' => '한 색 그러데이션' ),
			'여러 색은 이웃한 topic 을 갈라 보이게 할 뿐 뜻은 없다. 글 수는 글자 크기가 나타낸다. 아래 색상 두 개는 그러데이션에서만 쓰인다.'
		);
	}

	public static function field_size() {
		echo '최소 ';
		self::number_field( 'min_size', 6, 200 );
		echo ' &nbsp; 최대 ';
		self::number_field( 'max_size', 6, 200 );
		echo '<p class="description">글 수에 sqrt 스케일을 적용해 두 값 사이로 배분한다.</p>';
	}

	public static function field_color() {
		foreach ( array( 'color_start' => '적은 글에서 온 topic', 'color_end' => '많은 글에서 온 topic' ) as $key => $label ) {
			printf(
				'<label style="margin-right:16px">%s <input type="color" name="%s" value="%s"></label>',
				esc_html( $label ),
				esc_attr( self::name( $key ) ),
				esc_attr( (string) self::value( $key ) )
			);
		}
	}

	public static function field_link_mode() {
		self::radio_field(
			'link_mode',
			array( 'search' => '그 말로 검색한 글 목록 열기', 'none' => '링크 없음' )
		);
	}

	public static function field_pull_enabled() {
		printf(
			'<label><input type="checkbox" name="%s" value="1" %s> 하루에 한 번 아래 주소에서 topic 을 받아온다</label>',
			esc_attr( self::name( 'pull_enabled' ) ),
			checked( 1, (int) self::value( 'pull_enabled' ), false )
		);
		echo '<p class="description">받아오는 것이지 분석하는 것이 아니다. 새로 쓴 글은 파이프라인을 다시 돌려 그 주소의 파일을 갱신해야 반영된다.</p>';
	}

	public static function field_pull_url() {
		printf(
			'<input type="url" name="%s" value="%s" class="large-text code" placeholder="https://…/topics.json">',
			esc_attr( self::name( 'pull_url' ) ),
			esc_attr( (string) self::value( 'pull_url' ) )
		);
		$last = KWC_Topics::last_pull();
		if ( '' === $last['at'] ) {
			echo '<p class="description">아직 한 번도 받아오지 않았다.</p>';
		} else {
			printf(
				'<p class="description">마지막 시도: %s — %s</p>',
				esc_html( $last['at'] ),
				esc_html( $last['result'] )
			);
		}
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
		if ( isset( $_GET['kwc_pulled'] ) ) {
			$result = sanitize_text_field( wp_unslash( $_GET['kwc_pulled'] ) );
			if ( 1 === preg_match( '/^\d+$/', $result ) ) {
				printf( '<div class="notice notice-success is-dismissible"><p>topic %d개를 받아 저장했다.</p></div>', (int) $result );
			} else {
				printf( '<div class="notice notice-error is-dismissible"><p>받아오지 못했다: %s</p></div>', esc_html( $result ) );
			}
		}

		// 최상위 메뉴 페이지는 검증 오류가 자동으로 뜨지 않는다. 직접 띄운다.
		settings_errors();

		$status = KWC_Topics::status();
		?>
		<div class="wrap">
			<h1>Key Word Cloud</h1>
			<p>숏코드: <code>[wpwordcloud]</code> — 속성으로 아래 설정을 개별 페이지에서 덮어쓸 수 있다.<br>
				예: <code>[wpwordcloud language="ko" max="30" min_posts="3" color_end="#b3202e"]</code></p>

			<h2>올라온 topic</h2>
			<?php if ( 0 === $status['count'] ) : ?>
				<p><strong>아직 없다.</strong> 파이프라인이 <code>/wp-json/key-word-cloud/v1/topics</code> 로 올려야
					구름이 그려진다.</p>
			<?php else : ?>
				<p><strong><?php echo (int) $status['count']; ?></strong>개
					<?php if ( '' !== $status['updated'] ) : ?>
						· <?php echo esc_html( $status['updated'] ); ?>
					<?php endif; ?>
					<?php if ( '' !== $status['generator'] ) : ?>
						· <?php echo esc_html( $status['generator'] ); ?>
					<?php endif; ?>
				</p>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php
				settings_fields( self::GROUP );
				do_settings_sections( self::PAGE );
				submit_button();
				?>
			</form>

			<hr>
			<h2>지금 하기</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
				<input type="hidden" name="action" value="kwc_pull_now">
				<?php wp_nonce_field( 'kwc_pull_now' ); ?>
				<?php submit_button( '지금 받아오기', 'primary', 'submit', false ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;margin-left:8px">
				<input type="hidden" name="action" value="kwc_flush_cache">
				<?php wp_nonce_field( 'kwc_flush_cache' ); ?>
				<?php submit_button( '캐시 비우기', 'secondary', 'submit', false ); ?>
			</form>
			<?php
			$next = wp_next_scheduled( KWC_Topics::CRON_HOOK );
			if ( $next ) {
				printf(
					'<p class="description">다음 자동 갱신: %s</p>',
					esc_html( gmdate( 'c', $next ) )
				);
			} else {
				echo '<p class="description">자동 갱신이 걸려 있지 않다.</p>';
			}
			?>

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
