/** Y, 2026.3.7 - 9, 3.13
 * 계층 구조와 체크박스가 포함된 카테고리 검색 리스트
 * 쇼트코드: [subcategories_list_with_filters_and_shopify  parent="0" shopify="true"]
 */
add_shortcode('subcategories_list_with_filters_and_shopify', '_subcategory_list_with_filters_and_shopify');
function _subcategory_list_with_filters_and_shopify($atts) {
    $a = shortcode_atts(array(
        'parent' => 0,
        'shopify' => 'true',   // find Shopify tag
		'search_result_page_id' => 1615
    ), $atts);
	$search_result_page_id = $a['search_result_page_id'];

    $categories = get_terms(array(
        'taxonomy'   => 'category',
        'parent'     => $a['parent'],
        'hide_empty' => false,
    ));

    if (empty($categories)) {
        return '<p>No categories to display.</p>';
    }

    $output = '<div class="cat-filter-wrapper" style="transform: translateZ(0);">';
    $output .= '<ul class="cat-check-list">';
    $output .= render_hierarchical_checkbox_list($categories, $a['parent']);
    $output .= '</ul>';

    // 검색 액션 영역
    $output .= '<div class="cat-search-action" style="display: flex; align-items: center; gap: 9px; margin-top: 7px;">';
	$output .= '<button type="button" id="btn-cat-search-conditionally">Search</button>';
	$output .= '<button type="button" id="btn-cat-check-all">Check All</button>';
    $output .= '</div>';

    // shopify true인 경우만 체크박스 표시
    if ($a['shopify'] === 'true') {
        $output .= '<div class="cat-selling-toggle" style="margin-top: 7px;">';
        $output .= '<label class="shopify-label" style="cursor: pointer; display: flex; align-items: center; gap: 5px; font-size: 0.95em;">';
        $output .= '<input type="checkbox" id="chk-shopify" style="-webkit-appearance: checkbox;"> Currently Selling';
        $output .= '</label>';
		$output .= '</div>';
    }

    $output .= '</div>';

    $ajax_url = admin_url('admin-ajax.php');

    $output .= "
    <script>
    function updateCheckAllButtonText() {
        const btn = document.getElementById('btn-cat-check-all');
        const allCheckboxes = document.querySelectorAll('.cat-checkbox');
        const checkedCount = document.querySelectorAll('.cat-checkbox:checked').length;
        if (allCheckboxes.length > 0 && checkedCount === allCheckboxes.length) {
            btn.innerText = 'Uncheck All';
        } else {
            btn.innerText = 'Check All';
        }
    }

    window.addEventListener('pageshow', function() {
        updateCheckAllButtonText();
    });

    document.getElementById('btn-cat-check-all').addEventListener('click', function() {
        const allCheckboxes = document.querySelectorAll('.cat-checkbox');
        const isCheckAllMode = this.innerText === 'Check All';
        allCheckboxes.forEach(cb => {
            cb.checked = isCheckAllMode;
        });
        updateCheckAllButtonText();
    });
	
    document.querySelectorAll('.cat-checkbox').forEach(cb => {
        cb.addEventListener('change', updateCheckAllButtonText);
    });
	
    document.getElementById('btn-cat-search-conditionally').addEventListener('click', function() {
        const checkedBoxes = document.querySelectorAll('.cat-checkbox:checked');
        const selectedIds = Array.from(checkedBoxes).map(cb => cb.value);

        const shopifyChk = document.getElementById('chk-shopify');
        const isShopifyChecked = shopifyChk ? shopifyChk.checked : false;

        if (selectedIds.length === 0) {
            alert('Please select a category.');
            return;
        }

        const targetUrl = '{$ajax_url}?action=filter_my_posts&cats=' + selectedIds.join(',') + '&selling_only=' + isShopifyChecked;

    fetch(targetUrl)
        .then(res => res.json())
        .then(result => {
            if (result.success && result.data.length > 0) {
                // 쇼트코드가 post_in을 인식하므로 그대로 전달
                const finalUrl = window.location.origin + '/?page_id={$search_result_page_id}&post_in=' + result.data.join(',');
                window.location.href = finalUrl;
            } else {
                alert('No products found matching the criteria.');
                btn.innerText = originalText;
                btn.disabled = false;
            }
        })
        .catch(err => {
            console.error('AJAX Error:', err);
            btn.innerText = originalText;
            btn.disabled = false;
        });
    });
    </script>
    <style>
        .cat-checkbox {
            cursor: pointer;
            -webkit-appearance: checkbox;
        }
        #btn-cat-search-conditionally, #btn-cat-check-all {
            width: auto;
            padding: 3px 10px;
            background-color: var(--global-palette-highlight, #2b6cb0);
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 1em;
            cursor: pointer;
			transition: background 0.2s, transform 0.1s;
        }
        #btn-cat-search-conditionally:active, #btn-cat-check-all:active {
            transform: scale(0.98);
        }
		#btn-cat-check-all { background-color: var(--global-palette-btn-bg, #2b6cb0); }
        #btn-cat-check-all:hover { filter: brightness(1.1); }
        .cat-item-row { display: flex; align-items: center; }
        .cat-link { text-decoration: none; color: inherit; }
        .children-indent {
            list-style: circle !important;
            padding-left: 25px;
            margin-top: 5px;
            display: block !important;
        }
        .children-indent li {
            margin-bottom: 3px;
        }
    </style>
    ";

    return $output;
}
function render_hierarchical_checkbox_list($categories, $parent_id) {
    $html = '';
    foreach ($categories as $category) {
        $cat_link = get_category_link($category->term_id);
        $html .= '<li>';
        $html .= '<div class="cat-item-row">';
        $html .= '<a href="' . esc_url($cat_link) . '" class="cat-link">';
        $html .= '<span class="cat-name">' . esc_html($category->name) . '</span>';
        $html .= '</a>';
        $html .= '<span class="cat-count">&nbsp(' . esc_html($category->count) . ')</span>';
        $html .= '<input type="checkbox" value="' . esc_attr($category->term_id) . '" class="cat-checkbox" style="margin-left:10px">';
        $html .= '</div>';

        $children = get_terms(array(
            'taxonomy'   => 'category',
            'parent'     => $category->term_id,
            'hide_empty' => false,
        ));

        if (!empty($children)) {
            $html .= '<ul class="children-indent">';
            $html .= render_hierarchical_checkbox_list($children, $category->term_id);
            $html .= '</ul>';
        }
        $html .= '</li>';
    }
    return $html;
}
add_action('wp_ajax_filter_my_posts', 'ajax_filter_my_posts_handler');
add_action('wp_ajax_nopriv_filter_my_posts', 'ajax_filter_my_posts_handler');
function ajax_filter_my_posts_handler() {
    global $wpdb;

    $cats = isset($_GET['cats']) ? explode(',', $_GET['cats']) : array();
    $selling_only = isset($_GET['selling_only']) ? $_GET['selling_only'] : 'false';

    if (empty($cats)) wp_send_json_error('No categories provided');

    $cat_ids = array_map('intval', $cats);
    $in_clause = implode(',', $cat_ids);

    // 기본 조인 및 조건 (카테고리 필터링)
    $joins = "
        INNER JOIN {$wpdb->term_relationships} tr_cat ON (p.ID = tr_cat.object_id)
        INNER JOIN {$wpdb->term_taxonomy} tt_cat ON (tr_cat.term_taxonomy_id = tt_cat.term_taxonomy_id)
    ";
    $where_clause = "tt_cat.term_id IN ($in_clause) AND tt_cat.taxonomy = 'category' AND p.post_status = 'publish' AND p.post_type = 'post'";

    // Currently Selling(shopify 태그)이 체크된 경우 추가 조인 및 필터링
    if ($selling_only === 'true') {
        $joins .= "
            INNER JOIN {$wpdb->term_relationships} tr_tag ON (p.ID = tr_tag.object_id)
            INNER JOIN {$wpdb->term_taxonomy} tt_tag ON (tr_tag.term_taxonomy_id = tt_tag.term_taxonomy_id)
            INNER JOIN {$wpdb->terms} t_tag ON (tt_tag.term_id = t_tag.term_id)
        ";
        
        $where_clause .= " AND tt_tag.taxonomy = 'post_tag' AND LOWER(t_tag.name) = 'shopify'";
    }

    $sql = "
        SELECT DISTINCT p.ID
        FROM {$wpdb->posts} p
        $joins
        WHERE $where_clause
    ";

    $results = $wpdb->get_col($sql);

    if (empty($results)) {
        wp_send_json_success(array());
    } else {
        wp_send_json_success($results);
    }
}