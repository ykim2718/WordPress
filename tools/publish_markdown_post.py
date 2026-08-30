#!/usr/bin/env python3
"""Publish a GitHub markdown document as a post on the WordPress site.

The site stores these posts as the markdown already rendered to HTML, wrapped
in the same container GitHub uses, so the post reads on the site the way the
document reads on GitHub. A lead image sits above it as a figure, and the same
image is attached to the post as its featured image.

    <figure class="wp-block-image ..."><img src="IMAGE_URL" ...></figure>
    <p><div class="github-readme-container markdown-body">
       <div id="file" class="md" data-path="PATH_IN_REPO">
       <article class="markdown-body entry-content container-lg">RENDERED</article>
       </div></div></p>

GitHub's own renderer is not reachable from a script -- the rendered HTML only
comes back from the blob page -- so the markdown is rendered here to the same
shape: heading anchors, `dir="auto"`, tables in <markdown-accessiblity-table>,
fences in the highlight containers, and math left as $...$ inside a
<math-renderer> element, which is what the site's KaTeX pass reads.

Categories are matched by name against the site and must already exist; the
site keeps one tree and this script does not add to it. The author is matched
by name the same way.

Credentials are taken from the environment so they never reach the shell
history. --auth application-password sends WP_APP_PASSWORD on the REST route;
--auth cookie signs in at wp-login.php with the account's own password, for a
site that has no application password for the account:

    WP_URL, WP_USERNAME, and WP_APP_PASSWORD or WP_PASSWORD

Needs markdown-it-py, mdit-py-plugins and linkify-it-py:

    pip install markdown-it-py mdit-py-plugins linkify-it-py

Changelog
    0.0.0  First version.
"""

from __future__ import annotations

__author__ = 'yRocket'
__version__ = "0.0.0.2026.8.30"  # Semantic Versioning: Major.Minor.Patch.Date(YYYY.M.D)

import argparse
import base64
import hashlib
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

__all__ = ['render_markdown', 'build_content', 'publish']

REQUIRED_ENVIRONMENT = ('WP_URL', 'WP_USERNAME')

# The anchor GitHub puts beside every heading, copied so the posts already on
# the site and the posts this script writes carry the same markup.
OCTICON = (
    '<svg data-component="Octicon" class="octicon octicon-link" viewBox="0 0 16 16" '
    'version="1.1" width="16" height="16" aria-hidden="true"><path d="m7.775 3.275 1.25-1.25a3.5 '
    '3.5 0 1 1 4.95 4.95l-2.5 2.5a3.5 3.5 0 0 1-4.95 0 .751.751 0 0 1 .018-1.042.751.751 0 0 1 '
    '1.042-.018 1.998 1.998 0 0 0 2.83 0l2.5-2.5a2.002 2.002 0 0 0-2.83-2.83l-1.25 1.25a.751.751 '
    '0 0 1-1.042-.018.751.751 0 0 1-.018-1.042Zm-4.69 9.64a1.998 1.998 0 0 0 2.83 0l1.25-1.25a.751'
    '.751 0 0 1 1.042.018.751.751 0 0 1 .018 1.042l-1.25 1.25a3.5 3.5 0 1 1-4.95-4.95l2.5-2.5a3.5 '
    '3.5 0 0 1 4.95 0 .751.751 0 0 1-.018 1.042.751.751 0 0 1-1.042.018 1.998 1.998 0 0 0-2.83 '
    '0l-2.5 2.5a1.998 1.998 0 0 0 0 2.83Z"></path></svg>'
)

# GitHub names the lexer in the class, and the name is not always the fence's.
FENCE_LANGUAGE = {
    'sh': 'shell', 'bash': 'shell', 'zsh': 'shell', 'console': 'shell',
    'py': 'python', 'js': 'js', 'javascript': 'js', 'ts': 'ts', 'typescript': 'ts',
    'yml': 'yaml', 'md': 'gfm', 'markdown': 'gfm', 'c++': 'c++', 'cs': 'csharp',
}
# Fences GitHub has no lexer for; these get the plain container instead.
FENCE_PLAIN = {'', 'text', 'txt', 'plain', 'none', 'output', 'log'}


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


def repository_path(*, url: str) -> str:
    """The path of the document inside its repository, for the data-path attribute."""
    parts = urllib.parse.urlsplit(url)
    match = re.match(r'^/[^/]+/[^/]+/blob/[^/]+/(.+)$', parts.path)
    if match:
        return urllib.parse.unquote(match.group(1))
    match = re.match(r'^/[^/]+/[^/]+/[^/]+/(.+)$', parts.path)  # raw.githubusercontent.com
    return urllib.parse.unquote(match.group(1)) if match else parts.path.lstrip('/')


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


def read_source(*, source: str, timeout: int) -> bytes:
    """Read a local path or a URL, whichever was given."""
    if re.match(r'^https?://', source):
        return fetch(url=source, timeout=timeout)
    path = pathlib.Path(source)
    if not path.is_file():
        raise SystemExit(f"no such file: {source}")
    return path.read_bytes()


def slugify(*, text: str) -> str:
    """The heading id GitHub would give this text."""
    stripped = re.sub(r'[`*_$]', '', text).strip().lower()
    stripped = re.sub(r'[^\w\- ]', '', stripped, flags=re.UNICODE)
    return stripped.replace(' ', '-')


def render_markdown(*, markdown: str, run_id: str) -> str:
    """Render the markdown the way GitHub renders it in a repository page."""
    try:
        from markdown_it import MarkdownIt
        from mdit_py_plugins.dollarmath import dollarmath_plugin
    except ImportError as error:  # A wrong answer here is a mangled post, so stop.
        raise SystemExit(
            'this script needs markdown-it-py, mdit-py-plugins and linkify-it-py:\n'
            '    pip install markdown-it-py mdit-py-plugins linkify-it-py'
        ) from error

    parser = MarkdownIt('gfm-like', {'html': True, 'xhtmlOut': False})
    parser.use(dollarmath_plugin, double_inline=True)
    rules = parser.renderer.rules

    def math_element(*, kind: str, body: str) -> str:
        display = 'block' if kind == 'display' else 'inline-block'
        return (f'<math-renderer class="js-{kind}-math" style="display: {display}" '
                f'data-run-id="{run_id}">{html.escape(body, quote=False)}</math-renderer>')

    def render_heading_open(tokens, index, options, env):
        level = tokens[index].tag
        text = tokens[index + 1].content
        anchor = slugify(text=text)
        label = html.escape(re.sub(r'[`*_$]', '', text), quote=True)
        env['heading_anchor'] = anchor
        env['heading_label'] = label
        return (f'<div class="markdown-heading" dir="auto">'
                f'<{level} class="heading-element" dir="auto">')

    def render_heading_close(tokens, index, options, env):
        anchor = env.get('heading_anchor', '')
        label = env.get('heading_label', '')
        return (f'</{tokens[index].tag}>'
                f'<a id="user-content-{anchor}" class="anchor" aria-label="Permalink: {label}" '
                f'href="#{anchor}">{OCTICON}</a></div>\n')

    def render_fence(tokens, index, options, env):
        token = tokens[index]
        language = (token.info or '').strip().split()[0].lower() if token.info.strip() else ''
        code = html.escape(token.content, quote=False)
        copyable = html.escape(token.content, quote=True)
        if language in FENCE_PLAIN:
            return (f'<div class="snippet-clipboard-content notranslate position-relative '
                    f'overflow-auto" dir="auto" data-snippet-clipboard-copy-content="{copyable}">'
                    f'<pre class="notranslate"><code>{code}</code></pre></div>\n')
        lexer = FENCE_LANGUAGE.get(language, language)
        return (f'<div class="highlight highlight-source-{html.escape(lexer, quote=True)} '
                f'notranslate position-relative overflow-auto" dir="auto" '
                f'data-snippet-clipboard-copy-content="{copyable}">'
                f'<pre>{code}</pre></div>\n')

    def render_with_direction(tokens, index, options, env):
        tokens[index].attrSet('dir', 'auto')
        return parser.renderer.renderToken(tokens, index, options, env)

    def render_cell_open(tokens, index, options, env):
        """GitHub writes the column alignment as an attribute, not as a style."""
        token = tokens[index]
        style = token.attrGet('style') or ''
        alignment = re.search(r'text-align:\s*(\w+)', style)
        if alignment:
            token.attrs = {k: v for k, v in token.attrs.items() if k != 'style'}
            token.attrSet('align', alignment.group(1))
        return parser.renderer.renderToken(tokens, index, options, env)

    def render_link_open(tokens, index, options, env):
        token = tokens[index]
        href = token.attrGet('href') or ''
        if re.match(r'^https?://', href):
            token.attrSet('rel', 'nofollow')
        return parser.renderer.renderToken(tokens, index, options, env)

    rules['heading_open'] = render_heading_open
    rules['heading_close'] = render_heading_close
    rules['fence'] = render_fence
    rules['code_block'] = render_fence
    rules['paragraph_open'] = render_with_direction
    rules['bullet_list_open'] = render_with_direction
    rules['ordered_list_open'] = render_with_direction
    rules['th_open'] = render_cell_open
    rules['td_open'] = render_cell_open
    rules['link_open'] = render_link_open
    rules['table_open'] = lambda *a: '<markdown-accessiblity-table><table>\n'
    rules['table_close'] = lambda *a: '</table></markdown-accessiblity-table>\n'
    rules['math_inline'] = lambda tokens, index, *a: math_element(
        kind='inline', body=f'${tokens[index].content}$')
    rules['math_inline_double'] = lambda tokens, index, *a: math_element(
        kind='display', body=f'$${tokens[index].content}$$')
    rules['math_block'] = lambda tokens, index, *a: '<p dir="auto">' + math_element(
        kind='display', body=f'$${tokens[index].content.strip()}$$') + '</p>\n'

    rendered = parser.render(markdown)
    # GitHub's sanitizer namespaces the ids an author writes by hand, so that a
    # link target in the document cannot collide with an id on the page.
    return re.sub(r'(<a\b[^>]*?\bid=")(?!user-content-)', r'\1user-content-', rendered)


def document_title(*, markdown: str) -> str:
    """The first level-one heading, which is the post's title."""
    for line in markdown.splitlines():
        if line.startswith('# '):
            return re.sub(r'[`*_]', '', line[2:]).strip()
    raise SystemExit('the document has no "# " heading to take the post title from')


def build_content(*, rendered: str, path: str, image_url: str, image_height: int) -> str:
    """Wrap the rendered markdown and the lead image the way the site's posts are wrapped."""
    figure = (
        '<!-- wp:image {"sizeSlug":"large","linkDestination":"none","className":"is-resized"} -->\n'
        f'<figure class="wp-block-image size-large is-resized">'
        f'<img src="{html.escape(image_url, quote=True)}" alt="" '
        f'style="width:auto;height:{image_height}px"/></figure>\n'
        '<!-- /wp:image -->'
    )
    article = (
        '<!-- wp:html -->\n'
        '<p><div class="github-readme-container markdown-body">'
        f'<div id="file" class="md" data-path="{html.escape(path, quote=True)}">'
        '<article class="markdown-body entry-content container-lg" itemprop="text">'
        f'{rendered}</article></div></div></p>\n'
        '<!-- /wp:html -->'
    )
    return f"{figure}\n\n{article}"


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

    def term_ids(self, *, taxonomy: str, names: list[str]) -> list[int]:
        """Look the terms up by name; every one of them has to exist already."""
        found, missing = [], []
        for name in names:
            query = urllib.parse.urlencode({'search': name, 'per_page': 100})
            matches = self.call(path=f'/{taxonomy}?{query}')
            wanted = name.strip().lower().replace('-', ' ')
            exact = [t for t in matches if t['name'].strip().lower().replace('-', ' ') == wanted]
            if exact:
                found.append(exact[0]['id'])
            else:
                missing.append(name)
        if missing:
            raise SystemExit(
                f"the site has no {taxonomy} named: {', '.join(missing)}. "
                f"Create the term first, or name an existing one."
            )
        return found

    def author_id(self, *, name: str) -> int:
        query = urllib.parse.urlencode({'search': name, 'per_page': 100})
        for user in self.call(path=f'/users?{query}'):
            if user['name'].strip().lower() == name.strip().lower():
                return user['id']
        raise SystemExit(f"the site has no user named {name}")

    def attachment_id(self, *, image: bytes, filename: str) -> int:
        """Reuse the attachment of that name if it is there, otherwise upload it."""
        stem = pathlib.Path(filename).stem
        query = urllib.parse.urlencode({'search': stem, 'per_page': 100, '_fields': 'id,slug'})
        for item in self.call(path=f'/media?{query}'):
            if item['slug'] == stem.lower():
                print(f"the media library already holds {filename} as {item['id']}")
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
    """Render the document, then write it to the site as one post."""
    markdown = read_source(source=raw_url(url=args.markdown_url),
                           timeout=args.timeout).decode('utf-8')
    title = args.title or document_title(markdown=markdown)
    path = repository_path(url=args.markdown_url)
    run_id = hashlib.md5(markdown.encode('utf-8')).hexdigest()
    rendered = render_markdown(markdown=markdown, run_id=run_id)
    content = build_content(rendered=rendered, path=path, image_url=args.image_url,
                            image_height=args.image_height)
    print(f"{title}\n  {path}, {len(markdown)} characters of markdown, "
          f"{len(content)} of post content")

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
    if args.tags:
        payload['tags'] = site.term_ids(taxonomy='tags', names=args.tags)
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
                        help='GitHub URL of the markdown document, or a local path')
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
    parser.add_argument('--tags', nargs='*', default=[], help='tag names, which must already exist')
    parser.add_argument('--title', default='',
                        help='override the title taken from the "# " heading')
    parser.add_argument('--excerpt', default='', help='the post summary')
    parser.add_argument('--image-height', type=int, default=500,
                        help='height the figure is drawn at, in pixels')
    parser.add_argument('--dry-run', choices=['true', 'false'], default='false',
                        help='render the post and stop without sending it')
    parser.add_argument('--write', default='', help='also write the post content here')
    parser.add_argument('--timeout', type=int, default=60, help='seconds to wait for a request')

    if len(sys.argv) == 1:
        parser.print_help()
        raise SystemExit(0)

    args = parser.parse_args()
    args.dry_run = args.dry_run == 'true'
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
                + '\nUse --dry-run true to render the post without sending it.'
            )
    return args


if __name__ == '__main__':
    publish(args=parse_args())
    raise SystemExit(0)
