## 2.15.0

- A click opens the posts the topic was counted from. The tooltip said 12 posts
  and the search it opened returned 3: the size counts the topic's phrases as
  well as its name, and the search knew only the name. The link now carries the
  topic, and the search query is answered with the posts that were counted, so
  the number and the list are the same thing. Checked on all 72 topics of the
  rehearsal site: 72 agree, 0 differ; `stochastic optimization` says 11 and
  lists 11.
- The heading still reads *Search Results for:* the topic, and a search typed
  by hand is untouched -- the same address without the topic returns what it
  always did.

## 2.14.1

- The settings screen says that posts filed under the RESTRICTED category are
  left out -- of the counting and of the list a click opens. The rule was in
  the code and in the README only, where the site's owner would not meet it.
  When no category of that name exists on the site, the screen says that too,
  so a name that does not match cannot look like a rule that does not work.

## 2.14.0

- Posts filed under the **RESTRICTED** category stay out of the cloud. They are
  not counted when a topic is sized, they are not in the list a click opens,
  and `tools/llm_keywords.py` no longer reads them, so their words cannot
  become a topic in the first place. On the rehearsal site 11 of 74 topics were
  sized against fewer posts afterwards, and a search that had returned 12 posts
  returned 8. The category is found by name, whatever its slug is, and the
  categories under it go with it.
- The Refresh button stays readable under the pointer. A theme that paints
  `button:hover` -- the theme on this site paints it #215387 -- put dark blue
  behind the button's dark letters and the word disappeared. It now sets its own
  hover background, a light grey, and turns bold.

## 2.13.1

- The counting is done when the topics arrive, not when someone visits. On the
  live site the first page view after an update waited 23 seconds for 74 topics
  to be counted against 835 posts. That work now happens in the request that
  stores the topics -- the daily fetch, an upload, or the Refresh button -- so
  a reader never pays for it. Drawing right after took 0.00s.

## 2.13.0

- The cloud is counted against the site's own writing. Pick where to look --
  the post body, the excerpt, pages -- and every topic is searched for there
  now, rather than being drawn at the size the pipeline measured on the day it
  ran. A post written since then counts, a deleted one stops counting, and a
  topic that no longer appears anywhere is not drawn. The body and the excerpt
  are ticked to begin with; tick nothing and the pipeline's own numbers are
  used, as before.
- Sizes can change order, so the topics are sorted after the recount and only
  then cut to the number asked for. Cutting first would have kept whichever
  topics were largest on the day the pipeline ran.
- Counting 74 topics against 835 posts took 4 seconds, so the counts are kept
  for a day of their own. Changing a colour or a size does not pay for it
  again, and Refresh or emptying the cache starts it over.
- The settings screen has a **Version** section above the models, saying which
  version is running.

## 2.12.2

- The settings screen said one model took three of the jobs without ever saying
  what the jobs were, and then listed models rather than jobs. It now lists the
  four steps in the order they run, each with the model that does it, so the
  sentence and the table say the same thing.

## 2.12.1

- The menu icon kept its colours. Handed over as a data URI it arrived white
  and blank: `wp-admin/js/svg-painter.js` takes base64 SVG menu icons and
  rewrites every `fill` to one admin-scheme colour, which is right for the
  one-colour icons WordPress ships and left this one a bare cloud. Passing the
  file's address instead makes WordPress hang it as an `<img>`, which that
  script does not touch. It costs one request per admin page and the browser
  caches it.
- The icon also appears at 56px beside the heading on the plugin's own screen.

## 2.12.0

- The settings screen says what the plugin is before it starts asking for
  settings: what a cloud is made of, that the chosen language is translated
  into before the keyword is picked, which two models do which part of the work
  and that neither of them runs on this site, and then the shortcode and the
  block. The count of uploaded topics follows all of that instead of opening
  the screen.
- The plugin has its own icon: KWC across a cloud, in the blues the cloud
  itself draws with. It is drawn for the 20px it gets in the admin menu, so the
  shape is flat and wide and the letters are stretched across it. The same file
  is offered to the plugin list and the View details window.

## 2.11.2

- The 2.11.1 upgrade did not fire on the sites it was written for. It decided
  whether the settings were old by looking inside them, and emptying the cache
  rewrites the settings with whatever the defaults are that day -- so the
  marker it looked for had already been filled in for it. What has been done is
  now recorded outside the settings, where nothing else writes.

## 2.11.1

- A site whose settings said "draw every field" did not get the three ticked
  fields of 2.11.0. A saved value beats a default, which is what keeps an
  update from overwriting a choice, but that value had been saved by sites that
  never chose it. It is replaced once -- unless fields were actually picked, in
  which case they stand.

## 2.11.0

- The fields are now declared on the settings screen instead of being read back
  out of the topics. A field nothing has been classified into yet was invisible,
  and being invisible it could not be picked, so nothing would ever be
  classified into it. The list now shows every field with its count, `0`
  included.
- The fields ship as data science, mathematics, semiconductor, sports and
  liberal arts, with the first three ticked. `machine learning` and `applied
  statistics` are gone; re-run `tools/label_fields.py` with the new names and
  publish, or the counts will read `0`.
- Language is radio buttons rather than a select in the block sidebar. Four
  answers fit in the room a closed select takes up.
- The block's field boxes start from what the settings screen ticks, the way
  every other field in that sidebar shows its setting. Unticking them all goes
  back to following the settings screen, since a cloud of no fields at all is
  not something to ask for.

## 2.10.0

- The block's sidebar shows the value it would use instead of the words "saved
  setting". A field left empty still follows the settings screen, but now it
  says what that is: `Setting: English`, a placeholder of `3600`. Reading the
  sidebar no longer means opening the settings screen to find out.
- **Least posts** is now **Least post count**, and both it and **Topics to
  draw** are sliders. Their ends come from the topics that are uploaded -- the
  first stops at the largest post count a topic has, the second at how many
  topics there are -- so neither offers a number that would do nothing. Reset
  puts a slider back to following the settings screen.
- The **Behaviour** panel is gone. What a click does is a decision for the
  whole site, and it was in the block sidebar without effect there: the editor
  preview has drawn its topics as plain text since 2.8.1, so the control could
  not show what it changed. The settings screen still holds it, and so does the
  shortcode's `link` attribute. A block that already carries a value keeps it.
- The Fields panel says what to do when there is nothing to tick, rather than
  naming a script and stopping.

## 2.9.0

- Choosing a language no longer hides half the site. Every topic now carries
  both an English and a Korean name, written by `tools/translate_topics.py`, so
  a topic found in Korean posts is drawn in English on an English cloud and the
  other way round. On the rehearsal site the English cloud went from 71 topics
  to 74 and the Korean one from 3 to 73.
- Only the name is translated. The phrases behind it are what the topic was
  folded from and stay as they were written, which is what the tooltip says
  they are.
- Two topics can translate to the same name. The one drawn from more posts is
  kept and the other dropped, rather than the two being added together: one
  post can sit in both topics, so a sum would claim more posts than the site
  has.
- Topics uploaded before this version keep working. Without both names the
  cloud falls back to the old rule and shows a topic only on the cloud of the
  language it was written in.
- The cloud's version is written faintly at its top left, opposite the refresh
  button, so what is on screen says which version drew it. The version is part
  of the cache key now, so an update cannot leave an old number, or an old
  drawing, in place.

## 2.8.1

- The editor no longer breaks when a topic is clicked. The preview's topics
  were real links, and the editor canvas is an iframe: clicking one navigated
  the canvas to the search results, the editor lost the document it was
  editing, and everything after that failed with `Cannot destructure property
  'documentElement' of 'D' as it is null` until the page was reloaded. The
  preview now draws the topics as plain text. Links on the published page are
  untouched.
- The settings screen's preview loses its links for the same reason: clicking
  a topic there walked out of the settings screen.

## 2.8.0

- Draw only the fields you pick. `tools/label_fields.py` asks the model which
  of the fields you name each topic belongs to and writes the answer onto the
  topic; the settings screen, the block sidebar, and the `fields` shortcode
  attribute then tick the ones to draw. A topic may sit in several fields, so
  semiconductor and machine learning can be one cloud and applied statistics
  another on the same page.
- The field list is read from the uploaded topics, not written into the
  plugin. Add a field to the pipeline and it appears in both screens by
  itself. A name that no topic carries is an error naming the ones that exist,
  because a typo that quietly draws nothing is the harder bug.
- The block's sidebar is grouped: Settings holds what is drawn, Styles holds
  how it looks, and the cache sits under Advanced. These are the editor's own
  tabs rather than tabs of our making.

## 2.7.0

- Set the cloud's width and height in pixels, from the settings screen, the
  block sidebar, or the `width` and `height` shortcode attributes. Both default
  to 0, which keeps the old behaviour: the column's width, and a height from
  the ratio.
- A pixel width is a maximum, not a promise. It is written as
  `min(Npx, 100%)`, so a 900px cloud is 900px on a desktop and 452px in a 500px
  window, and never puts a horizontal scrollbar on the page.
- A pixel height replaces the ratio rather than competing with it. Giving both
  a height and a ratio would be two answers to one question, so the height
  wins and the ratio is simply not read. When even the smallest text will not
  fit the height asked for, the console says how much room it actually needs.

## 2.6.0

- Keep the ellipse wide when the column is narrow. The shape had no target: it
  was whatever fell out of fitting the phrases into the available width, so
  moving the block inside a section turned a wide oval into a tall one. The
  layout now aims for a width-to-height ratio, 2:1 by default, and reaches it
  by scaling the text down when the column cannot hold the phrases at full
  size. Measured at a 700px window: 1.12:1 before, 1.95:1 after, at 0.76 scale.
- Leave the text alone when there is room. At a 1400px window the same cloud
  keeps its 44px maximum and comes out at 4.32:1 — the scale only ever drops,
  never rises, and it stops at 0.55 so the smallest topics stay readable.
- The ratio is a setting, on the settings screen, in the block sidebar, and as
  the `ratio` shortcode attribute, between 0.5 and 5.

## 2.5.0

- Draw the same ellipse in the editor as on the front end. The layout script
  was never loaded on the editor screen, so the preview fell back to the
  stylesheet alone — and the stylesheet stacked the topics vertically, which
  looked nothing like the published cloud.
- Stack vertically only once the rows exist. `flex-direction: column` was on
  the ellipse itself, so anywhere the script had not run the topics came out
  one per line. It now rides on a class the script adds after it has built the
  rows, and the fallback is the wrapped block it always should have been.
- Follow the cloud into the editor's canvas iframe, and keep watching it. The
  preview is fetched from the server and replaced wholesale whenever a setting
  changes; a one-time scan missed both the iframe, which is created after the
  page loads, and every later replacement.

## 2.4.1

- Separate the post count from the phrases with a colon in the tooltip: "3
  posts: reference display · reference number". A topic with no phrases behind
  it gets no colon, so the line never ends on one.

## 2.4.0

- Write everything a reader sees in English. The tooltip said 글 3개; it now
  says "3 posts", with the singular and plural split so one post does not read
  as "1 posts". The refresh button, its states, and the two on-page error
  messages moved over with it. The settings screen stays in Korean — that one
  is for the site's owner, not its readers.
- Make the refresh button visible. It was 11px grey text in the corner; it is
  now 13px with a darker border, it takes the cloud's own font, and it does not
  wrap when a theme sets `word-break` on buttons. It still appears only for
  users who may edit posts, so a reader who does not see it is not seeing a
  bug.
- Re-lay the cloud out whenever its width has actually changed, whatever
  reported the change, and skip the work when it has not. A ResizeObserver
  alone was not enough: it does not fire under every automation, and the cloud
  stayed as it was laid out at the wrong width.

## 2.3.1

- Do not lay the ellipse out against a container that is not on screen yet. A
  cloud measured at zero width produced 142 rows, 112 of them empty. The layout
  now waits until the container is at least 40px wide, watches it with a
  ResizeObserver so it runs when the cloud is revealed or its column changes,
  and never emits an empty row.

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
