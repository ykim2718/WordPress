## 2.3.0

- Put the largest topics in the middle and let them shrink towards the edge.
  Rows are now filled from the centre row outwards, and each row is arranged so
  its biggest phrase sits in the middle of the row, which gives a size gradient
  in both directions rather than only down the page. Measured on the demo
  cloud: 35.4px average in the middle fifth, 23.4px in the ring around it,
  18.5px at the edge.
- Choose the font: rounded, sans, serif, monospace, the theme's own, or one
  written by hand. Rounded is the default because a word cloud reads better in
  a soft face than in the stiff one a theme usually sets for body text. Nothing
  is downloaded; the stacks name faces that are already on the device, and on
  Windows, which has no rounded system face, rounded falls back to a soft sans.
- A hand-written font family is reduced to letters, digits, spaces, commas,
  quotes, hyphens and underscores before it reaches the style attribute, and
  what was removed is logged. `font=custom` with nothing usable left is refused
  on the page rather than quietly falling back.

## 2.2.0

- Lay the cloud out as an ellipse. Rows are built in JavaScript and each row is
  given the width the ellipse allows at its height, because CSS `shape-outside`
  cannot do it: that needs the box height up front, and the height depends on
  how many rows the phrases make. A row always takes at least one phrase, so a
  phrase wider than the row is never dropped. Without JavaScript the cloud stays
  the plain wrapped block it was.
- Colour the topics from a five-hue palette so neighbours are told apart. The
  hue carries no meaning — size still carries the number of posts — and the five
  were chosen by running the palette validator: they clear the colourblind
  separation floor and every one sits at 4.5:1 or better against the page, which
  matters here because the colour is the text. `color_mode=gradient` restores
  the single-hue ramp.
- Show the phrases a topic was folded from in a tooltip on hover, replacing the
  browser's own `title` bubble.
- Put a small refresh button at the top right of the cloud for users who may
  edit posts. It fetches the published topics now rather than tomorrow, through
  a REST route that checks the same capability, and reloads.
- Key the cloud cache on whether the reader may edit posts. Without that an
  editor was served the guest copy and the button was missing from it.

## 2.1.0

- Fetch the topics once a day instead of waiting for someone to push them. The
  plugin reads a JSON file over https on a WP-Cron schedule, the same way it
  already reads `version.json` to find updates, so a site behind a NAS needs no
  application password and no open port.
- The pipeline writes that file with `push_topics.py --write`, and it is
  committed to the repository as `dist/topics.json`.
- A failed fetch keeps the topics already stored and records what went wrong;
  the settings screen shows the last attempt, the next scheduled run, and a
  button that fetches now rather than tomorrow.
- Turning the daily fetch off unschedules it, and so does deactivating the
  plugin.

Fetching is not analysing. A post written after the last pipeline run does not
appear until the pipeline runs again and republishes the file.

## 2.0.0

- Keep only the uploaded topics and remove the two counting rankings. TF-IDF
  and raw frequency both rank single words, and a keyword is usually a phrase;
  once the topics were there the counting paths were a second answer to a
  question already answered, carrying a tokenizer, a stopword list, a Korean
  particle list and eight settings of their own. The plugin is now about half
  its former size and no longer needs the `mbstring` extension.
- Choose the language of the cloud: English, Korean, or both, English by
  default. A topic counts as Korean when it holds a single Hangul syllable.
- Show on the settings screen how many topics have arrived, when, and from
  which generator, so an empty cloud can be told from a stale one.
- **Breaking.** `ranking`, `source`, `post_type`, `category`, `tag`, `limit`,
  `min_count`, `min_len`, `min_docs_pct`, the stopword list and the particle
  settings are gone. `min_count` is now `min_posts` and counts posts, not
  occurrences. A cloud draws nothing until topics have been uploaded.

## 1.3.0

- Add `ranking=topics`, which draws topics prepared elsewhere instead of
  counting words here. TF-IDF ranks single words; a keyword is usually a
  phrase, and no amount of counting turns `wafer` and `variation` into
  `within-wafer variation`.
- Accept those topics at `POST /wp-json/key-word-cloud/v1/topics`, guarded by
  the `edit_posts` capability and stored in one option. A topic carries a
  label, the number of posts it covers, and the phrases it was folded from,
  which the tooltip shows. Uploading clears the cloud cache.
- Reject an upload that carries no usable topic rather than storing an empty
  set, and log which entries were dropped and why.

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
