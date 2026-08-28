#!/usr/bin/env python3
"""Give every topic both an English and a Korean name.

A blog written in two languages produced two clouds that could not be read
together: choosing English hid everything written in Korean and choosing Korean
hid the rest. Half the site disappeared either way, and the half that showed
was not the half a reader picked -- it was the half that happened to be typed
in that script.

This pass asks the local model for the missing name of each topic, so a topic
found in Korean text can be drawn in English and the other way round. Only the
label is translated. The phrases behind it are what the topic was folded from
and stay as they were written, which is what the tooltip claims to show.

A name is written in one of the two languages or it is not a name at all. The
model has answered with Cyrillic and with CJK, so anything outside printable
ASCII and Hangul is dropped and counted.

Translation of a two-word technical phrase is where a small model is at its
worst, so the answer is checked rather than trusted. The two directions cannot
be checked the same way. An English name holding Hangul is plainly a failure
and is dropped. A Korean name holding none is not: `wp rest api` and `on-chip
sram` are what a Korean writer types, and rejecting them would hide those
topics from the Korean cloud, which is the very thing this pass exists to
prevent. A name that came back untouched is therefore kept and counted apart,
so a model that has stopped translating shows up as a number rather than as a
cloud that quietly stayed English.

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

__all__ = ['load_clusters', 'script_of', 'ask_translation', 'translate_clusters']

HANGUL = re.compile(r'[가-힣]')

# Printable ASCII, Hangul syllables, and Hangul jamo. A name is one of the two
# languages or it is neither: the model has answered with Cyrillic and with CJK,
# and either one draws a word the reader cannot even sound out.
ALLOWED = re.compile(r'^[ -~가-힣㄰-㆏]+$')

PROMPT = (
    "Translate each numbered phrase below into {target}. These are the topic names "
    "of a technical blog.\n"
    "Rules: keep it a phrase of one to three words, not a sentence. Use the wording "
    "the field actually uses rather than a literal rendering. A term that writers of "
    "the target language keep in Latin letters stays in Latin letters. Lower case.\n"
    "Answer with a JSON object mapping each phrase's number, as a string, to its "
    "translation. Nothing else.\n\n"
    "PHRASES:\n{phrases}"
)

TARGET = {'en': 'English', 'ko': 'Korean'}


def script_of(*, phrase: str) -> str:
    """Return 'ko' when the phrase holds any Hangul syllable, else 'en'."""
    return 'ko' if HANGUL.search(phrase) else 'en'


def load_clusters(*, path: pathlib.Path) -> dict:
    """Read the pipeline file whole, so the names can be written back into it."""
    if not path.is_file():
        raise SystemExit(f"no such file: {path}. Run cluster_keywords.py first.")
    saved = json.loads(path.read_text(encoding='utf-8'))
    if 'clusters' not in saved or not saved['clusters']:
        raise SystemExit(f"{path} holds no clusters; it was not written by cluster_keywords.py")
    return saved


def ask_translation(*, phrases: list[str], target: str, model: str, endpoint: str,
                    timeout: int) -> dict[str, str]:
    """Translate one batch into `target` ('en' or 'ko').

    Returns {index as string: translation} as the model answered it; the caller
    checks the script. Raises when the request fails, because a dead endpoint
    must not read as "nothing could be translated".
    """
    if target not in TARGET:
        raise ValueError(f"target must be one of {sorted(TARGET)}, got {target!r}")

    payload = json.dumps({
        'model': model,
        'prompt': PROMPT.format(
            target=TARGET[target],
            phrases='\n'.join(f"{i}. {p}" for i, p in enumerate(phrases)),
        ),
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


def translate_clusters(*, clusters: list[dict], model: str, endpoint: str, timeout: int,
                       batch_size: int) -> tuple[list[dict], collections.Counter]:
    """Write a 'labels' object of {'en': ..., 'ko': ...} onto every cluster.

    The name a cluster already has is kept for its own script and only the other
    one is asked for. Returns the clusters and a counter of what happened.
    """
    tally: collections.Counter = collections.Counter()
    for cluster in clusters:
        source = script_of(phrase=cluster['label'])
        cluster['labels'] = {source: cluster['label']}
        tally[f'already {source}'] += 1

    for target in sorted(TARGET):
        pending = [c for c in clusters if target not in c['labels']]
        if not pending:
            print(f"{target}: nothing to translate")
            continue

        for start in range(0, len(pending), batch_size):
            batch = pending[start:start + batch_size]
            answered = ask_translation(
                phrases=[c['label'] for c in batch], target=target, model=model,
                endpoint=endpoint, timeout=timeout,
            )
            for index, cluster in enumerate(batch):
                name = str(answered.get(str(index), '')).strip().lower()
                if not name:
                    tally[f'{target} missing'] += 1
                    print(f"no {target} name for {cluster['label']!r}", file=sys.stderr)
                    continue
                if not ALLOWED.match(name):
                    tally['other script'] += 1
                    print(f"{target} name for {cluster['label']!r} came back holding a script "
                          f"that is neither: {name!r}", file=sys.stderr)
                    continue
                # Hangul in an English name is a failure with no innocent reading.
                if 'en' == target and script_of(phrase=name) == 'ko':
                    tally['en came back Korean'] += 1
                    print(f"english name for {cluster['label']!r} came back as {name!r}",
                          file=sys.stderr)
                    continue
                cluster['labels'][target] = name
                if name == cluster['label']:
                    tally[f'{target} kept as written'] += 1
                else:
                    tally[f'{target} translated'] += 1

            print(f"  {target}: {min(start + batch_size, len(pending))}/{len(pending)}",
                  end='\r', flush=True)
        print(' ' * 40, end='\r')

    return clusters, tally


def run(*, args: argparse.Namespace) -> int:
    saved = load_clusters(path=pathlib.Path(args.input))
    clusters = saved['clusters']
    print(f"{len(clusters)} topics")

    started = time.time()
    clusters, tally = translate_clusters(
        clusters=clusters, model=args.model, endpoint=args.endpoint,
        timeout=args.timeout, batch_size=args.batch,
    )
    print(f"translated in {time.time() - started:.1f}s\n")

    for key in sorted(tally):
        print(f"{tally[key]:>5}  {key}")

    both = sum(1 for c in clusters if len(c['labels']) == 2)
    if not both:
        # Every topic having one name is the pass failing, not the blog being monolingual.
        raise SystemExit("no topic came back with both names; the translation did not work")
    print(f"\n{both}/{len(clusters)} topics have both names")

    untouched = sum(tally[k] for k in tally if k.endswith('kept as written'))
    if untouched > both / 2:
        print(f"warning: {untouched} names came back unchanged; check that the model is "
              f"translating rather than echoing", file=sys.stderr)

    print(f"\n{'posts':>5}  en / ko")
    for cluster in sorted(clusters, key=lambda c: -c['posts'])[:args.top]:
        labels = cluster['labels']
        print(f"{cluster['posts']:>5}  {labels.get('en', '-')} / {labels.get('ko', '-')}")

    saved['clusters'] = clusters
    output = pathlib.Path(args.output)
    output.write_text(json.dumps(saved, ensure_ascii=False, indent=1), encoding='utf-8')
    print(f"\nwrote {output}")
    return 0


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        prog='translate_topics.py',
        description=f"translate_topics.py {__version__}\n\n{__doc__}",
        formatter_class=argparse.RawDescriptionHelpFormatter,
    )
    parser.add_argument('-v', '--version', action='version', version=__version__)
    parser.add_argument('--input', default='label_fields.json',
                        help='output of label_fields.py, or of cluster_keywords.py')
    parser.add_argument('--output', default='translate_topics.json',
                        help='where to write the same clusters with both names on each')
    parser.add_argument('--model', default='qwen3:8b', help='ollama model that translates')
    parser.add_argument('--endpoint', default='http://localhost:11434', help='ollama base URL')
    parser.add_argument('--batch', type=int, default=20, help='topics per request')
    parser.add_argument('--top', type=int, default=25, help='how many topics to print')
    parser.add_argument('--timeout', type=int, default=600, help='seconds to wait for one request')

    if len(sys.argv) == 1:
        parser.print_help()
        raise SystemExit(0)

    args = parser.parse_args()
    if args.batch < 1:
        parser.error('--batch must be at least 1')
    return args


if __name__ == '__main__':
    raise SystemExit(run(args=parse_args()))
