/* Y, 2026.3.11
 * [subcategories_search parent=1408 title='']
 */
add_shortcode('subcategories_search', '_subcategories_search');
function _subcategories_search($atts) {
    $atts = shortcode_atts(array(
        'parent' => 0,
        'title'  => 'Search in Categories'
    ), $atts);

    $parent_id = intval($atts['parent']);
    $title = esc_html($atts['title']);

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
			transition: all 0.3s ease;
            border-radius: 4px;
        }
        .sub-search-button svg { width: 20px; height: 20px; fill: #555; }
		.sub-search-button:hover { background-color: #0073aa; }
        .sub-search-button:hover svg { fill: #ffffff; }

		.ss-modal {
			display: none;
			position: fixed;
			z-index: 99999;
			left: 0;
			top: 0;
			width: 100%;
			height: 100%;
			background-color: rgba(0,0,0,0.6);
		}
        .ss-modal-content {
            background-color: #fff;
            margin: 10% auto;
            padding: 40px 40px 15px 40px; /* 위, 오른쪽, 아래, 왼쪽 */
            border-radius: 8px;
            width: 500px;
            height: 300px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            box-shadow: 0 5px 25px rgba(0,0,0,0.5);
            position: relative;
        }
        .ss-modal-close-btn {
            background: #0073aa; color: white; border: none;
            padding: 5px 16px;
            border-radius: 5px; cursor: pointer;
            margin-top: auto;
            font-size: 14px;
        }
        .ss-modal-close-btn:hover { background: #005a87; }
        .ss-term-highlight { color: #d32f2f; font-weight: bold; }
    </style>

    <div class="sub-search-container">
        <div class="sub-search-title"><?php echo $title; ?></div>
        <form onsubmit="doSubSearch(event)" class="sub-search-form">
            <input type="text" id="ssInput" class="sub-search-input" placeholder="Enter your search..." required>
            <button type="submit" class="sub-search-button">
                <svg viewBox="0 0 24 24"><path d="M15.5 14h-.79l-.28-.27A6.471 6.471 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>
            </button>
        </form>
    </div>

    <div id="ssNoResultModal" class="ss-modal">
        <div class="ss-modal-content">
            <div style="margin-top: auto; text-align: center;">
                <p style="font-size: 24px; color: #333; font-weight: 500; margin: 0;">
                    No results for "<span id="ssResTerm" class="ss-term-highlight"></span>"
                </p>
                <p style="font-size: 16px; color: #666; margin: 10px 0 0 0;">Please try again with different keywords.</p>
            </div>
            <button class="ss-modal-close-btn" onclick="document.getElementById('ssNoResultModal').style.display='none'">Close</button>
        </div>
    </div>

    <script>
    function doSubSearch(e) {
        e.preventDefault(); // 폼 제출로 인한 페이지 새로고침(이상한 페이지 이동)을 즉시 중단

        const term = document.getElementById('ssInput').value;
        const parentId = <?php echo $parent_id; ?>;

        // AJAX로 결과가 있는지 먼저 확인합니다.
        // 이 과정은 페이지 이동 없이 백그라운드에서 일어납니다.
        const xhr = new XMLHttpRequest();
        xhr.open('POST', '<?php echo admin_url('admin-ajax.php'); ?>', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

        xhr.onload = function() {
            if (this.status === 200) {
                const res = JSON.parse(this.responseText);
                if (res.success && res.data.ids) {
                    // 결과가 있으면 즉시 최종 목적지로 이동 (이상한 페이지 거치지 않음)
                    window.location.href = "https://stellafire.com/home/search-results/?post_in=" + res.data.ids;
                } else {
                    // 결과가 없으면 현재 페이지에서 모달만 띄움
                    document.getElementById('ssResTerm').innerText = term;
                    document.getElementById('ssNoResultModal').style.display = 'block';
                }
            }
        };
        xhr.send('action=ss_lookup&term=' + encodeURIComponent(term) + '&parent=' + parentId);
    }
    </script>
    <?php
    return ob_get_clean();
}

// 서버 사이드 AJAX 핸들러 (반드시 필요)
add_action('wp_ajax_ss_lookup', 'ss_lookup_handler');
add_action('wp_ajax_nopriv_ss_lookup', 'ss_lookup_handler');
function ss_lookup_handler() {
    $term = sanitize_text_field($_POST['term']);
    $parent = intval($_POST['parent']);
    $ids = get_term_children($parent, 'category');
    $ids[] = $parent;

    $posts = get_posts([
        's' => $term,
        'category__in' => $ids,
        'posts_per_page' => -1,
        'fields' => 'ids'
    ]);

    if (!empty($posts)) {
        wp_send_json_success(['ids' => implode(',', $posts)]);
    } else {
        wp_send_json_error();
    }
}