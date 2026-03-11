/** Y, 2026.3.11
 * [subcategories_posts parent=10, row=3, column=4, excerpt_length=30]
 * parent: 10 (Software), 4 (Semiconducotr)
 */
add_shortcode('subcategories_posts', function($atts) {
    // 1. 속성 추출 (기본값 설정 - parent 추가)
    $a = shortcode_atts(array(
        'parent'              => 0,
        'row'                 => 3,
        'column'              => 4,
        'category_font_size'  => '0.8rem',
        'title_font_size'     => '1.5rem',
        'meta_font_size'      => '0.7rem',
        'excerpt_length'      => 30,
        'excerpt_font_size'   => '1.0rem',
        'read_more_font_size' => '0.9rem',
    ), $atts);

    // 2. 카테고리 ID 처리
    $parent_id = intval($a['parent']);
    if ($parent_id <= 0) return "<p style='text-align:center; padding:50px;'>Please provide a valid parent category ID.</p>";

    // 해당 카테고리와 모든 하위 카테고리 ID 가져오기
    $category_ids = get_term_children($parent_id, 'category');
    $category_ids[] = $parent_id; // 부모 카테고리 자신도 포함

    $paged = (get_query_var('paged')) ? get_query_var('paged') : 1;
    $grid_column = intval($a['column']);
    $posts_per_page = intval($a['row']) * $grid_column;

    $args = array(
        'category__in'   => $category_ids,
        'post_type'      => 'post',
        'posts_per_page' => $posts_per_page,
        'paged'          => $paged,
        'orderby'        => 'date', // post__in이 제거되었으므로 기본 날짜순 정렬
        'order'          => 'DESC'
    );

    $query = new WP_Query($args);
    $html = '<div class="stk-custom-grid-wrapper">';
    $html .= '<div class="stk-grid-container">';

    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $post_id = get_the_ID();
            
            $author = get_the_author();
            $created_date = get_the_date('Y.m.d');
            $modified_date = get_the_modified_date('Y.m.d');
            
            // 카테고리 처리
            $categories = get_the_category();
            $cat_output = array();
            if (!empty($categories)) {
                foreach ($categories as $category) {
                    $term_id = $category->term_id;
                    $cat_link = get_category_link($term_id);
                    $cat_color = get_term_meta($term_id, 'color', true); 
                    if (!$cat_color) $cat_color = '#2b6cb0'; 
                    $cat_output[] = '<a href="' . esc_url($cat_link) . '" class="stk-cat-link" style="color:' . esc_attr($cat_color) . '; font-size:' . esc_attr($a['category_font_size']) . '; text-decoration:none; padding:2px 1px; border-radius:4px; display:inline-block;">' . esc_html($category->name) . '</a>';
                }
            }
            $cat_html = implode('<span style="color:#ddd; margin:0 5px;">|</span>', $cat_output);

            $html .= '<article class="stk-card">';
            
            if (has_post_thumbnail()) {
                $html .= '<div class="stk-card-image"><a href="' . get_permalink() . '">' . get_the_post_thumbnail($post_id, 'medium_large') . '</a></div>';
            }

            $html .= '<div class="stk-card-content">';
            
            $html .= '<div class="stk-cat-wrapper" style="margin-bottom: 10px; line-height: 1;">' . $cat_html . '</div>';
            
            // 제목 폰트 사이즈 변수 연결
            $html .= '<h3 class="stk-card-title" style="margin-top: 0; font-size:' . esc_attr($a['title_font_size']) . ';"><a href="' . get_permalink() . '">' . get_the_title() . '</a></h3>';
            
            // 메타 정보 폰트 사이즈 변수 연결
            $html .= '<div class="stk-card-meta" style="font-size:' . esc_attr($a['meta_font_size']) . '; color: #888; margin-bottom: 12px; line-height: 1.4;">';
            $html .= '<span>By ' . esc_html($author) . '</span><br>';
            $html .= '<span>Created: ' . esc_html($created_date) . '</span> | ';
            $html .= '<span>Modified: ' . esc_html($modified_date) . '</span>';
            $html .= '</div>';

            // 요약문 길이 및 폰트 사이즈
            $html .= '<div class="stk-card-excerpt" style="font-size:' . esc_attr($a['excerpt_font_size']) . '; color: #555; margin-bottom: 20px; line-height: 1.6;">' . wp_trim_words(get_the_excerpt(), $a['excerpt_length']) . '</div>';
            
            // Read More 폰트 사이즈 변수 연결
            $html .= '<a class="stk-card-readmore" style="font-size:' . esc_attr($a['read_more_font_size']) . '; margin-top: auto; color: #2b6cb0; text-decoration: none; font-weight: 600;" href="' . get_permalink() . '">Read More <span class="arrow">→</span></a>';
            $html .= '</div>';
            
            $html .= '</article>';
        }

        $html .= '</div>';

        // 페이지네이션
        $total_pages = $query->max_num_pages;
        if ($total_pages > 1) {
            $html .= '<div class="stk-pagination">';
            $html .= paginate_links(array(
                'total'   => $total_pages,
                'current' => $paged,
                'format'  => '?paged=%#%',
                'prev_text' => '« Prev',
                'next_text' => 'Next »',
            ));
            $html .= '</div>';
        }

        wp_reset_postdata();
    } else {
        $html .= '</div><p style="text-align:center;">No posts found.</p>';
    }

    $html .= '</div>';

    $html .= '
    <style>
        .stk-grid-container { display: grid; grid-template-columns: repeat(' . $grid_column . ', 1fr); gap: 30px; margin-bottom: 40px; }
        .stk-card { 
            background: #fff; border-radius: 9px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.08); 
            transition: transform 0.3s ease; display: flex; flex-direction: column; height: 100%;
        }
        .stk-card:hover {
            animation: stk_shake_final 0.4s linear infinite !important;
            transition: box-shadow 0.3s ease;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1) !important;
        }
        .stk-card-image img { width: 100%; height: 200px; object-fit: cover; display: block; }
        .stk-cat-link:hover { text-decoration: underline !important; }
        .stk-card-content { padding: 25px; flex-grow: 1; display: flex; flex-direction: column; }
        .stk-card-title { line-height: 1.3; margin: 0 0 10px 0; font-weight: 700; }
        .stk-card-title a { color: #222; text-decoration: none; }
        .stk-card-title a:hover { color: #2b6cb0; text-decoration: underline; }
        .stk-card-readmore:hover .arrow { transform: translateX(5px); display: inline-block; transition: transform 0.2s; }
        
        .stk-pagination { text-align: center; margin-top: 40px; }
        .stk-pagination .page-numbers { padding: 8px 16px; margin: 0 5px; border: 1px solid #eee; border-radius: 50px; text-decoration: none; color: #444; }
        .stk-pagination .page-numbers.current { background: #2b6cb0; color: #fff; border-color: #2b6cb0; }
        
        @media (max-width: 992px) { .stk-grid-container { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 650px) { .stk-grid-container { grid-template-columns: 1fr; } }
        @keyframes stk_shake_final {
            0% { transform: translate(0, 0) rotate(0deg); }
            25% { transform: translate(-1.5px, 1px) rotate(-0.5deg); }
            50% { transform: translate(1.5px, -1px) rotate(0.5deg); }
            75% { transform: translate(-1px, -1.5px) rotate(-0.2deg); }
            100% { transform: translate(1px, 1.5px) rotate(0.2deg); }
        }
    </style>
    <script>
    (function() {
        document.addEventListener("mouseover", function(e) {
            const card = e.target.closest(".stk-card");
            if (card) {
                card.style.setProperty("animation", "stk_shake_final 0.3s linear infinite", "important");
            }
        });
        document.addEventListener("mouseout", function(e) {
            const card = e.target.closest(".stk-card");
            if (card) {
                card.style.animation = "none";
            }
        });
    })();
    </script>';

    return $html;
});