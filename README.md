# WordPress

**This is a sandbox.** It is the scratch repository behind one personal
WordPress site — a place to keep code while it is being tried out, not a
product, not a library, and not something to install on a site you care about.
Nothing here carries a support promise, a stability promise, or a deprecation
policy. Files move, names change, and things are deleted the moment they stop
being interesting. Read it for ideas; copy at your own risk.

Two things follow from that, and they are worth saying plainly:

- **The snippet folders are drafts.** Most of the PHP outside `plugins/` was
  pasted into a Code Snippets box on a live site to see what would happen. It
  is kept here so it is not lost, not because it is finished. Assume little
  input validation, assume no tests.
- **The images are test data.** `Images/` exists so the gallery plugin has a
  real folder to read. The photographs are the author's own snapshots and
  generated thumbnails, and they get renamed and re-cropped whenever a layout
  question comes up.

## Layout

```
plugins/          finished enough to install: one folder per plugin
Images/           photographs and generated thumbnails, plus index.json
tools/            Python: builds the artifacts in plugins/*/dist and Images/,
                  publishes posts, and audits what the posts store
Shortcode/        single-file shortcodes pasted into Code Snippets
Custom Block/     a dynamic block, same story
Post Guard/       keeps a post on the [github_file] shortcode
Mongo REST API/   a chart fed from MongoDB over REST
WP Statistics/    a visitor map
YouTube/          latest-video embed
bbPress/          forum styling and tweaks
wordpress php functions (priority=1).php    debug helpers loaded first
```

## Plugins

Two, both real enough to install from a zip and both updating themselves from
this repository rather than from tags or releases.

| Plugin | What it does |
|---|---|
| [`github-image-gallery`](plugins/github-image-gallery) | Renders a folder of a public GitHub repository as a filterable thumbnail grid. Groups are derived from the file names and offered as a multi-select dropdown. |
| [`key-word-cloud`](plugins/key-word-cloud) | Draws a site's topics as a word cloud. The topics are prepared off-site by a language model and uploaded over REST; the site only stores and draws them. |

Each plugin folder has the same shape. Only `src/` is zipped, so the documents
and screenshots never reach the download.

```
plugins/<slug>/
├── src/            the plugin itself — this is what gets zipped
├── dist/           <slug>.zip and version.json, both built by CI
├── screenshots/    used by the View details window and the README
├── DESCRIPTION.md  becomes the Description tab
├── CHANGELOG.md    becomes the Changelog tab
└── README.md
```

WordPress checks `dist/version.json` for a newer version and downloads
`dist/<slug>.zip` when there is one. No tags, no GitHub releases.

## Build

`.github/workflows/image-index.yml` runs on every push that touches `Images/`,
the gallery plugin, or the build scripts, and commits whatever it regenerates.

| Script | Output |
|---|---|
| `tools/build_image_index.py` | `Images/index.json` and 480px WebP thumbnails under `Images/.thumbs/`. Unchanged pictures are skipped by sha1. |
| `tools/build_plugin_dist.py` | `plugins/<slug>/dist/` — the zip, plus `version.json` with the two documents rendered to HTML. Rebuilds only when the version in the plugin header changed. |
| `tools/publish_markdown_post.py` | Publishes a GitHub markdown document as a post on the site, rendered to the same HTML shape GitHub uses. |

`index.json` is why the gallery costs no GitHub API quota: the plugin fetches
that one static file from `raw.githubusercontent.com` instead of walking the
contents API, and gets commit dates, pixel sizes and thumbnail paths with it.

## Branches

One branch, `main`. Work is committed and pushed there directly. There are no
working branches and pull requests are not used.
