/* 2025.11.28
 // Y, 2025.11.25
function disable_jetpack_search_for_bbpress_high_priority( $disabled ) {
    // bbPress 검색 결과 페이지이거나, 검색 쿼리에 bbp 포스트 타입이 포함되었는지 확인합니다.
    if ( function_exists( 'bbp_is_search' ) && bbp_is_search() ) {
        // bbPress 검색일 경우 Jetpack Search를 비활성화(true)합니다.
        return true;
    }
    
    // bbp_is_search()가 작동하지 않을 경우, 일반 검색 쿼리에서 bbPress 토픽이나 답글이 요청되었는지 확인합니다.
    global $wp_query;
    if ( isset( $wp_query->query['post_type'] ) && is_array( $wp_query->query['post_type'] ) ) {
        if ( in_array( bbp_get_topic_post_type(), $wp_query->query['post_type'] ) || in_array( bbp_get_reply_post_type(), $wp_query->query['post_type'] ) ) {
            return true;
        }
    }
    
    return $disabled;
}
// 우선순위를 1000으로 설정하여 Jetpack의 기본 설정보다 늦게 실행되도록 합니다.
add_filter( 'jetpack_search_disable', 'disable_jetpack_search_for_bbpress_high_priority', 1000 );


// Y, 2025.11.24 - 25
function restrict_bbp_search_to_topics_and_replies_final( $query ) {
    // 관리자 페이지가 아니고, 메인 쿼리이며, 검색 페이지이고, bbPress 검색일 때만 실행합니다.
    if ( ! is_admin() && $query->is_main_query() && $query->is_search() && function_exists( 'bbp_is_search' ) && bbp_is_search() ) {

        // 검색 포스트 타입을 토픽(topic)과 답글(reply)로 강제 지정합니다.
        $query->set( 'post_type', array( 
            bbp_get_topic_post_type(),
            bbp_get_reply_post_type()
        ) );
    }
}
// 우선순위 999를 사용하여 Jetpack 비활성화 후 쿼리가 실행되기 전에 작동하도록 합니다.
add_action( 'pre_get_posts', 'restrict_bbp_search_to_topics_and_replies_final', 999 );


// Y, 2025.11.24
// 모든 bbPress 작성자 이름을 display_name으로 표시
// Reply author -> display_name
add_filter( 'bbp_get_reply_author_display_name', function( $author_name, $reply_id ) {
    // Ensure $reply_id is a valid post
    if ( empty( $reply_id ) || ! get_post( $reply_id ) ) return $author_name;

    $user_id = bbp_get_reply_author_id( $reply_id );
    if ( ! $user_id ) return $author_name;

    $user = get_userdata( $user_id );
    if ( ! $user || empty( $user->display_name ) ) return $author_name;

    // Trim overly long names to prevent layout breakage
    $name = $user->display_name;
    if ( mb_strlen( $name ) > 40 ) $name = mb_substr( $name, 0, 40 ) . '…';

    return $name;
}, 10, 2 );
// Topic author -> display_name
add_filter( 'bbp_get_topic_author_display_name', function( $author_name, $topic_id ) {
    if ( empty( $topic_id ) || ! get_post( $topic_id ) ) return $author_name;

    $user_id = bbp_get_topic_author_id( $topic_id );
    if ( ! $user_id ) return $author_name;

    $user = get_userdata( $user_id );
    if ( ! $user || empty( $user->display_name ) ) return $author_name;

    $name = $user->display_name;
    if ( mb_strlen( $name ) > 40 ) $name = mb_substr( $name, 0, 40 ) . '…';

    return $name;
}, 10, 2 );
*/

// Y, 2025.11.28
// 특정 Forum을 family 역할만 접근 가능하게 제한
function my_bbp_restrict_family_forum_access() {
    $restricted_forum_id = 2209;  // 제한할 Forum ID (예: 123), 2209: Bangood Education
	$allowed_roles = array( 'um_family', 'bbp_keymaster' );  // 허용할 역할 배열

    // 현재 포럼 페이지인지 확인
    if ( bbp_is_forum() && bbp_get_forum_id() == $restricted_forum_id ) {
        // 로그인 여부 확인
        if ( ! is_user_logged_in() ) {
            wp_die( '이 포럼은 로그인한 family 사용자만 볼 수 있습니다.' );
        }
        // 현재 사용자 정보
        $user = wp_get_current_user();
        // family 역할이 아니면 접근 차단
        if ( ! array_intersect( $allowed_roles, (array) $user->roles ) ) {
            wp_die( '이 포럼은 family 사용자만 볼 수 있습니다.' );
        }
    }
}
add_action( 'template_redirect', 'my_bbp_restrict_family_forum_access' );
// Forum 목록에서도 숨기기
function my_bbp_hide_family_forum_from_list( $args ) {
    $restricted_forum_id = 2209;  // 2209: Bangood Education
    $user = wp_get_current_user();
	$allowed_roles = array( 'um_family', 'bbp_keymaster' );  // 허용할 역할 배열
	/* DEBUGGING
	echo '<p>현재 사용자 역할:</p>';
    echo '<ul>';
    foreach ( $user->roles as $role ) {
        echo '<li>' . esc_html( $role ) . '</li>';
    }
    echo '</ul>';
	*/
    // 로그인하지 않았거나 허용된 역할이 아닌 경우 숨김
    if ( ! is_user_logged_in() || ! array_intersect( $allowed_roles, (array) $user->roles ) ) {
        $args['post__not_in'] = array( $restricted_forum_id );
    }

    return $args;
}
add_filter( 'bbp_has_forums_query', 'my_bbp_hide_family_forum_from_list' );


// Y, 2025.11.28
/* DEBUGGING *
add_action( 'wp_footer', function() {
    if ( is_user_logged_in() ) {
		$user_role = bbp_get_user_role( get_current_user_id() );
		echo '<pre>bbPress role: ' . esc_html( $user_role ) . '</pre>';
        $user = wp_get_current_user();
        echo '<pre>'; print_r($user->allcaps); echo '</pre>';
    }
});
*/


// Y, 2025.12.6
function prevent_duplicate_topics($topic_id) {
    $topic_title = bbp_get_topic_title($topic_id);
    $forum_id    = bbp_get_topic_forum_id($topic_id);

    // 같은 포럼 내 동일 제목 검색
    $args = array(
        'post_type'   => bbp_get_topic_post_type(),
        'post_parent' => $forum_id,
        'title'       => $topic_title,
        'post_status' => 'publish',
    );

    $query = new WP_Query($args);

    if ($query->found_posts > 1) {
        // 중복 발견 시 삭제
        wp_delete_post($topic_id, true);
        bbp_add_error('duplicate_topic', __('이미 동일한 제목의 토픽이 존재합니다.', 'bbpress'));
    }
}
add_action('bbp_new_topic', 'prevent_duplicate_topics');


// Y, 2025.12.7
// Subscriber가 자신이 작성한 글만 삭제 가능
function bbpress_subscriber_delete_own( $caps, $cap, $user_id, $args ) {
    if ( in_array( $cap, ['delete_reply', 'delete_topic'], true ) ) {
        $post = get_post( $args[0] );
        if ( $post && $post->post_author == $user_id ) {
            // 본인 글일 경우 권한 허용
            $caps = ['exist'];
        }
    }
    return $caps;
}
add_filter( 'map_meta_cap', 'bbpress_subscriber_delete_own', 10, 4 );


/* Y, 2025.12.15
 * “User Role Editor” 같은 플러그인으로 권한 수정했을 때 bbPress의 커스텀 권한이 제대로 반영되지 않는 경우가 많음.
 * bbPress의 Participant role에 WordPress capability를 강제 매핑.
 */
function fix_bbpress_caps() {
    $role = get_role( 'bbp_participant' );
    if ( $role ) {
        $role->add_cap( 'publish_topics' );
        $role->add_cap( 'edit_topics' );
        $role->add_cap( 'delete_topics' );
    }
}
add_action( 'bbp_init', 'fix_bbpress_caps' );



/* Y, 2025.12.15
 * 중복된 박스 제거하기. 보통 bbPress 기본 박스 ID는 bbp_forum_attributes 임.
 */
function remove_duplicate_forum_attributes() {
    remove_meta_box( 'bbp_forum_attributes', 'forum', 'side' );
}
add_action( 'add_meta_boxes_forum', 'remove_duplicate_forum_attributes', 11 );


/* Y, 2025.12.18 - 19
 * Allow HTML tags.
 */
function custom_bbp_allow_basic_tags( $allowed ) {
	$allowed['h1'] = array();
    $allowed['h2'] = array();
    $allowed['h3'] = array();
	$allowed['h4'] = array();
	$allowed['h5'] = array();
	$allowed['h6'] = array();
	$allowed['hr'] = array();
    $allowed['a']  = array(
        'href'  => array(),
        'title' => array(),
        'rel'   => array()
    );
    return $allowed;
}
add_filter( 'bbp_kses_allowed_tags', 'custom_bbp_allow_basic_tags' );



/* Y, 2025.12.18
 * bbPress는 토픽/포럼 제목에 기본 최대 글자 수(보통 80자) 제한을 둔다.
 * 멀티바이트(한글/이모지) 사용 시 체감 길이보다 더 길게 계산될 수 있다.
 */
add_filter( 'bbp_get_title_max_length', function() {
    return 128; // 원하는 최대 길이
});



/* Y, 2026.1.2
 * bbPress에 style attribute 허용하기
 * <img style="width:auto;height:100px"> 같은 태그의 저장 시의 자동제거를 막아줌.
 */
function allow_style_attribute_in_bbpress( $allowed ) {
    if ( isset( $allowed['img'] ) ) {
        $allowed['img']['style'] = true;
    }
    return $allowed;
}
add_filter( 'bbp_kses_allowed_tags', 'allow_style_attribute_in_bbpress' );


/* Y, 2026.1.19
 * bbPress 본문 출력 시 모든 숏코드를 강제로 해석함
 */
remove_filter( 'bbp_get_topic_content', 'bbp_kses_encode_bad', 10 );
remove_filter( 'bbp_get_reply_content', 'bbp_kses_encode_bad', 10 );
add_filter( 'bbp_get_topic_content', 'do_shortcode', 1 );
add_filter( 'bbp_get_reply_content', 'do_shortcode', 1 );



/*, Y, 2026.1.19
 * pdfjs block: Prebuilt (modern browsers) Stable v5.4.530
 * https://mozilla.github.io/pdf.js/getting_started/
 * [pdf_safe_view url="PDF파일주소"] 숏코드 생성
 */
function register_pdf_safe_viewer_shortcode($atts) {
    $a = shortcode_atts(array(
        'url' => '',
    ), $atts);

    if (empty($a['url'])) return 'PDF URL이 입력되지 않았습니다.';

    // 테마 폴더 내 pdfjs 경로 설정
    $viewer_url = get_stylesheet_directory_uri() . '/pdfjs/web/viewer.html';
    $file_url = esc_url($a['url']);

    // iframe으로 뷰어 호출 (우클릭 방지 스크립트 포함)
    return '<iframe src="' . $viewer_url . '?file=' . $file_url . '#view=FitH"
            width="100%" height="900px" 
            style="border: none;" 
            oncontextmenu="return false;"></iframe>';
}
add_shortcode('pdf_safe_view', 'register_pdf_safe_viewer_shortcode');



/*
 * Y, 2026.2.15
 * $$f(x) = a^x + q$$   ... 줄바꿈과 중앙정렬
 * $f(x) = a^x + q$     ... 줄바꿈 없음 (in-line text)
 * <math class="math-text">f(x) = a^x + q</math>   ... $..$와 동일
 */
// 1. 출력 직전, 깨진 <math> 구조를 강제로 텍스트($)로 복원
function fix_broken_math_structure($content) {
    // <math ... data-latex="수식" ...> 또는 그 변형들을 모두 찾아 $수식$으로 바꿉니다.
    // 태그가 닫히지 않아 발생하는 문제를 방지하기 위해 정규식을 매우 포괄적으로 설정합니다.
    $content = preg_replace_callback('/<math[^>]*data-latex=["\']([^"\']+)["\'][^>]*>(.*?)<\/math>/is', function($matches) {
        return ' $' . $matches[1] . '$ ';
    }, $content);

    // 혹시라도 닫히지 않은 <math> 태그가 남아 전체를 집어삼키는 것을 방지
    $content = preg_replace('/<math[^>]*data-latex=["\']([^"\']+)["\'][^>]*>/i', ' $\1$ ', $content);
    
    return $content;
}
// bbPress의 글 내용(Topic)과 댓글(Reply) 출력 필터에 최우선 순위로 적용
add_filter('bbp_get_topic_content', 'fix_broken_math_structure', 1);
add_filter('bbp_get_reply_content', 'fix_broken_math_structure', 1);
// 2. KaTeX 렌더링 설정
add_action('wp_footer', function() {
    if ( is_bbpress() ) {
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.css">
<script src="https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/contrib/auto-render.min.js"></script>
<script>
    document.addEventListener("DOMContentLoaded", function() {
        renderMathInElement(document.body, {
            delimiters: [
                {left: "$$", right: "$$", display: true},
                {left: "$", right: "$", display: false}
            ],
            throwOnError : false
        });
    });
</script>
<?php
    }
}, 999);