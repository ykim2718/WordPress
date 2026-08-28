#!/usr/bin/env python3
"""Label each clustered topic with the fields it belongs to.

The cloud draws whatever the blog wrote about, which mixes fields: a post on
EUV lithography and a post on a tax return produce topics that sit side by side
and mean nothing together. This pass asks the local model which of the fields
you name each topic belongs to, and writes the answer onto the topic. The
plugin then draws only the fields you tick, so one cloud can be semiconductor
and machine learning while another, on the same site, is applied statistics.

Fields are yours to name, not the model's to invent. Anything the model answers
that is not on your list is dropped and counted, so a model that starts
inventing categories is visible rather than quietly widening the vocabulary. A
topic may belong to several fields, or to none -- a topic in no field is kept
and simply never drawn by a field-filtered cloud.

The model runs on the same local Ollama the rest of the pipeline uses.

Changelog
    0.0.0  First version.
"""

from __future__ import annotations

__author__ = 'yRocket'
__version__ = "0.0.0.2026.8.28"  # Semantic Versioning: Major.Minor.Patch.Date(YYYY.M.D)

import argparse
import collections
import json
import pathlib
import re
import sys
import time
import urllib.error
import urllib.request
from typing import Union

__all__ = ['load_clusters', 'ask_fields', 'label_clusters']

PROMPT = (
    "You are sorting the topics of a blog into subject fields.\n"
    "The fields are exactly these, and no others:\n{fields}\n\n"
    "For each numbered topic below, answer with the fields it belongs to. A topic "
    "may belong to more than one field, and a topic that belongs to none of them "
    "gets an empty list. Judge the topic itself, not the words it happens to share "
    "with a field name.\n"
    "Answer with a JSON object mapping each topic's number, as a string, to an array "
    "of field names taken verbatim from the list above. Nothing else.\n\n"
    "TOPICS:\n{topics}"
)


def load_clusters(*, path: pathlib.Path) -> dict:
    """Read the clustering output whole, so the labels can be written back into it."""
    if not path.is_file():
        raise SystemExit(f"no such file: {path}. Run cluster_keywords.py first.")
    saved = json.loads(path.read_text(encoding='utf-8'))
    if 'clusters' not in saved or not saved['clusters']:
        raise SystemExit(f"{path} holds no clusters; it was not written by cluster_keywords.py")
    return saved


def ask_fields(*, batch: list[dict], fields: list[str], model: str, endpoint: str,
               timeout: int) -> dict[str, list[str]]:
    """Ask the model which fields each topic of one batch belongs to.

    Returns {index as string: [field, ...]} exactly as the model answered it;
    the caller is what checks the names. Raises when the request itself fails,
    because a dead endpoint must not read as "no topic matched any field".
    """
    lines = '\n'.join(
        f"{i}. {topic['label']}  [{', '.join(topic['phrases'][:6])}]"
        for i, topic in enumerate(batch)
    )
    payload = json.dumps({
        'model': model,
        'prompt': PROMPT.format(fields='\n'.join(f'- {f}' for f in fields), topics=lines),
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

    match = re.search(r'\{.*\}', answer, re.S)
    if not match:
        print(f"no JSON object in answer: {answer[:160]!r}", file=sys.stderr)
        return {}
    try:
        parsed = json.loads(match.group(0))
    except json.JSONDecodeError as error:
        print(f"bad JSON in answer ({error}): {match.group(0)[:160]!r}", file=sys.stderr)
        return {}
    return parsed if isinstance(parsed, dict) else {}


def label_clusters(*, clusters: list[dict], fields: list[str], model: str, endpoint: str,
                   timeout: int, batch_size: int) -> tuple[list[dict], collections.Counter]:
    """Write a 'fields' list onto every cluster.

    Returns the clusters and a counter of how many topics landed in each field,
    under the key 'invented' for names the model made up and '(none)' for topics
    that matched nothing.
    """
    allowed = {f.lower(): f for f in fields}
    tally: collections.Counter = collections.Counter()

    for start in range(0, len(clusters), batch_size):
        batch = clusters[start:start + batch_size]
        answered = ask_fields(batch=batch, fields=fields, model=model, endpoint=endpoint,
                              timeout=timeout)

        for index, topic in enumerate(batch):
            named = answered.get(str(index), [])
            if not isinstance(named, list):
                print(f"topic {start + index} ({topic['label']}): answer was not a list, "
                      f"got {named!r}", file=sys.stderr)
                named = []

            kept: list[str] = []
            for name in named:
                canonical = allowed.get(str(name).strip().lower())
                if canonical is None:
                    # A field nobody asked for is the model widening the vocabulary.
                    tally['invented'] += 1
                    print(f"topic {start + index} ({topic['label']}): dropped invented field "
                          f"{str(name)!r}", file=sys.stderr)
                    continue
                if canonical not in kept:
                    kept.append(canonical)

            topic['fields'] = kept
            if kept:
                tally.update(kept)
            else:
                tally['(none)'] += 1

        print(f"  labelled {min(start + batch_size, len(clusters))}/{len(clusters)}",
              end='\r', flush=True)

    print(' ' * 40, end='\r')
    return clusters, tally


def run(*, args: argparse.Namespace) -> int:
    fields = [f.strip() for f in args.fields.split(',') if f.strip()]
    saved = load_clusters(path=pathlib.Path(args.input))
    clusters = saved['clusters']
    print(f"{len(clusters)} topics, {len(fields)} fields: {', '.join(fields)}")

    started = time.time()
    clusters, tally = label_clusters(
        clusters=clusters, fields=fields, model=args.model, endpoint=args.endpoint,
        timeout=args.timeout, batch_size=args.batch,
    )
    print(f"labelled in {time.time() - started:.1f}s\n")

    for field in fields:
        print(f"{tally[field]:>5}  {field}")
    print(f"{tally['(none)']:>5}  (in none of them)")
    if tally['invented']:
        print(f"{tally['invented']:>5}  answers dropped as fields nobody asked for")
    if not any(tally[f] for f in fields):
        # Every topic in no field means the labelling failed, not that the blog is empty.
        raise SystemExit("no topic landed in any field; the labelling did not work")

    print(f"\n{'posts':>5}  topic -> fields")
    for cluster in sorted(clusters, key=lambda c: -c['posts'])[:args.top]:
        print(f"{cluster['posts']:>5}  {cluster['label']} -> {', '.join(cluster['fields']) or '-'}")

    saved['clusters'] = clusters
    saved['fields'] = fields
    output = pathlib.Path(args.output)
    output.write_text(json.dumps(saved, ensure_ascii=False, indent=1), encoding='utf-8')
    print(f"\nwrote {output}")
    return 0


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        prog='label_fields.py',
        description=f"label_fields.py {__version__}\n\n{__doc__}",
        formatter_class=argparse.RawDescriptionHelpFormatter,
    )
    parser.add_argument('-v', '--version', action='version', version=__version__)
    parser.add_argument('--input', default='cluster_keywords.json',
                        help='output of cluster_keywords.py')
    parser.add_argument('--output', default='label_fields.json',
                        help='where to write the same clusters with a fields list on each')
    parser.add_argument('--fields', required=True,
                        help='the fields to sort into, comma separated, '
                             'e.g. "semiconductor, machine learning, applied statistics"')
    parser.add_argument('--model', default='qwen3:8b', help='ollama model that does the sorting')
    parser.add_argument('--endpoint', default='http://localhost:11434', help='ollama base URL')
    parser.add_argument('--batch', type=int, default=20, help='topics per request')
    parser.add_argument('--top', type=int, default=30, help='how many labelled topics to print')
    parser.add_argument('--timeout', type=int, default=600, help='seconds to wait for one request')

    if len(sys.argv) == 1:
        parser.print_help()
        raise SystemExit(0)

    args = parser.parse_args()
    named = [f.strip() for f in args.fields.split(',') if f.strip()]
    if len(named) < 1:
        parser.error('--fields must name at least one field')
    if len(named) != len(set(f.lower() for f in named)):
        parser.error('--fields names the same field twice')
    if args.batch < 1:
        parser.error('--batch must be at least 1')
    return args


if __name__ == '__main__':
    raise SystemExit(run(args=parse_args()))
