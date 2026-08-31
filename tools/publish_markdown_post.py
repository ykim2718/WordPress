#!/usr/bin/env python3
"""Publish a GitHub markdown document as a post on the WordPress site.

The site renders these documents with its own [github_file] shortcode, which
reads the file from GitHub when the page is viewed. A post therefore stores
just the lead image and one shortcode, and a later edit to the markdown shows
up on the site without republishing:

    <!-- wp:image {"width":"auto","height":"500px","sizeSlug":"large"} -->
    <figure class="wp-block-image size-large is-resized">
    <img src="IMAGE_URL" alt="" style="width:auto;height:500px"/></figure>
    <!-- /wp:image -->

    <!-- wp:shortcode -->
    [github_file user='USER' repo='REPO' file='PATH_IN_REPO']
    <!-- /wp:shortcode -->

The shortcode names user, repo and file and nothing else, so it always reads
the repository's default branch. A --markdown-url on any other branch is
refused rather than published as a post that quietly renders something else.

The lead image is linked from GitHub, the way the site's other posts link it,
and the same file is uploaded to the media library as the featured image. The
document is still fetched, but only to read the post title off its "# "
heading; the site, not this script, renders the markdown.

Categories are matched by name against the site and must already exist; the
site keeps one tree and this script does not add to it. The author is matched
by name the same way.

Tags are matched by name too, but they are written as one comma-separated
string, because a tag name often has a space in it and a space-separated list
cannot tell "Time Series" from two tags:

    --tags 'github-hosted, Time Series, PCA'

Several --tags arguments, and commas inside them, come to the same list. A tag
the site does not have stops the run, the way a category does; --create-tags
true adds it instead, which is how the site's one-off tags get made.

Credentials are taken from the environment so they never reach the shell
history. --auth application-password sends WP_APP_PASSWORD on the REST route;
--auth cookie signs in at wp-login.php with the account's own password, for a
site that has no application password for the account:

    WP_URL, WP_USERNAME, and WP_APP_PASSWORD or WP_PASSWORD

Changelog
    0.0.0  First version.
    0.1.0  The figure points at the uploaded attachment, not at the source URL.
    0.2.0  Post the site's [github_file] shortcode instead of markdown rendered
           here, which is what every other post on the site uses, and link the
           lead image from GitHub again to match them.
    0.3.0  Read --tags as a comma-separated string, and add --create-tags.
"""

from __future__ import annotations

__author__ = 'yRocket'
__version__ = "0.3.0.2026.8.31"  # Semantic Versioning: Major.Minor.Patch.Date(YYYY.M.D)

import argparse
import base64
import html
import http.cookiejar
import json
import mimetypes
import os
import pathlib
import re
import sys
import urllib.error
import urllib.parse
import urllib.request

__all__ = ['github_reference', 'tag_names', 'build_content', 'publish']

REQUIRED_ENVIRONMENT = ('WP_URL', 'WP_USERNAME')


def raw_url(*, url: str) -> str:
    """Turn a GitHub blob URL into the raw URL that serves the file itself."""
    parts = urllib.parse.urlsplit(url)
    if parts.netloc == 'raw.githubusercontent.com':
        return urllib.parse.urlunsplit((parts.scheme, parts.netloc, parts.path, '', ''))
    match = re.match(r'^/([^/]+)/([^/]+)/blob/(.+)$', parts.path)
    if parts.netloc != 'github.com' or not match:
        return url
    owner, repository, rest = match.groups()
    return f"https://raw.githubusercontent.com/{owner}/{repository}/{rest}"


def github_reference(*, url: str) -> dict:
    """Split a GitHub blob or raw URL into the parts the shortcode names."""
    parts = urllib.parse.urlsplit(url)
    if parts.netloc == 'github.com':
        match = re.match(r'^/([^/]+)/([^/]+)/blob/([^/]+)/(.+)$', parts.path)
    elif parts.netloc == 'raw.githubusercontent.com':
        match = re.match(r'^/([^/]+)/([^/]+)/([^/]+)/(.+)$', parts.path)
    else:
        raise SystemExit(f"not a GitHub URL, so it has no shortcode to write: {url}")
    if not match:
        raise SystemExit(f"cannot read user, repo and file out of {url}")
    user, repository, branch, path = (urllib.parse.unquote(p) for p in match.groups())
    # The attributes are single-quoted inside a bracketed shortcode, and there
    # is no escape for either character, so refuse rather than write it broken.
    for name, value in (('user', user), ('repo', repository), ('file', path)):
        if "'" in value or ']' in value:
            raise SystemExit(f"the shortcode cannot carry {name}={value!r}")
    return {'user': user, 'repository': repository, 'branch': branch, 'path': path}


def tag_names(*, values: list[str]) -> list[str]:
    """Split --tags on commas, keeping the order and dropping repeats.

    Space-separated words cannot carry a tag like "Time Series", so the names
    are written as one comma-separated string. Taking several arguments as well
    costs nothing and keeps an older command line working.
    """
    names, seen = [], set()
    for value in values:
        for name in value.split(','):
            name = name.strip()
            if name and name.casefold() not in seen:
                seen.add(name.casefold())
                names.append(name)
    return names


def fetch(*, url: str, timeout: int) -> bytes:
    """GET the URL and return its body."""
    request = urllib.request.Request(url, headers={'User-Agent': 'publish_markdown_post'})
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            return response.read()
    except urllib.error.HTTPError as error:
        raise SystemExit(f"could not read {url}: HTTP {error.code}") from error
    except urllib.error.URLError as error:
        raise SystemExit(f"could not reach {url}: {error}") from error


def default_branch(*, user: str, repository: str, timeout: int) -> str:
    """The branch the shortcode reads, or '' when GitHub will not say."""
    request = urllib.request.Request(
        f"https://api.github.com/repos/{user}/{repository}",
        headers={'User-Agent': 'publish_markdown_post', 'Accept': 'application/vnd.github+json'})
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            return json.loads(response.read().decode('utf-8')).get('default_branch', '')
    except (urllib.error.HTTPError, urllib.error.URLError, json.JSONDecodeError):
        return ''  # Rate-limited or private; the caller falls back to a default.


def read_source(*, source: str, timeout: int) -> bytes:
    """Read a local path or a URL, whichever was given."""
    if re.match(r'^https?://', source):
        return fetch(url=source, timeout=timeout)
    path = pathlib.Path(source)
    if not path.is_file():
        raise SystemExit(f"no such file: {source}")
    return path.read_bytes()


def document_title(*, markdown: str) -> str:
    """The first level-one heading, which is the post's title."""
    for line in markdown.splitlines():
        if line.startswith('# '):
            return re.sub(r'[`*_]', '', line[2:]).strip()
    raise SystemExit('the document has no "# " heading to take the post title from')


def build_content(*, reference: dict, image_url: str, image_height: int) -> str:
    """The lead image and the shortcode, in the blocks the site's posts use."""
    figure = (
        f'<!-- wp:image {{"width":"auto","height":"{image_height}px","sizeSlug":"large"}} -->\n'
        f'<figure class="wp-block-image size-large is-resized">'
        f'<img src="{html.escape(image_url, quote=True)}" alt="" '
        f'style="width:auto;height:{image_height}px"/></figure>\n'
        '<!-- /wp:image -->'
    )
    shortcode = (
        '<!-- wp:shortcode -->\n'
        f"[github_file user='{reference['user']}' repo='{reference['repository']}' "
        f"file='{reference['path']}']\n"
        '<!-- /wp:shortcode -->'
    )
    return f"{figure}\n\n{shortcode}"


class Site:
    """The REST endpoints of the WordPress site, under one signed-in user.

    Two ways in, because the site does not always have an application password
    for the account. 'application-password' sends WP_APP_PASSWORD as HTTP Basic,
    which is what WordPress accepts on the REST route directly. 'cookie' signs in
    at wp-login.php with WP_PASSWORD, the account's own password, and then sends
    the login cookie together with the REST nonce, which is what a browser does.
    """

    def __init__(self, *, url: str, username: str, auth: str, timeout: int) -> None:
        self.site = url.rstrip('/')
        self.root = self.site + '/wp-json/wp/v2'
        self.timeout = timeout
        self.token = ''
        self.nonce = ''
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.opener.addheaders = [('User-Agent', 'publish_markdown_post')]
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

    def call(self, *, path: str, method: str = 'GET', body: bytes | None = None,
             headers: dict | None = None) -> object:
        request = urllib.request.Request(self.root + path, data=body, method=method)
        if self.token:
            request.add_header('Authorization', f'Basic {self.token}')
        if self.nonce:
            request.add_header('X-WP-Nonce', self.nonce)
        for name, value in (headers or {}).items():
            request.add_header(name, value)
        try:
            with self.opener.open(request, timeout=self.timeout) as response:
                return json.loads(response.read().decode('utf-8'))
        except urllib.error.HTTPError as error:
            detail = error.read().decode('utf-8', errors='replace')
            raise SystemExit(f"{method} {path} refused ({error.code})\n{detail[:400]}") from error
        except urllib.error.URLError as error:
            raise SystemExit(f"could not reach {self.root}{path}: {error}") from error

    def term_ids(self, *, taxonomy: str, names: list[str], create: bool = False) -> list[int]:
        """Look the terms up by name, adding the missing ones only when asked."""
        found, missing = [], []
        for name in names:
            query = urllib.parse.urlencode({'search': name, 'per_page': 100})
            matches = self.call(path=f'/{taxonomy}?{query}')
            wanted = name.strip().lower().replace('-', ' ')
            exact = [t for t in matches if t['name'].strip().lower().replace('-', ' ') == wanted]
            if exact:
                found.append(exact[0]['id'])
            elif create:
                made = self.call(path=f'/{taxonomy}', method='POST',
                                 body=json.dumps({'name': name}).encode('utf-8'),
                                 headers={'Content-Type': 'application/json'})
                singular = {'tags': 'tag', 'categories': 'category'}.get(taxonomy, taxonomy)
                print(f"created the {singular} {name!r} as {made['id']}")
                found.append(made['id'])
            else:
                missing.append(name)
        if missing:
            addition = ('' if taxonomy != 'tags'
                        else ' Pass --create-tags true to add them instead.')
            raise SystemExit(
                f"the site has no {taxonomy} named: {', '.join(missing)}. "
                f"Create the term first, or name an existing one.{addition}"
            )
        return found

    def author_id(self, *, name: str) -> int:
        query = urllib.parse.urlencode({'search': name, 'per_page': 100})
        for user in self.call(path=f'/users?{query}'):
            if user['name'].strip().lower() == name.strip().lower():
                return user['id']
        raise SystemExit(f"the site has no user named {name}")

    def attachment_id(self, *, image: bytes, filename: str) -> int:
        """Reuse the attachment holding that file if it is there, otherwise upload it.

        Matched on the file the site serves rather than on the slug. A slug
        drifts -- WordPress will set it to the attachment id -- and a slug that
        no longer looked like its file name is how a second copy of the same
        image once reached the library.

        WordPress renames a colliding upload to name-1.jpg, so a suffixed file
        of the same size counts as the same image; without that, uploading over
        an earlier duplicate would quietly make a third copy.
        """
        stem = pathlib.Path(filename).stem
        suffixed = re.compile(re.escape(stem) + r'-\d+$', re.IGNORECASE)
        query = urllib.parse.urlencode({'search': stem, 'per_page': 100,
                                        '_fields': 'id,source_url,media_details'})
        for item in self.call(path=f'/media?{query}'):
            served = urllib.parse.unquote(item['source_url'].rsplit('/', 1)[-1])
            size = (item.get('media_details') or {}).get('filesize')
            same = served.lower() == filename.lower() or (
                suffixed.fullmatch(pathlib.Path(served).stem)
                and pathlib.Path(served).suffix.lower() == pathlib.Path(filename).suffix.lower()
                and size == len(image))
            if same:
                note = '' if served.lower() == filename.lower() else f" (as {served})"
                print(f"the media library already holds {filename} as {item['id']}{note}")
                return item['id']
        mime = mimetypes.guess_type(filename)[0] or 'application/octet-stream'
        uploaded = self.call(
            path='/media', method='POST', body=image,
            headers={'Content-Type': mime,
                     'Content-Disposition': f'attachment; filename="{filename}"'},
        )
        print(f"uploaded {filename} as attachment {uploaded['id']}")
        return uploaded['id']

    def create_post(self, *, payload: dict) -> dict:
        return self.call(path='/posts', method='POST',
                         body=json.dumps(payload).encode('utf-8'),
                         headers={'Content-Type': 'application/json'})


def publish(*, args: argparse.Namespace) -> dict:
    """Point a post at the document, then write it to the site."""
    reference = github_reference(url=args.markdown_url)
    branch = default_branch(user=reference['user'], repository=reference['repository'],
                            timeout=args.timeout) or 'main'
    if reference['branch'] != branch:
        raise SystemExit(
            f"the shortcode carries only user, repo and file, so it renders "
            f"{reference['repository']}@{branch}, but this URL is on "
            f"{reference['branch']!r}. Publish the document from {branch!r}."
        )

    markdown = read_source(source=raw_url(url=args.markdown_url),
                           timeout=args.timeout).decode('utf-8')
    title = args.title or document_title(markdown=markdown)
    content = build_content(reference=reference, image_url=args.image_url,
                            image_height=args.image_height)
    print(f"{title}\n  {reference['repository']}@{branch} {reference['path']}\n"
          f"  {len(content)} characters of post content")

    if args.write:
        destination = pathlib.Path(args.write)
        destination.parent.mkdir(parents=True, exist_ok=True)
        destination.write_text(content, encoding='utf-8')
        print(f"  wrote {destination}")
    if args.dry_run:
        print("--dry-run: nothing was sent to the site")
        return {}

    site = Site(url=os.environ['WP_URL'], username=os.environ['WP_USERNAME'],
                auth=args.auth, timeout=args.timeout)
    payload = {
        'title': title,
        'content': content,
        'status': args.status,
        'author': site.author_id(name=args.author),
        'categories': site.term_ids(taxonomy='categories', names=args.categories),
        'comment_status': 'open',
        'ping_status': 'open',
    }
    tags = tag_names(values=args.tags)
    if tags:
        payload['tags'] = site.term_ids(taxonomy='tags', names=tags,
                                        create=args.create_tags)
    if args.excerpt:
        payload['excerpt'] = args.excerpt
    if args.image_url:
        image = read_source(source=raw_url(url=args.image_url), timeout=args.timeout)
        filename = pathlib.Path(urllib.parse.urlsplit(args.image_url).path).name
        payload['featured_media'] = site.attachment_id(image=image, filename=filename)

    post = site.create_post(payload=payload)
    print(f"  post {post['id']} is {post['status']}: {post['link']}")
    return post


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        prog='publish_markdown_post.py',
        description=f"publish_markdown_post.py {__version__}\n\n{__doc__}",
        formatter_class=argparse.RawDescriptionHelpFormatter,
    )
    parser.add_argument('-v', '--version', action='version', version=__version__)
    parser.add_argument('--markdown-url', required=True,
                        help='GitHub URL of the markdown document, on its default branch')
    parser.add_argument('--image-url', required=True,
                        help='URL of the lead image, used as the figure and the featured image')
    parser.add_argument('--categories', nargs='+', required=True,
                        help='category names, which must already exist on the site')
    parser.add_argument('--author', required=True, help='display name of the post author')
    parser.add_argument('--status', default='draft',
                        choices=['publish', 'draft', 'pending', 'private'])
    parser.add_argument('--auth', default='application-password',
                        choices=['application-password', 'cookie'],
                        help='application password on the REST route, or a wp-login.php sign-in')
    parser.add_argument('--tags', nargs='*', default=[], metavar='NAMES',
                        help="comma-separated tag names, e.g. 'github-hosted, Time Series'")
    parser.add_argument('--create-tags', choices=['true', 'false'], default='false',
                        help='add a tag the site does not have yet, rather than stopping')
    parser.add_argument('--title', default='',
                        help='override the title taken from the "# " heading')
    parser.add_argument('--excerpt', default='', help='the post summary')
    parser.add_argument('--image-height', type=int, default=500,
                        help='height the figure is drawn at, in pixels')
    parser.add_argument('--dry-run', choices=['true', 'false'], default='false',
                        help='build the post and stop without sending it')
    parser.add_argument('--write', default='', help='also write the post content here')
    parser.add_argument('--timeout', type=int, default=60, help='seconds to wait for a request')

    if len(sys.argv) == 1:
        parser.print_help()
        raise SystemExit(0)

    args = parser.parse_args()
    args.dry_run = args.dry_run == 'true'
    args.create_tags = args.create_tags == 'true'
    if args.image_height < 1:
        parser.error('--image-height must be at least 1')

    if not args.dry_run:
        needed = REQUIRED_ENVIRONMENT + (
            ('WP_PASSWORD',) if args.auth == 'cookie' else ('WP_APP_PASSWORD',))
        missing = [name for name in needed if not os.environ.get(name, '').strip()]
        if missing:
            parser.error(
                'these environment variables are needed to publish: ' + ', '.join(missing)
                + '\n  WP_URL           https://example.com/wordpress'
                + '\n  WP_USERNAME      the login name'
                + '\n  WP_APP_PASSWORD  xxxx xxxx xxxx xxxx, for --auth application-password'
                + '\n  WP_PASSWORD      the account password, for --auth cookie'
                + '\nUse --dry-run true to build the post without sending it.'
            )
    return args


if __name__ == '__main__':
    publish(args=parse_args())
    raise SystemExit(0)
