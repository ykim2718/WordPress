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