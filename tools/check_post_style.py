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

Exits 1 when anything is baked, so it can gate a workflow. Credentials are taken
from the environment so they never reach the shell history. --auth
application-password sends WP_APP_PASSWORD on the REST route; --auth cookie
signs in at wp-login.php with the account's own password, for a site that has no
application password for the account:

    WP_URL, WP_USERNAME, and WP_APP_PASSWORD or WP_PASSWORD

Changelog
    0.0.0  First version.
    0.1.0  Carry the REST client here, rather than importing it from the
           publishing script that the github-to-wp-post skill replaced.
"""

from __future__ import annotations

__author__ = 'yRocket'
__version__ = "0.1.0.2026.8.31"  # Semantic Versioning: Major.Minor.Patch.Date(YYYY.M.D)

import argparse
import base64
import http.cookiejar
import json
import os
import re
import urllib.error
import urllib.parse
import urllib.request

__all__ = ['Site', 'classify', 'survey', 'report']

SHORTCODE = re.compile(r'\[github_file[^\]]*\]')
CONTAINER = 'github-readme-container'
REQUIRED_ENVIRONMENT = ('WP_URL', 'WP_USERNAME')


class Site:
    """The REST endpoints of the WordPress site, under one signed-in user.

    Two ways in, because the site does not always have an application password
    for the account. 'application-password' sends WP_APP_PASSWORD as HTTP Basic,
    which is what WordPress accepts on the REST route directly. 'cookie' signs in
    at wp-login.php with WP_PASSWORD, the account's own password, and then sends
    the login cookie together with the REST nonce, which is what a browser does.

    Only the reading half is here. The site is written to by the
    github-to-wp-post skill, and an auditor that cannot write cannot damage what
    it is auditing.
    """

    def __init__(self, *, url: str, username: str, auth: str, timeout: int) -> None:
        self.site = url.rstrip('/')
        self.root = self.site + '/wp-json/wp/v2'
        self.timeout = timeout
        self.token = ''
        self.nonce = ''
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.opener.addheaders = [('User-Agent', 'check_post_style')]
        if auth == 'cookie':
            self.sign_in(username=username, password=os.environ['WP_PASSWORD'])
        else:
            self.token = base64.b64encode(
                f"{username}:{os.environ['WP_APP_PASSWORD']}".encode()).decode()

    def sign_in(self, *, username: str, password: str) -> None:
        """Log in at wp-login.php and keep the cookie and the REST nonce."""
        form = urllib.parse.urlencode({
            'log': username, 'pwd': password, 'wp-submit': 'Log In',
            'redirect_to': self.site + '/wp-admin/', 'testcookie': '1',
        }).encode()
        self.opener.open(self.site + '/wp-login.php', timeout=self.timeout).read()
        with self.opener.open(urllib.request.Request(self.site + '/wp-login.php', data=form),
                              timeout=self.timeout) as response:
            landed = response.geturl()
            page = response.read().decode('utf-8', errors='replace')
        if 'wp-login.php' in landed:
            # WordPress answers a refused login with 200 and the form again, so the
            # message on the page is the only thing that says what went wrong.
            error = re.search(r'id="login_error"[^>]*>(.*?)</div>', page, re.S)
            reason = re.sub(r'<[^>]+>', ' ', error.group(1)).strip() if error else 'login refused'
            raise SystemExit(f"could not sign in as {username}: {re.sub(r'  +', ' ', reason)}")
        with self.opener.open(self.site + '/wp-admin/admin-ajax.php?action=rest-nonce',
                              timeout=self.timeout) as response:
            self.nonce = response.read().decode('utf-8').strip()

    def call(self, *, path: str) -> object:
        request = urllib.request.Request(self.root + path, method='GET')
        if self.token:
            request.add_header('Authorization', f'Basic {self.token}')
        if self.nonce:
            request.add_header('X-WP-Nonce', self.nonce)
        try:
            with self.opener.open(request, timeout=self.timeout) as response:
                return json.loads(response.read().decode('utf-8'))
        except urllib.error.HTTPError as error:
            detail = error.read().decode('utf-8', errors='replace')
            raise SystemExit(f"GET {path} refused ({error.code})\n{detail[:400]}") from error
        except urllib.error.URLError as error:
            raise SystemExit(f"could not reach {self.root}{path}: {error}") from error


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


def report(*, args: argparse.Namespace) -> int:
    """Read the site and print what each post stores; 1 when anything is baked."""
    site = Site(url=os.environ['WP_URL'], username=os.environ['WP_USERNAME'],
                auth=args.auth, timeout=args.timeout)
    posts = survey(site=site, statuses=args.statuses)
    if not posts:
        # An empty site and a broken query print the same counts, so say which.
        raise SystemExit('the site returned no posts at all; check WP_URL and --statuses')

    baked = [p for p in posts if p['style'] == 'baked']
    counts = {style: sum(1 for p in posts if p['style'] == style)
              for style in ('shortcode', 'baked', 'other')}
    print(f"{len(posts)} posts: {counts['shortcode']} shortcode, "
          f"{counts['baked']} baked, {counts['other']} other")

    if args.list in ('all', 'shortcode'):
        for post in posts:
            if args.list == 'all' or post['style'] == 'shortcode':
                print(f"  {post['date']} {post['id']:>6} {post['style']:<9} "
                      f"{post['stored']:>7}ch  {post['title'][:44]}")

    if not baked:
        print("no post stores rendered markdown")
        return 0
    print("\nthese posts store rendered markdown instead of the shortcode:")
    for post in baked:
        print(f"  {post['date']} {post['id']:>6} {post['stored']:>7}ch  {post['title'][:50]}\n"
              f"         {post['link']}")
    print("\nReplace each body with the two blocks the site's posts carry:\n"
          "  <!-- wp:shortcode -->\n"
          "  [github_file user='USER' repo='REPO' file='PATH']\n"
          "  <!-- /wp:shortcode -->")
    return 1


def parse_args() -> argparse.Namespace:
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

    # Auditing the whole site is the bare command, so no argument is required and
    # an empty command line is not an error.
    args = parser.parse_args()
    if args.timeout < 1:
        parser.error('--timeout must be at least 1 second')

    needed = REQUIRED_ENVIRONMENT + (
        ('WP_PASSWORD',) if args.auth == 'cookie' else ('WP_APP_PASSWORD',))
    missing = [name for name in needed if not os.environ.get(name, '').strip()]
    if missing:
        parser.error(
            'these environment variables are needed to read the site: ' + ', '.join(missing)
            + '\n  WP_URL           https://example.com/wordpress'
            + '\n  WP_USERNAME      the login name'
            + '\n  WP_APP_PASSWORD  xxxx xxxx xxxx xxxx, for --auth application-password'
            + '\n  WP_PASSWORD      the account password, for --auth cookie'
        )
    return args


if __name__ == '__main__':
    raise SystemExit(report(args=parse_args()))
