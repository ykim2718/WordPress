/* Y, 2026.4.9
 * Shortcode: [page_break_navigator]
 */
add_shortcode('page_break_navigator', '_page_break_navigator');

function _page_break_navigator() {
    global $post, $page, $numpages, $pages;

    if (empty($pages)) {
        $content = apply_filters('the_content', $post->post_content);
        wp_link_pages(['echo' => 0]);
    }

    if ($numpages <= 1) return '';

    $headings = [];

    foreach ($pages as $index => $p) {
        if (preg_match('/<(h1|h2|h3)[^>]*>(.*?)<\/\1>/i', $p, $m)) {
            $headings[$index + 1] = trim(strip_tags($m[2]));
        } else {
            $headings[$index + 1] = "Page " . ($index + 1);
        }
    }

    ob_start();
    ?>

    <style>
    .k-page-nav { margin-bottom:20px; padding:10px 0; }
    .k-page-nav a, .k-page-nav span {
        display:block; padding:6px 10px; margin-bottom:6px;
        background:#eee; border-radius:4px; text-decoration:none;
        color:#333; font-weight:500;
    }
    .k-page-nav span { background:#333; color:#fff; }
    </style>

    <div class="k-page-nav">
        <?php foreach ($headings as $pnum => $title): ?>
            <?php if ($pnum == $page): ?>
                <span><?php echo $title . " — Page " . $pnum; ?></span>
            <?php else: ?>
                <?php echo _wp_link_page($pnum); ?>
                    <?php echo $title . " — Page " . $pnum; ?>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <?php
    return ob_get_clean();
}
