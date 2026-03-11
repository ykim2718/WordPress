/* Y, 2026.3.11
 * [subcategories_search parent=1408 title='']
 */
add_shortcode('subcategories_search', '_subcategories_search');
function _subcategories_search($atts) {
    // 1. 설정 및 속성 정의
    $atts = shortcode_atts(array(
        'parent' => 0,
        'title'  => 'Search in Categories'
    ), $atts);

    $parent_id = intval($atts['parent']);
    $title = esc_html($atts['title']);

    // 2. 검색 로직 (POST 요청 처리)
    if (isset($_POST['sub_search_submit']) && !empty($_POST['sub_search_query'])) {
        $search_term = sanitize_text_field($_POST['sub_search_query']);
        
        // 부모 및 모든 하위 카테고리 ID 가져오기
        $category_ids = get_term_children($parent_id, 'category');
        $category_ids[] = $parent_id;

        // 포스트 검색 (ID만 추출)
        $query_args = array(
            's'              => $search_term,
            'category__in'   => $category_ids,
            'posts_per_page' => -1,
            'fields'         => 'ids'
        );

        $post_ids = get_posts($query_args);

        // 결과 URL 생성 및 리다이렉트
        $base_url = "https://stellafire.com/home/search-results/";
        if (!empty($post_ids)) {
            $ids_string = implode(',', $post_ids);
            $redirect_url = add_query_arg('post_in', $ids_string, $base_url);
        } else {
            $redirect_url = add_query_arg('post_in', 'none', $base_url);
        }

        wp_redirect($redirect_url);
        exit;
    }

    // 3. UI 출력 (HTML/CSS)
    ob_start();
    ?>
    <style>
        .sub-search-container { max-width: 500px; margin: 20px 0; font-family: sans-serif; }
        .sub-search-title { font-size: 1.2em; font-weight: bold; margin-bottom: 10px; }
        .sub-search-form { display: flex; position: relative; }
        .sub-search-input { 
            flex: 1; padding: 10px 45px 10px 12px; border: 1px solid #ccc; border-radius: 4px; font-size: 16px; 
        }
        .sub-search-button { 
            position: absolute; right: 5px; top: 50%; transform: translateY(-50%);
            background: none; border: none; cursor: pointer; padding: 5px;
        }
        .sub-search-button svg { width: 20px; height: 20px; fill: #555; }
    </style>

    <div class="sub-search-container">
        <div class="sub-search-title"><?php echo $title; ?></div>
        <form method="post" class="sub-search-form">
            <input type="text" name="sub_search_query" class="sub-search-input" placeholder="검색어를 입력하세요..." required>
            <button type="submit" name="sub_search_submit" class="sub-search-button">
                <svg viewBox="0 0 24 24"><path d="M15.5 14h-.79l-.28-.27A6.471 6.471 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>
            </button>
        </form>
    </div>
    <?php
    return ob_get_clean();
}
