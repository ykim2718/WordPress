/* (copyLeft) yRocket
 * [url_parameter_posts row=3, columnn=4, excerpt_length=30]
 * 2026.3.8 - 9: Kick-off
 * 2026.3.23: get_kadence_archive_color, get_dynamic_badge_on_featured_image
 */
add_shortcode('url_parameter_posts', function($atts) {
    // 1. 속성 추출 (기본값 설정)
    $a = shortcode_atts(array(
		'row'                 => 3,
		'column'              => 4,
        'category_font_size'  => '0.8rem',
        'title_font_size'     => '1.5rem',
        'meta_font_size'      => '0.7rem',
        'excerpt_length'      => 30,
        'excerpt_font_size'   => '1.0rem',
        'read_more_font_size' => '0.9rem',
    ), $atts);

    // 2. URL 파라미터 확인 및 정수화
    $raw_ids = isset($_GET['post_in']) ? $_GET['post_in'] : '';
    if (empty($raw_ids)) return "<p style='text-align:center; padding:50px;'>No search results found.</p>";

    // 정수 배열로 변환
    $ids = array_filter(array_map('intval', explode(',', $raw_ids)));
    
    // 만약 유효한 ID가 하나도 없다면 중단
    if (empty($ids)) return "<p style='text-align:center; padding:50px;'>Invalid post IDs.</p>";

    $paged = (get_query_var('paged')) ? get_query_var('paged') : 1;
	$grid_column = intval($a['column']);
    $posts_per_page = intval($a['row']) * $grid_column;

    $args = array(
        'post__in'       => $ids,
        'post_type'      => 'post',
        'posts_per_page' => $posts_per_page,
        'paged'          => $paged,
        'orderby'        => 'post__in',
        'ignore_sticky_posts' => true,  // 워드프레스의 고정 게시물 강제 삽입 방지
    );

    $query = new WP_Query($args);
    $html = '<div class="yrkt-custom-grid-wrapper">';
    $html .= '<div class="yrkt-grid-container">';

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
					$archive_color = get_kadence_archive_color($term_id, 'color', '#2b6cb0');
                    $hover_color = get_kadence_archive_color($term_id, 'hover', '#1a4971');
                    $unique_cls = 'cat-color-' . $term_id;
                    $cat_output[] = '
                        <style>
                        .' . $unique_cls . ' { color: ' . esc_attr($archive_color) . ' !important; transition: color 0.2s; }
                        .' . $unique_cls . ':hover { color: ' . esc_attr($hover_color) . ' !important; text-decoration: underline !important; }
                        </style>
                        <a href="' . esc_url($cat_link) . '" class="yrkt-cat-link ' . $unique_cls . '" style="font-size:' . esc_attr($a['category_font_size']) . '; text-decoration:none; font-weight:600;">
                        ' . esc_html($category->name) . ' </a>';
                }
            }
            $cat_html = implode('<span style="color:#ddd; margin:0 5px;">|</span>', $cat_output);
			
			// Dynamic Post Badge
			$badge_html = get_dynamic_badge_on_featured_image($post_id);

			// Card article
            $html .= '<article class="yrkt-card" style="position: relative;">';
            
            if (has_post_thumbnail()) {
                $html .= '<div class="yrkt-card-image" style="position: relative;">';
                $html .= '<a href="' . get_permalink() . '">' . get_the_post_thumbnail($post_id, 'medium_large') . '</a>';
                $html .= $badge_html; 
                $html .= '</div>';
            }

            $html .= '<div class="yrkt-card-content">';
            $html .= '<div class="yrkt-cat-wrapper" style="margin-bottom: 10px; line-height: 1;">' . $cat_html . '</div>';
            
            // 제목 폰트 사이즈 변수 연결
            $html .= '<h3 class="yrkt-card-title" style="margin-top: 0; font-size:' . esc_attr($a['title_font_size']) . ';"><a href="' . get_permalink() . '">' . get_the_title() . '</a></h3>';
            
            // 메타 정보 폰트 사이즈 변수 연결
            $html .= '<div class="yrkt-card-meta" style="font-size:' . esc_attr($a['meta_font_size']) . '; color: #888; margin-bottom: 12px; line-height: 1.4;">';
            $html .= '<span>By ' . esc_html($author) . '</span><br>';
            $html .= '<span>Created: ' . esc_html($created_date) . '</span> | ';
            $html .= '<span>Modified: ' . esc_html($modified_date) . '</span>';
            $html .= '</div>';

            // 요약문 길이 및 폰트 사이즈 (변수명 $a로 수정)
            $html .= '<div class="yrkt-card-excerpt" style="font-size:' . esc_attr($a['excerpt_font_size']) . '; color: #555; margin-bottom: 20px; line-height: 1.6;">' . wp_trim_words(get_the_excerpt(), $a['excerpt_length']) . '</div>';
            
            // Read More 폰트 사이즈 변수 연결
            $html .= '<a class="yrkt-card-readmore" style="font-size:' . esc_attr($a['read_more_font_size']) . '; margin-top: auto; color: #2b6cb0; text-decoration: none; font-weight: 600;" href="' . get_permalink() . '">Read More <span class="arrow">→</span></a>';
            $html .= '</div>';
            
            $html .= '</article>';
        }

        $html .= '</div>';

        // 페이지네이션
        $total_pages = $query->max_num_pages;
        if ($total_pages > 1) {
            $html .= '<div class="yrkt-pagination">';
            $html .= paginate_links(array(
                'total'   => $total_pages,
                'current' => $paged,
                'format'  => '?paged=%#%',
                'add_args' => array('post_in' => $raw_ids),
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
        .yrkt-grid-container { display: grid; grid-template-columns: repeat(' . $grid_column . ', 1fr); gap: 30px; margin-bottom: 40px; }
        .yrkt-card { 
		    background: #fff; border-radius: 9px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.08); 
            transition: transform 0.3s ease; display: flex; flex-direction: column; height: 100%;
			position: relative !important;  /* Badge를 위해 부모 요소에 position relative 추가 */
        }
        .yrkt-card:hover {
            animation: yrckt_wiggle 0.4s linear infinite !important;
            transition: box-shadow 0.3s ease;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1) !important;
        }
        .yrkt-card-image img { width: 100%; height: 200px; object-fit: cover; display: block; }
		.yrkt-cat-link:hover { text-decoration: underline !important; }
        .yrkt-card-content { padding: 25px; flex-grow: 1; display: flex; flex-direction: column; }
        .yrkt-card-title { line-height: 1.3; margin: 0 0 10px 0; font-weight: 700; }
        .yrkt-card-title a { color: #222; text-decoration: none; }
		.yrkt-card-title a:hover { color: #2b6cb0; text-decoration: underline; }
        .yrkt-card-readmore:hover .arrow { transform: translateX(5px); display: inline-block; transition: transform 0.2s; }
        
        .yrkt-pagination { text-align: center; margin-top: 40px; }
        .yrkt-pagination .page-numbers { padding: 8px 16px; margin: 0 5px; border: 1px solid #eee; border-radius: 50px; text-decoration: none; color: #444; }
        .yrkt-pagination .page-numbers.current { background: #2b6cb0; color: #fff; border-color: #2b6cb0; }
        
        @media (max-width: 992px) { .yrkt-grid-container { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 650px) { .yrkt-grid-container { grid-template-columns: 1fr; } }
    </style>
	<script>
    (function() {
        // 문서 어디든 마우스가 들어오면 체크하는 이벤트 위임 방식
        document.addEventListener("mouseover", function(e) {
            // 마우스가 올라간 요소가 .yrkt-card이거나 그 자식 요소인지 확인
            const card = e.target.closest(".yrkt-card");
            if (card) {
                card.style.setProperty("animation", "yrckt_wiggle 0.3s linear infinite", "important");
            }
        });
        document.addEventListener("mouseout", function(e) {
            const card = e.target.closest(".yrkt-card");
            if (card) {
                card.style.animation = "none";
            }
        });
    })();
    </script>';

    return $html;
});