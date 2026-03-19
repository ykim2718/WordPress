/* Y, 2026.3.19
 */
add_shortcode('dynamic_comment_count_text', function() {
    // 현재 Query Loop 내의 포스트 ID를 가져옵니다.
    $post_id = get_the_ID();
    if (!$post_id) return '';

    // 댓글 개수를 가져옵니다.
    $count = get_comments_number($post_id);

    // 조건에 따른 텍스트 설정
    if ($count <= 1) {
        $text = $count . ' comment';
    } else {
        $text = $count . ' comments';
    }

    return '<span class="custom-comment-count">' . $text . '</span>';
});