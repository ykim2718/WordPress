/**
 * Shortcode to display the current post or page title.
 * Usage: [page_title]
 *
 * @return string The title of the current post/page.
 */
function show_page_title_shortcode() {
    // get_the_title() retrieves the title of the current post/page.
    return get_the_title();
}
// Register the shortcode with the tag [page_title]
add_shortcode( 'page_title', 'show_page_title_shortcode' );