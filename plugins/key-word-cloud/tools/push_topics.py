#!/usr/bin/env python3
"""Upload clustered topics to the Key Word Cloud plugin.

Reads the clusters that cluster_keywords.py wrote and POSTs them to the
plugin's REST route, where they are stored and drawn by ranking=topics. The
whole pipeline -- LLM extraction, embedding, clustering -- runs on a machine
with a GPU; only this small result travels to the site.

Authentication is a WordPress application password, taken from the environment
so it never reaches the shell history:

    WP_URL, WP_USERNAME, WP_APP_PASSWORD

Changelog
    0.0.0  First version.
"""

from __future__ import annotations

__author__ = 'yRocket'
__version__ = "0.0.0.2026.8.28"  # Semantic Versioning: Major.Minor.Patch.Date(YYYY.M.D)

import argparse
import base64
import json
import os
import pathlib
import sys
import urllib.error
import urllib.request

__all__ = ['load_clusters', 'push']

REQUIRED_ENVIRONMENT = ('WP_URL', 'WP_USERNAME', 'WP_APP_PASSWORD')


def load_clusters(*, path: pathlib.Path, min_posts: int, limit: int) -> list[dict]:
    """Read the clusters and keep the ones worth drawing.

    Returns [{'label': str, 'posts': int, 'phrases': [str, ...]}, ...].
    """
    if not path.is_file():
        raise SystemExit(f"no such file: {path}. Run cluster_keywords.py first.")
    saved = json.loads(path.read_text(encoding='utf-8'))
    if 'clusters' not in saved:
        raise SystemExit(f"{path} has no 'clusters' key; it was not written by cluster_keywords.py")

    kept = [
        {'label': c['label'], 'posts': int(c['posts']), 'phrases': list(c['phrases'])}
        for c in saved['clusters'] if int(c['posts']) >= min_posts
    ]
    if not kept:
        raise SystemExit(
            f"none of the {len(saved['clusters'])} clusters reach --min-posts {min_posts}; "
            f"nothing would be uploaded"
        )
    kept.sort(key=lambda c: (-c['posts'], c['label']))
    return kept[:limit]


def push(*, topics: list[dict], generator: str, url: str, username: str, password: str,
         timeout: int) -> dict:
    """POST the topics and return the plugin's answer."""
    endpoint = url.rstrip('/') + '/wp-json/key-word-cloud/v1/topics'
    token = base64.b64encode(f"{username}:{password}".encode()).decode()
    payload = json.dumps({'generator': generator, 'topics': topics}).encode('utf-8')

    request = urllib.request.Request(endpoint, data=payload, method='POST')
    request.add_header('Authorization', f'Basic {token}')
    request.add_header('Content-Type', 'application/json')

    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            return json.loads(response.read().decode('utf-8'))
    except urllib.error.HTTPError as error:
        body = error.read().decode('utf-8', errors='replace')
        raise SystemExit(f"upload rejected ({error.code}) by {endpoint}\n{body[:400]}") from error
    except urllib.error.URLError as error:
        raise SystemExit(f"could not reach {endpoint}: {error}") from error


def run(*, args: argparse.Namespace) -> int:
    topics = load_clusters(path=pathlib.Path(args.input), min_posts=args.min_posts, limit=args.limit)
    print(f"{len(topics)} topics to upload, {topics[0]['posts']} posts at the top")
    for topic in topics[:10]:
        print(f"  {topic['posts']:>3}  {topic['label']}")
    if len(topics) > 10:
        print(f"  ... and {len(topics) - 10} more")

    if args.dry_run:
        print("\n--dry-run: nothing was sent")
        return 0

    answer = push(topics=topics, generator=args.generator, url=os.environ['WP_URL'],
                  username=os.environ['WP_USERNAME'], password=os.environ['WP_APP_PASSWORD'],
                  timeout=args.timeout)
    print(f"\nstored {answer.get('stored')} topics")
    rejected = answer.get('rejected') or []
    if rejected:
        # The site kept some and dropped others; saying only the good number would hide that.
        print(f"the site rejected {len(rejected)}: {'; '.join(rejected[:5])}", file=sys.stderr)
    return 0


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        prog='push_topics.py',
        description=f"push_topics.py {__version__}\n\n{__doc__}",
        formatter_class=argparse.RawDescriptionHelpFormatter,
    )
    parser.add_argument('-v', '--version', action='version', version=__version__)
    parser.add_argument('--input', default='cluster_keywords.json', help='output of cluster_keywords.py')
    parser.add_argument('--min-posts', type=int, default=2,
                        help='drop topics drawn from fewer posts than this')
    parser.add_argument('--limit', type=int, default=80, help='upload at most this many topics')
    parser.add_argument('--generator', default='llm+bge-m3', help='note stored beside the topics')
    parser.add_argument('--dry-run', choices=['true', 'false'], default='false',
                        help='print what would be sent and stop')
    parser.add_argument('--timeout', type=int, default=60, help='seconds to wait for the site')

    if len(sys.argv) == 1:
        parser.print_help()
        raise SystemExit(0)

    args = parser.parse_args()
    args.dry_run = args.dry_run == 'true'
    if args.min_posts < 1:
        parser.error('--min-posts must be at least 1')
    if args.limit < 1:
        parser.error('--limit must be at least 1')

    if not args.dry_run:
        missing = [name for name in REQUIRED_ENVIRONMENT if not os.environ.get(name, '').strip()]
        if missing:
            parser.error(
                'these environment variables are needed to upload: ' + ', '.join(missing)
                + '\n  WP_URL           https://example.com/wordpress'
                + '\n  WP_USERNAME      the login name'
                + '\n  WP_APP_PASSWORD  xxxx xxxx xxxx xxxx xxxx xxxx'
                + '\nUse --dry-run true to see the topics without uploading.'
            )
    return args


if __name__ == '__main__':
    raise SystemExit(run(args=parse_args()))
