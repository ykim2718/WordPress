/** Y, 2026.3.6 - 7
 * 특정 부모 카테고리의 하위 카테고리 리스트 출력 쇼트코드
 * 사용법: [subcategory_list parent="12"] (12는 부모 ID)
 * 1408: Beauty
 */
add_shortcode('subcategory_list', '_subcategory_list');
function _subcategory_list($atts) {
    // 매개변수 설정
    $a = shortcode_atts(array(
        'parent' => 0, // 기본값은 0
    ), $atts);

    $args = array(
        'child_of'      => $a['parent'],
		'hide_empty'    => false,   // 핵심: 글이 없는(0개) 카테고리도 표시
        'title_li'      => '',      // 제목 제거
        'show_count'    => true,    // 글 개수 표시 (원치 않으면 false)
        'hierarchical'  => true,
        'echo'          => false,   // 바로 출력하지 않고 변수에 저장
        'taxonomy'      => 'category',
    );

    $categories = wp_list_categories($args);

    if (empty($categories) || strpos($categories, 'No categories') !== false) {
        return '<p class="no-sub-cats">하위 카테고리가 없습니다.</p>';
    }

    // Kadence 스타일과 어울리도록 클래스 부여
    return '<ul class="kadence-custom-sub-cats">' . $categories . '</ul>';
}
