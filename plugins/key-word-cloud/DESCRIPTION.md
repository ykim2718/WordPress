Reads your published posts, counts the words in them, and prints the result as
a word cloud. One shortcode in a post is all it takes.

```
[wpwordcloud]
```

**Content or excerpt, your choice.** The plugin can read the post body or the
post excerpt. When it reads excerpts and a post has none, that post is skipped
rather than quietly swapped for its body; turn the fallback on in the settings
if you want the swap.

**Korean particles are stripped.** A rule-based pass removes the trailing
particle from a Korean word so that the same noun stops appearing three times.
`학교에서는`, `학교를` and `학교가` all land on `학교`. The particle list is
editable, and a minimum stem length guards short words. This is not a
morphological analyser: if a word like `고양이` gets cut to `고양`, raise the
minimum stem length or drop that particle from the list.

**Stopwords are yours to edit.** Korean and English defaults ship with the
plugin. The list is checked twice, once on the raw word and once after the
particle has been stripped.

**Every word sits horizontally.** No rotation, no vertical text, no canvas.
The cloud is plain anchors in a flex container, so it reflows on a phone and
stays selectable and readable.

**Click a word to see the posts.** Each word links to the site search for that
word, so the reader lands on the ordinary post list of your theme.

### What you can set

- Text source, content or excerpt, and whether an empty excerpt falls back to
  the body.
- Which post types to read, and how many of the newest posts to scan.
- Stopword list, Korean particle list, minimum stem length.
- Minimum word length, minimum number of occurrences, maximum number of words.
- Smallest and largest font size in px. Frequencies are spread between them on
  a square-root scale so one very common word does not flatten the rest.
- Start and end colour. Rare words get the first colour, common words the
  second, and everything in between is interpolated.
- Whether a word links to the search results or is plain text.
- How long the rendered cloud is cached. Saving the settings clears it.

### Shortcode attributes

Attributes override the saved settings for that one cloud. A bad value is
reported on the page instead of being silently replaced by a default.

- `source` — `content` or `excerpt`.
- `post_type` — comma separated. Public post types only.
- `category`, `tag` — slugs, to narrow the posts that are read.
- `limit` — how many of the newest posts to scan, 300 by default.
- `max` — how many words to draw, 60 by default.
- `min_count` — smallest number of occurrences a word needs.
- `min_len` — smallest number of characters a word needs.
- `min_size`, `max_size` — font size range in px, 12 and 44 by default.
- `color_start`, `color_end` — `#rrggbb`, rare and common.
- `link` — `search` or `none`.
- `cache` — cache seconds, `0` to skip the cache.

### Where the settings live

The sidebar of WP Admin gets a **Key Word Cloud** menu. Everything above is on
that one screen, with the current cloud rendered underneath as a preview and a
button that empties the cache.

PHP needs the `mbstring` extension. Without it the plugin says so in an admin
notice instead of producing a broken cloud.
