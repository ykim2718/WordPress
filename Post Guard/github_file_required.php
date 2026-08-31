<?php
/* Y, 2026.8.31
 *
 * Keep a post that carries a GitHub document on the [github_file] shortcode.
 *
 * The shortcode reads the file from GitHub when the page is viewed, so editing
 * the document updates the site. A post that instead stores the markdown
 * already rendered to HTML looks the same on the page but is frozen at the
 * moment it was published, and nothing on the rendered page shows the
 * difference -- the shortcode expands into the same github-readme-container
 * markup a baked copy carries. That is what makes the mistake so quiet, and
 * why the check has to sit where the content is written.
 *
 * Two hooks, deliberately unequal:
 *
 *   rest_pre_insert_post  refuses the write. Every script, every agent and the
 *                         block editor itself save over REST, so this is the
 *                         path that actually needs closing.
 *   save_post             only marks the post. The other ways in (the classic
 *                         editor, XML-RPC, wp_insert_post from PHP) are the
 *                         author's own hands, and locking those is how a site
 *                         owner gets locked out of a post they meant to write.
 *
 * A post that legitimately quotes the markup -- an article about this very
 * problem, say -- would trip the refusal. Let it through with:
 *
 *     add_filter('allow_baked_github_html', '__return_true');
 *
 * or, for one post, by checking the $prepared post inside that filter.
 */

/* The condition both hooks share: the rendered container, and no shortcode. */
function _github_file_is_baked($content) {
	if (!is_string($content) || $content === '') {
		return false;
	}
	if (strpos($content, 'github-readme-container') === false) {
		return false;
	}
	return !preg_match('/\[github_file\b/', $content);
}

/* Refuse the write on the REST route, which is where scripts and the block
 * editor both land. WP_Error comes back to the caller as a 400 with the text. */
add_filter('rest_pre_insert_post', function ($prepared, $request) {
	$content = isset($prepared->post_content) ? $prepared->post_content : '';
	if (!_github_file_is_baked($content)) {
		return $prepared;
	}
	if (apply_filters('allow_baked_github_html', false, $prepared, $request)) {
		return $prepared;
	}
	return new WP_Error(
		'baked_github_html',
		'This post stores rendered markdown, which freezes it at publication. '
			. "Use the shortcode instead: [github_file user='USER' repo='REPO' file='PATH']",
		array('status' => 400)
	);
}, 10, 2);

/* Off the REST route, mark rather than refuse, so the author keeps the post. */
add_action('save_post_post', function ($post_id, $post) {
	if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
		return;
	}
	if (_github_file_is_baked($post->post_content)) {
		update_post_meta($post_id, '_baked_github_html', '1');
	} else {
		delete_post_meta($post_id, '_baked_github_html');
	}
}, 10, 2);

/* Say so in wp-admin, since a marked post looks perfectly fine on the site. */
add_action('admin_notices', function () {
	if (!current_user_can('edit_posts')) {
		return;
	}
	$flagged = get_posts(array(
		'post_type'      => 'post',
		'post_status'    => 'any',
		'numberposts'    => 20,
		'meta_key'       => '_baked_github_html',
		'meta_value'     => '1',
		'fields'         => 'ids',
		'suppress_filters' => false,
	));
	if (empty($flagged)) {
		return;
	}
	echo '<div class="notice notice-warning"><p><strong>'
		. esc_html(sprintf(
			_n(
				'%d post stores rendered markdown instead of the [github_file] shortcode.',
				'%d posts store rendered markdown instead of the [github_file] shortcode.',
				count($flagged),
				'default'
			),
			count($flagged)
		))
		. '</strong> '
		. esc_html('Editing the document on GitHub will not update them.')
		. '</p><ul style="list-style:disc;margin-left:2em">';
	foreach ($flagged as $post_id) {
		printf(
			'<li><a href="%s">%s</a></li>',
			esc_url(get_edit_post_link($post_id)),
			esc_html(get_the_title($post_id))
		);
	}
	echo '</ul></div>';
});
