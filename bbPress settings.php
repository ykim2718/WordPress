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