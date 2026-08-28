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

**Korean, English, or both.** A topic counts as Korean when it holds a single
Hangul syllable. Pick one on the settings screen or per cloud; English is the
default.

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

**Refresh without leaving the page.** Users who may edit posts get a small
button at the top right of the cloud that fetches the published topics now
instead of waiting for the daily schedule. Readers never see it.

**Click a topic to see the posts.** Each one links to the site search for it,
so the reader lands on the ordinary post list of your theme.

### What you can set

- Language: English, Korean, or both.
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

### Where the settings live

The sidebar of WP Admin gets a **Key Word Cloud** menu. It shows how many
topics have arrived and when, carries every setting above, renders the current
cloud as a preview, and has a button that empties the cache.
