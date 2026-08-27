## 1.2.0

- Rank words by TF-IDF instead of raw frequency, and make that the default.
  Counting occurrences alone surfaced whatever the site says most often —
  *data*, *model*, *time*, *use* — which describes nothing. TF-IDF lowers a
  word that appears across every post and raises one that clusters in a few,
  which is what a keyword is. `ranking=count` restores the old behaviour.
- Add a floor on how many posts a word must appear in, as a share of the posts
  scanned, 10% by default. Without it TF-IDF rewards rarity so hard that a
  typo or a stray code fragment from a single post wins. On a corpus of 300
  posts the difference is *slug, katex, ucxxxxxxxxx* at 0% versus *wafer,
  yield, variance, manufacturing* at 10%.
- Show the number of posts a word came from, next to its occurrence count, in
  the tooltip.

## 1.1.1

- File the block under a **yRocket** group in the inserter. It was asking for
  the built-in `widgets` category, so it sat among the widget blocks. The
  plugin now registers the group and points the block at it.

## 1.1.0

- Add a **Key Word Cloud** block, so the cloud can be dropped in from the
  block inserter instead of typing the shortcode. Every setting is in the
  block sidebar, and a field left empty falls back to the saved setting rather
  than to a second copy of the defaults.
- Render the block on the server through the same code the shortcode uses, and
  preview it in the editor the same way, so the rules stay in one place.

## 1.0.0

- First release. A `[wpwordcloud]` shortcode that counts the words in the
  content or the excerpt of published posts and draws them as a cloud, with an
  options screen of its own in the WP Admin sidebar.
- Strip Korean particles with an editable rule list, guarded by a minimum stem
  length, applied at most twice so that `학교에서는` reaches `학교`.
- Edit the stopword list, in Korean and English, and check it again after a
  particle has been stripped.
- Size words between a smallest and a largest px value on a square-root scale,
  and colour them along a gradient from rare to common.
- Lay every word out horizontally. Rotation and vertical writing are held off
  in the stylesheet so a theme cannot reintroduce them.
- Link each word to the site search for it, so a click ends on the post list.
- Report a bad shortcode attribute, an empty result, or a missing `mbstring`
  extension on the page and in the PHP error log rather than drawing nothing.
- Update from `plugins/key-word-cloud/dist/version.json` in the repository,
  with no tags and no releases involved.
