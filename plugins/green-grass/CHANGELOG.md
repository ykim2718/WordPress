## 0.0.0

- First cut. A day is a square and a square is a shade of green, which is the
  whole idea.
- Counts this site's posts, its approved comments, or a GitHub account's
  contributions. The GitHub source reads the public address the profile page
  uses, so no token is needed; it handles the three shapes that markup has taken
  and reports a fourth rather than drawing an empty year.
- Horizontal and vertical, which are one grid with the axes swapped. A year is
  wider than most columns, so the grid scrolls inside its own box instead of
  taking the page with it.
- Any stretch of days: a trailing window that moves with today, or a fixed pair
  of dates that does not. `2026-02-31` is refused, not read as March 3rd.
- GitHub's five greens by default, or any colour of your own, with the four
  levels built by raising lightness along the steps measured off GitHub's ramp.
- Shortcode `[green_grass]`, a block in the **yRocket** group, and a settings
  screen. Writing `from` on the shortcode is enough — `period="dates"` is
  inferred rather than required, since forgetting it would silently ignore the
  dates.
