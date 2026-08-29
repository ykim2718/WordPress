#!/usr/bin/env python3
"""Extract keywords from WordPress posts with a local Ollama model.

Two passes. The first asks the model for the key phrases of each post. Those
phrases are almost all unique to their post -- good as per-post tags, useless
as a cloud, because nothing repeats and so nothing grows. The second pass hands
the whole phrase list back to the model and asks it to fold the phrases into a
small set of topics, which is what a cloud needs.

Results are written to a JSON file so the second pass can be re-run, and
re-tuned, without paying for the first again.

The database is read through the docker CLI, so the connection is named by the
environment rather than carried in this file:

    KWC_DB_CONTAINER, KWC_DB_USER, KWC_DB_PASSWORD, KWC_DB_NAME

Changelog
    0.3.0  Skip posts filed under the RESTRICTED category.
    0.2.0  Take the database connection from the environment.
    0.1.0  Add the topic pass and JSON output.
    0.0.0  First version.
"""

from __future__ import annotations

__author__ = 'yRocket'
__version__ = "0.3.0.2026.8.28"  # Semantic Versioning: Major.Minor.Patch.Date(YYYY.M.D)

import argparse
import collections
import html
import json
import os
import pathlib
import re
import subprocess
import sys
import time
import urllib.error
import urllib.request
from typing import Union

__all__ = ['fetch_posts', 'extract_keywords', 'build_topics', 'ask_model']

# Posts filed here are kept out of the cloud, so their words must not shape the topics either.
# Matched by category name, case-insensitively, because the slug differs from site to site.
RESTRICTED_CATEGORY = 'restricted'

PHRASE_PROMPT = (
    "Read the article below and list the key phrases a reader would use to find it.\n"
    "Rules: 3 to 6 phrases, one to three words each, lower case, taken from the "
    "article's own vocabulary, no generic words like data or model on their own.\n"
    "Answer with a JSON array of strings and nothing else.\n\n"
    "ARTICLE:\n{body}"
)

TOPIC_PROMPT = (
    "Below is a list of key phrases taken from the articles of one blog, with the "
    "number of articles each phrase came from.\n"
    "Fold them into {topics} topics that describe what this blog writes about.\n"
    "Rules: one to three words each, lower case, a topic must cover several of the "
    "phrases, keep the blog's own vocabulary, no topic so broad it says nothing.\n"
    "Answer with a JSON object mapping each topic to the array of phrases it covers, "
    "and nothing else. Every phrase goes to exactly one topic.\n\n"
    "PHRASES:\n{phrases}"
)


def fetch_posts(*, container: str, db_user: str, db_password: str, db_name: str,
                limit: int, chars: int) -> list[dict]:
    """Read published posts from the mirror database through the docker CLI.

    Posts filed under RESTRICTED_CATEGORY are left out; the cloud does not draw them either.

    Returns a list of {'id': int, 'title': str, 'body': str}.
    """
    # A post body holds newlines and tabs, which would break the row-per-line batch
    # format. JSON-encode each row inside MariaDB so one row really is one line.
    query = (
        "SELECT JSON_OBJECT('id', ID, 'title', post_title, "
        "'body', LEFT(post_content, {chars})) FROM wp_posts "
        "WHERE post_status='publish' AND post_type='post' "
        "AND CHAR_LENGTH(post_content) > 400 "
        "AND ID NOT IN ("
        "  SELECT tr.object_id FROM wp_term_relationships tr"
        "  JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id"
        "  JOIN wp_terms t ON t.term_id = tt.term_id"
        "  WHERE tt.taxonomy = 'category' AND t.name = '{restricted}') "
        "ORDER BY post_date DESC LIMIT {limit}"
    ).format(chars=chars, limit=limit, restricted=RESTRICTED_CATEGORY)

    completed = subprocess.run(
        ['docker', 'exec', container, 'mariadb', '-u', db_user, f'-p{db_password}',
         db_name, '--batch', '--raw', '--skip-column-names', '-e', query],
        capture_output=True, text=True, encoding='utf-8', errors='replace',
    )
    if completed.returncode != 0:
        raise RuntimeError(f"mariadb query failed ({completed.returncode}): {completed.stderr.strip()}")
    if not completed.stdout.strip():
        raise RuntimeError("mariadb returned no rows; check the container name and credentials")

    posts: list[dict] = []
    for line in completed.stdout.splitlines():
        if not line.strip():
            continue
        try:
            row = json.loads(line)
        except json.JSONDecodeError as error:
            # Dropping a row silently would understate the corpus; say so.
            print(f"skipped unparsable row ({error}): {line[:80]!r}", file=sys.stderr)
            continue
        body = re.sub(r'<[^>]+>', ' ', row['body'])
        body = re.sub(r'\[[^\]]+\]', ' ', body)
        body = html.unescape(body)
        body = re.sub(r'\s+', ' ', body).strip()
        posts.append({'id': int(row['id']), 'title': str(row['title']), 'body': body})
    return posts


def ask_model(*, prompt: str, model: str, endpoint: str, timeout: int, opener: str) -> Union[list, dict, None]:
    """Send one prompt and return the JSON value the model answered with.

    `opener` is '[' for an array answer or '{' for an object answer. Returns None
    when the answer holds no parsable JSON of that shape.
    """
    if opener not in ('[', '{'):
        raise ValueError(f"opener must be '[' or '{{', got {opener!r}")
    closer = ']' if opener == '[' else '}'

    payload = json.dumps({
        'model': model,
        'prompt': prompt,
        'stream': False,
        'think': False,
        'options': {'temperature': 0.0},
    }).encode('utf-8')

    request = urllib.request.Request(
        f'{endpoint}/api/generate', data=payload,
        headers={'Content-Type': 'application/json'},
    )
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            answer = json.loads(response.read().decode('utf-8')).get('response', '')
    except (urllib.error.URLError, TimeoutError) as error:
        raise RuntimeError(f"ollama request failed: {error}") from error

    if not answer.strip():
        print("model returned an empty answer", file=sys.stderr)
        return None

    match = re.search(re.escape(opener) + r'.*' + re.escape(closer), answer, re.S)
    if not match:
        print(f"no JSON {opener}{closer} in answer: {answer[:160]!r}", file=sys.stderr)
        return None
    try:
        return json.loads(match.group(0))
    except json.JSONDecodeError as error:
        print(f"bad JSON in answer ({error}): {match.group(0)[:160]!r}", file=sys.stderr)
        return None


def extract_keywords(*, body: str, model: str, endpoint: str, timeout: int) -> list[str]:
    """Ask the model for the key phrases of one article.

    Returns the phrases, or an empty list when the answer cannot be parsed.
    """
    value = ask_model(prompt=PHRASE_PROMPT.format(body=body), model=model,
                      endpoint=endpoint, timeout=timeout, opener='[')
    if not isinstance(value, list):
        return []
    return [str(p).strip().lower() for p in value if str(p).strip()]


def build_topics(*, counter: collections.Counter, topics: int, model: str, endpoint: str,
                 timeout: int) -> dict[str, list[str]]:
    """Fold the phrase list into topics.

    Returns {topic: [phrase, ...]}. Raises when the model gives nothing usable,
    because an empty topic set is the whole point of this pass failing.
    """
    listing = '\n'.join(f"{phrase} ({n})" for phrase, n in counter.most_common())
    value = ask_model(prompt=TOPIC_PROMPT.format(topics=topics, phrases=listing),
                      model=model, endpoint=endpoint, timeout=timeout, opener='{')
    if not isinstance(value, dict) or not value:
        raise RuntimeError("the topic pass returned no topics; see the parse errors above")

    grouped: dict[str, list[str]] = {}
    for topic, phrases in value.items():
        if not isinstance(phrases, list):
            print(f"topic {topic!r} did not map to a list; skipped", file=sys.stderr)
            continue
        grouped[str(topic).strip().lower()] = [str(p).strip().lower() for p in phrases if str(p).strip()]
    return grouped


def run(*, args: argparse.Namespace) -> int:
    output = pathlib.Path(args.output)

    if args.reuse_phrases:
        if not output.is_file():
            raise SystemExit(f"--reuse-phrases needs an existing {output}; run the first pass once")
        saved = json.loads(output.read_text(encoding='utf-8'))
        per_post = [(row['id'], row['title'], row['phrases']) for row in saved['posts']]
        print(f"reusing {len(per_post)} posts from {output}")
    else:
        posts = fetch_posts(
            container=args.container, db_user=args.db_user, db_password=args.db_password,
            db_name=args.db_name, limit=args.limit, chars=args.chars,
        )
        print(f"{len(posts)} posts read\n")

        per_post = []
        failures = 0
        started = time.time()
        for index, post in enumerate(posts, start=1):
            phrases = extract_keywords(
                body=post['body'], model=args.model, endpoint=args.endpoint, timeout=args.timeout,
            )
            if not phrases:
                failures += 1
            per_post.append((post['id'], post['title'], phrases))
            print(f"[{index}/{len(posts)}] {post['title'][:44]:<44} : {', '.join(phrases) or '(none)'}",
                  flush=True)

        elapsed = time.time() - started
        print(f"\npass 1: {len(posts)} posts in {elapsed:.1f}s "
              f"({elapsed / max(1, len(posts)):.1f}s each), {failures} unparsed")

    counter: collections.Counter = collections.Counter()
    for _, _, phrases in per_post:
        for phrase in dict.fromkeys(phrases):
            counter[phrase] += 1
    if not counter:
        raise SystemExit("no phrases at all; the first pass produced nothing to fold")
    print(f"{len(counter)} distinct phrases from {len(per_post)} posts")

    started = time.time()
    grouped = build_topics(counter=counter, topics=args.topics, model=args.model,
                           endpoint=args.endpoint, timeout=args.topic_timeout)
    print(f"pass 2: {len(grouped)} topics in {time.time() - started:.1f}s\n")

    # A topic's weight is how many posts its phrases came from, counting each post once.
    posts_of_phrase: dict[str, set] = collections.defaultdict(set)
    for post_id, _, phrases in per_post:
        for phrase in phrases:
            posts_of_phrase[phrase].add(post_id)

    weighted = []
    for topic, phrases in grouped.items():
        covered: set = set()
        for phrase in phrases:
            covered |= posts_of_phrase.get(phrase, set())
        weighted.append((len(covered), topic, len(phrases)))
    weighted.sort(reverse=True)

    print(f"{'posts':>5}  {'phrases':>7}  topic")
    for post_count, topic, phrase_count in weighted:
        print(f"{post_count:>5}  {phrase_count:>7}  {topic}")

    unassigned = set(counter) - {p for phrases in grouped.values() for p in phrases}
    if unassigned:
        print(f"\n{len(unassigned)} phrases were left out of every topic, e.g. "
              f"{', '.join(sorted(unassigned)[:8])}")

    output.write_text(json.dumps({
        'model': args.model,
        'posts': [{'id': i, 'title': t, 'phrases': p} for i, t, p in per_post],
        'topics': {topic: phrases for _, topic, _ in weighted for phrases in [grouped[topic]]},
        'weights': {topic: n for n, topic, _ in weighted},
    }, ensure_ascii=False, indent=1), encoding='utf-8')
    print(f"\nwrote {output}")
    return 0


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        prog='llm_keywords.py',
        description=f"llm_keywords.py {__version__}\n\n{__doc__}",
        formatter_class=argparse.RawDescriptionHelpFormatter,
    )
    parser.add_argument('-v', '--version', action='version', version=__version__)
    parser.add_argument('--model', default='qwen3:8b', help='ollama model tag')
    parser.add_argument('--endpoint', default='http://localhost:11434', help='ollama base URL')
    parser.add_argument('--limit', type=int, default=10, help='how many posts to read')
    parser.add_argument('--chars', type=int, default=4000, help='characters of each post to send')
    parser.add_argument('--topics', type=int, default=25, help='how many topics the second pass makes')
    parser.add_argument('--output', default='llm_keywords.json', help='where to write the result')
    parser.add_argument('--reuse-phrases', choices=['true', 'false'], default='false',
                        help='skip the first pass and read the phrases from --output')
    parser.add_argument('--timeout', type=int, default=300, help='seconds to wait for one post')
    parser.add_argument('--topic-timeout', type=int, default=900, help='seconds to wait for the topic pass')
    parser.add_argument('--container', default=os.environ.get('KWC_DB_CONTAINER', ''), help='database container')
    parser.add_argument('--db-user', default=os.environ.get('KWC_DB_USER', ''), help='database user')
    parser.add_argument('--db-password', default=os.environ.get('KWC_DB_PASSWORD', ''), help='database password')
    parser.add_argument('--db-name', default=os.environ.get('KWC_DB_NAME', 'wordpress'), help='database name')

    if len(sys.argv) == 1:
        parser.print_help()
        raise SystemExit(0)

    args = parser.parse_args()
    if not args.reuse_phrases == 'true':
        missing = [name for name, value in (('--container / KWC_DB_CONTAINER', args.container),
                                            ('--db-user / KWC_DB_USER', args.db_user),
                                            ('--db-password / KWC_DB_PASSWORD', args.db_password))
                   if not str(value).strip()]
        if missing:
            parser.error(
                'the first pass reads the database and needs: ' + ', '.join(missing)
                + '\nSet them in the environment, or pass --reuse-phrases true to skip that pass.'
            )
    if args.limit < 1:
        parser.error('--limit must be at least 1')
    if args.chars < 200:
        parser.error('--chars must be at least 200; less than that is not an article')
    if args.topics < 2:
        parser.error('--topics must be at least 2')
    args.reuse_phrases = args.reuse_phrases == 'true'
    return args


if __name__ == '__main__':
    raise SystemExit(run(args=parse_args()))
