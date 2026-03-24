/** Y, 2026.3.11
 * 2026.3.23 (get_kadence_archive_color, get_dynamic_badge_on_featured_image, sticky_badge)
 * 2026.3.24 (get_subcategory_ids)
 *
 * [subcategories_posts parent=10, row=3, column=4, excerpt_length=30]
 * parent: 10 (Software), 4 (Semiconducotr), 1408 (Beauty), 1392 (MusicEye)
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

    // 2. 카테고리 ID 처리: 해당 카테고리와 모든 하위 카테고리 ID 가져오기
    $category_ids = get_subcategory_ids($a['parent']);
	
    //$paged = (get_query_var('paged')) ? get_query_var('paged') : 1;
    $paged = get_query_var('paged') ? get_query_var('paged') : (get_query_var('page') ? get_query_var('page') : 1);
    $grid_column = intval($a['column']);
    $posts_per_page = intval($a['row']) * $grid_column;
	
	// 1. 해당 카테고리의 모든 고정글 ID 찾기
    $sticky_all = get_option('sticky_posts');
    $sticky_in_cats = array();
    if (!empty($sticky_all)) {
        $sticky_in_cats = get_posts(array(
            'post__in' => $sticky_all,
            'category__in' => $category_ids,
            'posts_per_page' => -1,
            'fields' => 'ids',
            'ignore_sticky_posts' => true
        ));
    }
	
	// 2. 해당 카테고리의 모든 일반글 ID 찾기 (고정글 제외)
    $normal_post_ids = get_posts(array(
        'category__in' => $category_ids,
        'post__not_in' => $sticky_in_cats,
        'posts_per_page' => -1, // 전체를 다 가져옴
        'fields' => 'ids',
        'orderby' => 'date',
        'order' => 'DESC',
        'ignore_sticky_posts' => true
    ));

    // 3. 두 리스트 합치기 (고정글이 항상 위로)
    $all_post_ids = array_merge($sticky_in_cats, $normal_post_ids);
    $total_count = count($all_post_ids);

    // 4. 현재 페이지에 보여줄 ID들만 추출 (Pagination 처리)
    $offset = ($paged - 1) * $posts_per_page;
    $paged_post_ids = array_slice($all_post_ids, $offset, $posts_per_page);

    // 5. 최종 쿼리 실행
    $args = array(
        'post__in'            => !empty($paged_post_ids) ? $paged_post_ids : array(0),
        'post_type'           => 'post',
        'posts_per_page'      => $posts_per_page,
        'paged'               => 1, // 이미 위에서 잘라왔으므로 여기선 1로 고정
        'orderby'             => 'post__in',
        'ignore_sticky_posts' => true
    );

    $query = new WP_Query($args);

    // 6. 페이지네이션 정보 강제 주입 (이게 없으면 번호가 안 나옵니다)
    $query->found_posts = $total_count;
    $query->max_num_pages = ceil($total_count / $posts_per_page);
	

	// Create HTML
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
            $html .= '<article class="yrkt-card">';
			
            $is_sticky = is_sticky_post_by_id($post_id);
			if ($is_sticky) {
				$sticky_badge = '<span class="yrkt-sticky-label" style="position:absolute; top:10px; right:10px; font-size:19px; z-index:10; line-height:1.0;">💗</span>';
			    $html .= $sticky_badge;	
			}
            
            if (has_post_thumbnail()) {
                $html .= '<div class="yrkt-card-image" style="position: relative;">';
                $html .= '<a href="' . get_permalink() . '">' . get_the_post_thumbnail($post_id, 'medium_large') . '</a>';
                $html .= $badge_html; 
                $html .= '</div>';
            }

			// Card article
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

            // 요약문 길이 및 폰트 사이즈
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
			$big = 999999999;
            $html .= paginate_links(array(
				'base'      => str_replace($big, '%#%', esc_url(get_pagenum_link($big))),
                'format'    => '?paged=%#%',
                'total'   => $total_pages,
                'current' => $paged,
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
        document.addEventListener("mouseover", function(e) {
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


