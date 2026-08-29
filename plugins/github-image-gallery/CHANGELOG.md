## 1.1.0

- Add a **Refresh** button to the control bar. It throws away the cached
  listing for that folder and reads GitHub again, so a picture added minutes
  ago shows up without waiting out the `cache` minutes or visiting the plugins
  screen. The link carries a nonce; an expired one says so instead of quietly
  serving the cache. `show_refresh=0` hides the button.
- Start on **Newest first**. `sort` now defaults to `date_desc` rather than
  `name_asc`. A listing read through the contents API carries no dates, so
  there the gallery still starts on name order.

## 1.0.9

- Keep the vertical gap between tiles equal to the horizontal one. Themes
  that give `figure` and `figcaption` their own margins were adding that space
  under every caption, pushing the rows apart while the columns stayed at
  14px. Those margins are now held at zero inside the gallery.

## 1.0.8

- Actually hold the padding in the group dropdown. The reset added in 1.0.3
  was written as `.gig-drop-list li`, which loses to a theme rule like
  `.entry-content ul li` on specificity, so the checkboxes stayed indented on
  themes that style lists that way. The reset is now scoped under `.gig` and
  marked important.

## 1.0.7

- Spell the version label out as *version 1.0.7* rather than *v1.0.7*.

## 1.0.6

- Print the running version in small grey type at the top left of the gallery,
  so it is obvious from the page itself whether an update took effect.
  `show_version=0` hides it.

## 1.0.5

- Give the plugin one folder of its own. `src/`, `dist/` and `screenshots/`
  now sit under `plugins/github-image-gallery/` instead of being spread across
  three folders in `plugins/`, so a second plugin can be added beside it
  without the two getting tangled.
- Only `src/` goes into the zip, which drops the documents and screenshots
  from the download.

## 1.0.4

- Fill in this **View details** window: the description now carries screen
  captures of the gallery, the group dropdown and the right-click menu, and
  this changelog is generated from `CHANGELOG.md` at build time.
- The build script moved from shell to Python so it can render both documents
  into `version.json` alongside the zip.

## 1.0.3

- Even out the padding inside the group dropdown. Some themes indent `li`
  elements, which pushed the checkboxes inward while the count sat against the
  right edge. Both sides are now two characters wide.
- Drop the horizontal scrollbar that appeared when a group name was long.

## 1.0.2

- Give every caption the same two-line height, so a long file name no longer
  makes its row taller than its neighbours. The gap under a caption now
  matches the gap between columns.
- Add a right-click menu on the thumbnails: copy the GitHub image link address,
  copy as Markdown, copy the file name, open the image, open it on GitHub.
  Browsers do not let a page add entries to their own context menu, so this
  replaces it over the thumbnails only. `context_menu=0` turns it off.
- Fall back to `execCommand` when copying on a site that is not served over
  https, where the clipboard API is unavailable.

## 1.0.1

- Move the interface to English.
- Size the group dropdown to its widest entry and stop the names wrapping, so
  entries like *Margaret Hunt Hill Bridge* sit on one line.

## 1.0.0

- First release. A shortcode that renders a GitHub folder as a thumbnail grid,
  with groups derived from the file names, a multi-select dropdown, sorting by
  name or commit date, a name filter, and a lightbox.
- Read the listing from `index.json` when the folder has one, which avoids the
  GitHub API rate limit and serves small WebP thumbnails; fall back to the
  contents API when it does not.
- Update itself from `plugins/dist/version.json` in the repository, with no
  tags and no releases involved.
