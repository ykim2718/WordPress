#!/usr/bin/env python3
"""Report posts that store rendered markdown instead of the [github_file] shortcode.

The site renders a GitHub document with its own [github_file] shortcode, which
reads the file when the page is viewed. A post that instead stores the markdown
already rendered to HTML looks identical on the page but is frozen: editing the
document on GitHub no longer reaches the site.

The two cannot be told apart in rendered HTML, because the shortcode expands to
the same github-readme-container markup a baked copy carries. So this reads
content.raw over ?context=edit, which is the stored post_content itself, and
that is the whole point of the check.

Every post is sorted into one of three:

    shortcode   carries [github_file ...]           -- what the site expects
    baked       carries the container, no shortcode -- drift, reported
    other       neither, an ordinary post           -- not this script's business

Exits 1 when anything is baked, so it can gate a workflow. Credentials come
from the environment, as they do for publish_markdown_post.py:

    WP_URL, WP_USERNAME, and WP_APP_PASSWORD or WP_PASSWORD

Changelog
    0.0.0  First version.
"""

from __future__ import annotations

__author__ = 'yRocket'
__version__ = "0.0.0.2026.8.31"  # Semantic Versioning: Major.Minor.Patch.Date(YYYY.M.D)

import argparse
import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from publish_markdown_post import Site  # noqa: E402  the same REST client and sign-in

__all__ = ['classify', 'survey']

SHORTCODE = re.compile(r'\[github_file[^\]]*\]')
CONTAINER = 'github-readme-container'
REQUIRED_ENVIRONMENT = ('WP_URL', 'WP_USERNAME')


def classify(*, content: str) -> str:
    """Which of the three a stored post_content is."""
    if SHORTCODE.search(content):
        return 'shortcode'
    return 'baked' if CONTAINER in content else 'other'


def survey(*, site: Site, statuses: str, per_page: int = 50) -> list[dict]:
    """Every post the account can see, with the style of each."""
    posts, page = [], 1
    while True:
        batch = site.call(
            path=f'/posts?context=edit&status={statuses}&per_page={per_page}&page={page}'
                 f'&orderby=date&order=desc&_fields=id,title,date,link,content')
        if not batch:
            break
        for post in batch:
            content = post['content']['raw']
            found = SHORTCODE.search(content)
            posts.append({
                'id': post['id'],
                'title': post['title']['raw'],
                'date': post['date'][:10],
                'link': post['link'],
                'style': classify(content=content),
                'stored': len(content),
                'shortcode': found.group(0) if found else '',
            })
        if len(batch) < per_page:
            break
        page += 1
    return posts


def main() -> int:
    parser = argparse.ArgumentParser(
        prog='check_post_style.py',
        description=f"check_post_style.py {__version__}\n\n{__doc__}",
        formatter_class=argparse.RawDescriptionHelpFormatter,
    )
    parser.add_argument('-v', '--version', action='version', version=__version__)
    parser.add_argument('--auth', default='application-password',
                        choices=['application-password', 'cookie'],
                        help='application password on the REST route, or a wp-login.php sign-in')
    parser.add_argument('--statuses', default='publish,draft,pending,private,future',
                        help='comma-separated post statuses to read')
    parser.add_argument('--list', choices=['baked', 'shortcode', 'all'], default='baked',
                        help='which posts to print, beyond the counts')
    parser.add_argument('--timeout', type=int, default=60, help='seconds to wait for a request')
    args = parser.parse_args()

    needed = REQUIRED_ENVIRONMENT + (
        ('WP_PASSWORD',) if args.auth == 'cookie' else ('WP_APP_PASSWORD',))
    missing = [name for name in needed if not os.environ.get(name, '').strip()]
    if missing:
        parser.error('these environment variables are needed: ' + ', '.join(missing))

    site = Site(url=os.environ['WP_URL'], username=os.environ['WP_USERNAME'],
                auth=args.auth, timeout=args.timeout)
    posts = survey(site=site, statuses=args.statuses)
    baked = [p for p in posts if p['style'] == 'baked']
    counts = {style: sum(1 for p in posts if p['style'] == style)
              for style in ('shortcode', 'baked', 'other')}
    print(f"{len(posts)} posts: {counts['shortcode']} shortcode, "
          f"{counts['baked']} baked, {counts['other']} other")

    if args.list in ('all', 'shortcode'):
        for p in posts:
            if args.list == 'all' or p['style'] == 'shortcode':
                print(f"  {p['date']} {p['id']:>6} {p['style']:<9} "
                      f"{p['stored']:>7}ch  {p['title'][:44]}")

    if not baked:
        print("no post stores rendered markdown")
        return 0
    print("\nthese posts store rendered markdown instead of the shortcode:")
    for p in baked:
        print(f"  {p['date']} {p['id']:>6} {p['stored']:>7}ch  {p['title'][:50]}\n"
              f"         {p['link']}")
    print("\nRepublish them with tools/publish_markdown_post.py, or replace the body with\n"
          "  [github_file user='USER' repo='REPO' file='PATH']")
    return 1


if __name__ == '__main__':
    raise SystemExit(main())
