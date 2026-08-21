Turns a folder of a public GitHub repository into a filterable thumbnail
gallery. Put one shortcode in a post and the pictures in that folder appear as
a grid, with a control bar above it.

```
[github_image_gallery github_url=https://github.com/owner/repo/tree/main/Images]
```

![The gallery grid](gallery.jpg)

**Groups come from the file names.** Nothing has to be tagged by hand. The
plugin looks at the hyphens in each name and keeps the longest prefix that at
least two files share, so `harper-park-stone-sign.jpg` and
`harper-park-swing-bench.jpg` land together under **Harper Park**. Names that
share nothing go to **Other**. Pick several groups at once from the dropdown
and the grid narrows to them.

![Choosing several groups at once](group-filter.jpg)

**Right-click a thumbnail** to copy the address of the original image on
GitHub, copy it as Markdown, or open the file on GitHub. Copying gives the
full-size original, not the thumbnail.

![The right-click menu on a thumbnail](context-menu.jpg)

### How it reads the folder

If the folder contains an `index.json` built by `tools/build_image_index.py`,
the plugin fetches that single static file from `raw.githubusercontent.com`.
That costs no GitHub API quota, brings commit dates and pixel sizes with it,
and lets the grid load small WebP thumbnails instead of full-size originals.
Without it the plugin falls back to the GitHub contents API, which works but
spends the 60-per-hour anonymous rate limit and offers no date sorting.

### Shortcode attributes

- `github_url` — required. A `tree`/`blob` URL, or `owner/repo/path`.
- `column` — most columns to show, 4 by default. Narrow screens use fewer.
- `sort` — `name_asc`, `name_desc`, `date_desc`, `date_asc`.
- `sort_by_date` — `1` starts on newest first.
- `show_date`, `show_name`, `show_search` — turn caption and search parts off.
- `group_depth`, `min_group` — how eagerly names are grouped.
- `groups` — group slugs to have selected on load.
- `lightbox` — `0` opens the image in a new tab instead of an overlay.
- `context_menu` — `0` restores the browser's own right-click menu.
- `ratio` — tile shape, `4/3` by default. `auto` uses each picture's own.
- `limit`, `cache` — cap the number of tiles, and the listing cache in minutes.
