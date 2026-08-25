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
