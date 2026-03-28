/* Y, 2026.3.24 */
function echo_variable_value($name, $a) {
	echo '<pre>🚀 ' . $name . ' = ';
	if (is_array($a)) {
	    // echo '<pre> ' . $name . print_r($a, true) . '</pre>';
        var_dump($a);
	} else {
		print_r($a);
	}
	echo '</pre>';
}
/* Y, 2026.3.24 */
function echo_variable_type($name, $a) {
	echo '<pre>🚀 ' . $name . ' = ';
	echo gettype($a);
	echo '</pre>';
}
/* Y, 2026.3.26 */
function echo_array_element($name, $a, $index) {
	echo '<pre>🚀 ' . esc_html($name) . '[' . esc_html($index) . '] = ';
	if (isset($a) && is_array($a) && !empty($a)) {
		if ($index == -1) {
			$result = $a[array_key_last($a)];
		} else {
			$result = isset($a[$index]) ? $a[$index] : "Index {$index} not found";
		}
	    echo esc_html(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));	
	} else {
		echo 'empty or null array';
	}
	echo '</pre>';
}



/* Y, 2026.3.23
 * 해당 카테고리의 '모든' 메타 데이터를 가져옵
 */
function echo_category_color($category_name, $category_id) {
    // 1. 해당 카테고리의 모든 메타 데이터를 가져옵니다 (오류 방지용 핵심 코드)
    $all_meta = get_term_meta($category_id);
    
    // 2. 만약 데이터가 아예 없다면 알림 출력
    if (empty($all_meta)) {
        echo '<div style="background:#f8d7da; color:#721c24; padding:10px; margin:10px 0; border:1px solid #f5c6cb;">';
        echo '<strong>Category:</strong> ' . esc_html($category_name) . ' (ID: ' . $category_id . ') - 메타 데이터가 전혀 없습니다.</div>';
        return;
    }

    $debug_info = '<div style="font-size:11px; background:#fff3cd; border:1px solid #ffeeba; padding:15px; margin:15px 0; color:#856404; line-height:1.4; text-align:left;">';
    $debug_info .= '<strong style="font-size:13px;">[Debugging Category Meta]</strong><br>';
    $debug_info .= '<strong>Name:</strong> ' . esc_html($category_name) . ' | <strong>ID:</strong> ' . $category_id . '<br><br>';
    
    // 3. 존재하는 모든 메타 키 리스트 출력
    $debug_info .= '<strong>Available Meta Keys:</strong><br>';
    $debug_info .= '<pre style="background:#fff; padding:5px; border:1px solid #eee;">' . esc_html(print_r(array_keys($all_meta), true)) . '</pre>';
    
    // 4. (참고) kadence_taxonomy_options가 있다면 그 내용도 살짝 보여주기
    if (isset($all_meta['kadence_taxonomy_options'])) {
        $debug_info .= '<br><strong>Kadence Options Found! Raw Data:</strong><br>';
        $debug_info .= '<pre style="background:#fff; padding:5px; border:1px solid #eee;">' . esc_html(print_r($all_meta['kadence_taxonomy_options'], true)) . '</pre>';
    }

    $debug_info .= '</div>';
    
    echo $debug_info;
}




/* Y, 2026.3.23
 */
function get_custom_category_color($term_id, $default_color = '#2b6cb0') {
    $cat_color = get_term_meta($term_id, 'color', true); 
    if (!$cat_color) $cat_color = $default_color; 
    return $cat_color;
}
/* Y, 2026.3.23
 * Kadence 테마 카테고리 설정의 Archive Color를 가져오는 함수
 * @param int $term_id 카테고리 ID
 * @param string $type 'color' 또는 'hover' 선택
 * @return string HEX 색상 코드
 */
function get_kadence_archive_color($term_id, $type = 'color', $default_color = '#2b6cb0') {
    // 1. 요청 타입에 따라 메타 키 결정
    $meta_key = ($type === 'hover') ? 'archive_category_hover_color' : 'archive_category_color';

    // 2. get_term_meta로 단일 값을 가져옴 (배열의 첫 번째 요소를 가져오기 위해 true 설정)
    $color = get_term_meta($term_id, $meta_key, true);

    // 3. 값이 비어있지 않으면 반환, 비어있으면 기본값 반환
    if (!empty($color)) {
        return $color;
    }

    return $default_color;
}



/* Y, 2026.3.19 (Block), 3.23
 * 포스트의 태그를 확인하여 Featured Image 위에 보일 badgeHTML을 반환하는 함수
 */
function get_dynamic_badge_on_featured_image($post_id) {
    if (!$post_id) return '';

    // 스타일 중복 등록 방지를 위한 static 변수
    static $style_registered = false;
    $style = '';

    if (!$style_registered) {
        $style = '
        <style>
            .yrkt-dynamic-badge-container {
                position: absolute;
                top: 10px;
                left: 10px;
                display: flex;
                flex-direction: column; /* 세로 나열, 가로 나열 row */
                align-items: flex-start; /* 배경색이 길어지는 것 방지 */
                gap: 5px;
                z-index: 99;
                width: auto;
                pointer-events: none; /* 클릭 방해 금지 */
            }
            .yrkt-dynamic-badge {
                display: inline-block;
                color: #ffffff;
                padding: 4px 6px;
                font-size: 11px;
                font-weight: bold;
                border-radius: 3px;
                text-transform: uppercase;
                box-shadow: 0 2px 4px rgba(0,0,0,0.2);
                line-height: 1.2;
                white-space: nowrap;
            }
        </style>';
        $style_registered = true;
    }

    $tags = get_the_tags($post_id);
    if (!$tags) return $style; // 스타일은 등록되었으나 태그가 없으면 스타일만 반환 가능성 대비

    $target_map = [
        'new'     => ['text' => 'NEW',     'color' => '#ff0000'],
        'hot'     => ['text' => 'HOT',     'color' => '#ff0000'],
        'selling' => ['text' => 'SELLING', 'color' => '#ff0000'],
        'shopify' => ['text' => 'SHOPIFY', 'color' => '#2b6cb0'],
        'sale'    => ['text' => 'SALE',    'color' => '#ff0000']
    ];

    $badge_elements = [];
    foreach ($tags as $tag) {
        $tag_slug = strtolower($tag->slug);
        if (isset($target_map[$tag_slug])) {
            $data = $target_map[$tag_slug];
            $badge_elements[] = '<div class="yrkt-dynamic-badge" style="background-color:' . $data['color'] . ' !important;">' . esc_html($data['text']) . '</div>';
        }
    }

    if (empty($badge_elements)) {
		return $style;
	} else {
        return $style . '<div class="yrkt-dynamic-badge-container">' . implode('', array_unique($badge_elements)) . '</div>';
	}
}



/* Y, 2026.3.23
 * 특정 포스트 ID가 Sticky(고정) 포스트인지 확인하는 함수
 */
function is_sticky_post_by_id($post_id) {
    $sticky_posts = get_option('sticky_posts');
    if (is_array($sticky_posts) && in_array($post_id, $sticky_posts)) {
        return true;
    }
    return false;
}


/* Y, 2026.3.11, 3.24
 * [subcategories_posts parent=10, row=3, column=4, excerpt_length=30] 에서 사용중
 * Argument parent: 10 or Software or software-slug or "4, 10" or "Semiconductor or 10" or "Semiconductor or Software"
 */
function get_subcategory_ids($parent_input) {
    // 1. 입력값이 없으면 빈 배열 반환
    if (empty($parent_input)) return [];

    // 2. 입력값을 배열로 변환 (콤마 기준 분리 및 공백 제거)
    // 만약 "10, Semiconductor" 라면 [ "10", "Semiconductor" ] 배열이 됨
    if (is_string($parent_input)) {
        $inputs = array_map('trim', explode(',', $parent_input));
    } else {
        $inputs = (array) $parent_input;
    }

    $all_parent_ids = [];

    // 3. 각 입력 항목을 순회하며 실제 카테고리 ID 찾기
    foreach ($inputs as $input) {
        $found_id = 0;

        if (is_numeric($input)) {
            $found_id = intval($input);
        } else {
            // 슬러그로 먼저 찾기
            $term = get_term_by('slug', $input, 'category');
            // 슬러그가 없으면 이름으로 찾기
            if (!$term) {
                $term = get_term_by('name', $input, 'category');
            }
            if ($term) {
                $found_id = $term->term_id;
            }
        }

        if ($found_id > 0) {
            $all_parent_ids[] = $found_id;
        }
    }

    // 중복 제거 (여러 입력이 동일한 카테고리를 가리킬 경우 대비)
    $all_parent_ids = array_unique($all_parent_ids);

    // 4. 찾은 모든 부모 ID들에 대해 자식 카테고리 수집
    $final_category_ids = [];
    foreach ($all_parent_ids as $p_id) {
        $final_category_ids[] = $p_id; // 부모 자신 포함
        
        $children = get_term_children($p_id, 'category');
        if (!is_wp_error($children) && is_array($children)) {
            $final_category_ids = array_merge($final_category_ids, $children);
        }
    }

    // 최종 결과 반환 (중복 제거 및 숫자 정렬)
    $final_category_ids = array_unique($final_category_ids);
    sort($final_category_ids);

    return $final_category_ids;
}