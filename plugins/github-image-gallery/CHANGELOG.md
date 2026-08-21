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
