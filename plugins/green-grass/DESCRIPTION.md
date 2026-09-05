Draws a stretch of days as GitHub's contribution calendar — one square per day,
darker where there was more of it. Insert the **Green Grass** block, or type the
shortcode.

```
[green_grass]
```

![A year of posts drawn as a horizontal calendar](calendar.png)

**Three things to count, one way to draw them.** The squares can stand for this
site's posts, its approved comments, or a GitHub account's contributions. Each
source answers the same question — how many on this day — so the layout, the
colours and the shading are shared, and a source is a radio button rather than a
different plugin.

**GitHub needs no token.** The contributions come from the public address the
profile page itself uses, so there is no OAuth app to register and no secret to
store. It is read once and cached. The trade is that it depends on GitHub's own
markup: if that changes, the calendar says so on the page instead of quietly
drawing an empty year.

**Horizontal or vertical.** Horizontal is the familiar shape, about 800px for a
full year, and it scrolls inside its own box on a narrow screen rather than
dragging the page sideways with it. Vertical runs the weeks downward instead,
which fits a sidebar or a narrow column. Both are the same grid with the axes
swapped, so everything else behaves identically.

![Five months drawn vertically](vertical.png)

**Any stretch of days.** Either a trailing window — the last twelve months, the
last six weeks — which moves forward a square a day, or a fixed `from` and `to`,
which stays put. Both ends are included, and a date that does not exist is
refused rather than rounded into the following month.

**Shading is relative, and you choose to what.** The four green levels divide
the counts that actually occurred, not an absolute scale, so the calendar says
*busy for you* rather than *busy*. Quartiles of the distinct counts is the
default and shrugs off a single enormous day; even steps up to the busiest day
is the other option, and matches how most such charts behave.

**Colours other than green.** The default is GitHub's own five greens, written
down rather than computed, because no formula reproduces them exactly. Pick a
different colour and the four levels are built from it by holding the hue and
raising the lightness, using the steps measured off GitHub's own ramp — so a
green of your own lands within a shade or two of the default, and a blue or a
red gets a ramp with the same rhythm.

**A square is a link.** Clicking a day opens that day's archive, or that day's
page on the GitHub profile. Days with nothing on them are not links, since they
would open an empty list. Links can be turned off entirely.

**Wrong values are shown, not swallowed.** A shortcode attribute that is out of
range, misspelled, or not a colour prints what was wrong and what was expected.
Silently falling back to the default is how an attribute gets typed three times
before anyone notices it never applied.

Settings live under **Green Grass** in the sidebar, and the same options are on
the block's own sidebar in the editor. A field left empty on the block follows
the settings screen — and names the value it is following, so there is no need
to open the settings screen to find out.
