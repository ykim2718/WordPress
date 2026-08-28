Draws the topics of your site as a word cloud. Insert the **Key Word Cloud**
block, or type the shortcode.

```
[wpwordcloud]
```

**The site does not count words.** Topics are prepared elsewhere — a language
model reads the posts, an embedding model groups the phrases it finds — and
uploaded to `/wp-json/key-word-cloud/v1/topics`. Counting can only rank the
words already in the text, so it never produces a phrase such as *within-wafer
variation*; grouping does. WordPress stores what arrives and draws it, which is
why the site needs no GPU and no extra PHP extension.

**Refreshed once a day.** The site fetches the published topics on a daily
schedule, so nobody has to log in to update the cloud. Fetching is not
analysing: a post written after the last pipeline run appears once the pipeline
runs again and republishes the file. The settings screen shows the last
attempt, the next run, and a button that fetches now.

**Sized by reach, not by repetition.** A topic is as large as the number of
posts it covers, spread on a square-root scale so one broad topic does not
flatten the rest. Its tooltip lists the phrases it was folded from.

**Draw one subject, or several.** Each topic carries the fields it belongs to,
named by you and sorted by the model, so a blog that writes about several
things can draw them apart: semiconductor and machine learning in one cloud,
applied statistics in another, on the same page. Tick as many fields as a
cloud should hold. The list comes from the topics that were uploaded, so it
grows with the pipeline rather than with the plugin.

**Korean, English, or both — without losing half the site.** Every topic
carries both an English and a Korean name, so a topic found in Korean posts is
drawn in English on an English cloud and the other way round. Choosing a
language chooses how the cloud reads, not which half of the writing survives.
Pick one on the settings screen or per cloud; English is the default, and
Both draws each topic under the name it was written in.

**Big in the middle, small at the edge.** Rows fill from the centre outwards
and each row centres its own largest phrase, so size falls away in every
direction from the middle of the cloud rather than only down the page.

**Pick the font.** Rounded, sans, serif, monospace, the theme's own, or one you
write yourself. Rounded is the default: a cloud reads better in a soft face
than in the stiff one a theme sets for body text. Nothing is downloaded — the
stacks name faces already on the device.

**Shaped as an ellipse, coloured to be read.** Rows narrow towards the top and
bottom so the cloud reads as an oval rather than a paragraph. Topics take five
hues so neighbours are told apart; the hue means nothing, size still means the
number of posts, and all five clear the colourblind and contrast checks because
here the colour *is* the text. Nothing rotates and nothing is vertical, so the
cloud reflows on a phone and stays selectable.

**Hover a topic to see what it is made of.** The tooltip lists the phrases the
topic was folded from, and how many posts it covers.

**The cloud says which version drew it.** The version sits faintly at the top
left, opposite the refresh button, small enough to ignore and there when you
look for it.

**Refresh without leaving the page.** Users who may edit posts get a small
button at the top right of the cloud that fetches the published topics now
instead of waiting for the daily schedule. Readers never see it.

**Click a topic to see the posts.** Each one links to the site search for it,
so the reader lands on the ordinary post list of your theme.

### What you can set

- Language: English, Korean, or both.
- The fields to draw, as many as you like. None ticked draws every field.
- The least number of posts a topic must cover, and how many topics to draw.
- Smallest and largest font size in px.
- The cloud's width and height in pixels. Leave them at 0 and the cloud takes
  the column's width and the height its shape implies.
- Start and end colour, interpolated between the smallest and the largest.
- Whether a topic links to the search results or is plain text.
- How long the rendered cloud is cached. Saving the settings clears it, and so
  does an upload.

### Shortcode attributes

Attributes override the saved settings for that one cloud. A bad value is
reported on the page instead of being silently replaced by a default.

- `language` — `en`, `ko`, or `both`.
- `fields` — the fields to draw, comma separated. Empty or `*` draws them all.
  A name no uploaded topic carries is an error listing the ones that exist.
- `min_posts` — a topic drawn from fewer posts than this is left out.
- `max` — how many topics to draw, 60 by default.
- `min_size`, `max_size` — font size range in px, 12 and 44 by default.
- `color_start`, `color_end` — `#rrggbb`, smallest and largest.
- `width`, `height` — the cloud's size in px, `0` for automatic. A width is a
  ceiling rather than a promise: a 900px cloud is 900px wide on a desktop and
  as wide as the column in a phone window, so it never pushes a scrollbar onto
  the page. A height replaces the ellipse ratio instead of arguing with it.
- `link` — `search` or `none`.
- `cache` — cache seconds, `0` to skip the cache.

### Where the block's settings live

The block's sidebar uses the editor's own tabs. Settings holds what is drawn --
language, fields, how many topics, what a click does. Styles holds how it looks
-- shape, size, font, colour. The cache sits under Advanced, being neither.

### Where the settings live

The sidebar of WP Admin gets a **Key Word Cloud** menu. It shows how many
topics have arrived and when, carries every setting above, renders the current
cloud as a preview, and has a button that empties the cache.
