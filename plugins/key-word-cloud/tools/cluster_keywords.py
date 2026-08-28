#!/usr/bin/env python3
"""Group the extracted key phrases into cloud topics with embeddings.

Reads the phrases that llm_keywords.py wrote, turns each one into a vector with
a local embedding model, and clusters the vectors. Each cluster becomes one
entry of the word cloud: its weight is the number of posts its phrases came
from, and its label is the phrase closest to the cluster centre.

This replaces asking an LLM to fold the list. A model given 400 phrases at once
drifts back to copying them; clustering does the same job as arithmetic, and
does not care whether there are 400 phrases or 4000.

Korean and English phrases are clustered apart. In a multilingual embedding the
two scripts sit in different regions, so every Korean phrase is closer to every
other Korean phrase than to anything English, and they collapse into one
meaningless lump whatever the threshold.

Changelog
    0.1.0  Cluster each script separately.
    0.0.0  First version.
"""

from __future__ import annotations

__author__ = 'yRocket'
__version__ = "0.1.0.2026.8.28"  # Semantic Versioning: Major.Minor.Patch.Date(YYYY.M.D)

import argparse
import collections
import json
import pathlib
import re
import sys
import time
import urllib.error
import urllib.request

import numpy as np
from sklearn.cluster import AgglomerativeClustering
from sklearn.metrics.pairwise import cosine_similarity

__all__ = ['load_phrases', 'script_of', 'embed', 'cluster_phrases', 'label_clusters']

HANGUL = re.compile(r'[가-힣]')


def script_of(*, phrase: str) -> str:
    """Return 'ko' when the phrase holds any Hangul syllable, else 'en'."""
    return 'ko' if HANGUL.search(phrase) else 'en'


def load_phrases(*, path: pathlib.Path) -> tuple[list[str], dict[str, set]]:
    """Read the first-pass output.

    Returns the phrase list and {phrase: set of post ids it came from}.
    """
    if not path.is_file():
        raise SystemExit(f"no such file: {path}. Run llm_keywords.py first.")
    saved = json.loads(path.read_text(encoding='utf-8'))
    if 'posts' not in saved:
        raise SystemExit(f"{path} has no 'posts' key; it was not written by llm_keywords.py")

    posts_of_phrase: dict[str, set] = collections.defaultdict(set)
    for post in saved['posts']:
        for phrase in post['phrases']:
            posts_of_phrase[phrase].add(post['id'])
    if not posts_of_phrase:
        raise SystemExit(f"{path} holds no phrases at all")

    # Sort so a rerun on the same file gives the same clusters.
    return sorted(posts_of_phrase), dict(posts_of_phrase)


def embed(*, texts: list[str], model: str, endpoint: str, timeout: int, batch: int) -> np.ndarray:
    """Turn each text into a unit vector. Returns an array of shape (len(texts), dim)."""
    vectors: list[list[float]] = []
    for start in range(0, len(texts), batch):
        chunk = texts[start:start + batch]
        payload = json.dumps({'model': model, 'input': chunk}).encode('utf-8')
        request = urllib.request.Request(
            f'{endpoint}/api/embed', data=payload,
            headers={'Content-Type': 'application/json'},
        )
        try:
            with urllib.request.urlopen(request, timeout=timeout) as response:
                body = json.loads(response.read().decode('utf-8'))
        except (urllib.error.URLError, TimeoutError) as error:
            raise RuntimeError(f"embedding request failed at item {start}: {error}") from error

        returned = body.get('embeddings')
        if not returned or len(returned) != len(chunk):
            raise RuntimeError(
                f"embedding endpoint returned {len(returned) if returned else 0} vectors "
                f"for {len(chunk)} inputs at item {start}"
            )
        vectors.extend(returned)
        print(f"  embedded {min(start + batch, len(texts))}/{len(texts)}", end='\r', flush=True)

    print(' ' * 40, end='\r')
    array = np.asarray(vectors, dtype=np.float32)
    norms = np.linalg.norm(array, axis=1, keepdims=True)
    if not np.all(norms > 0):
        raise RuntimeError("the embedding model returned a zero vector; cosine distance is undefined")
    return array / norms


def cluster_phrases(*, vectors: np.ndarray, threshold: float) -> np.ndarray:
    """Group vectors by cosine distance. Returns a label per vector.

    `threshold` is the cosine distance at which two groups stop being merged:
    lower splits into many narrow topics, higher merges into few broad ones.
    """
    model = AgglomerativeClustering(
        n_clusters=None, distance_threshold=threshold, metric='cosine', linkage='average',
    )
    return model.fit_predict(vectors)


def label_clusters(*, phrases: list[str], vectors: np.ndarray, labels: np.ndarray,
                   posts_of_phrase: dict[str, set], script: str) -> list[dict]:
    """Name each cluster and weigh it.

    The label is the phrase nearest the cluster centre. The weight is how many
    distinct posts the cluster's phrases came from, so a post that used three
    phrases of one topic still counts once.
    """
    clusters = []
    for label in sorted(set(labels.tolist())):
        members = [i for i, value in enumerate(labels) if value == label]
        centre = vectors[members].mean(axis=0, keepdims=True)
        nearest = members[int(np.argmax(cosine_similarity(vectors[members], centre).ravel()))]

        covered: set = set()
        for index in members:
            covered |= posts_of_phrase[phrases[index]]
        clusters.append({
            'label': phrases[nearest],
            'script': script,
            'posts': len(covered),
            'phrases': [phrases[i] for i in members],
        })
    return clusters


def run(*, args: argparse.Namespace) -> int:
    phrases, posts_of_phrase = load_phrases(path=pathlib.Path(args.input))

    by_script: dict[str, list[str]] = collections.defaultdict(list)
    for phrase in phrases:
        by_script[script_of(phrase=phrase)].append(phrase)
    print(f"{len(phrases)} distinct phrases: "
          + ', '.join(f"{len(v)} {k}" for k, v in sorted(by_script.items())))

    clusters: list[dict] = []
    for script, group in sorted(by_script.items()):
        if len(group) < 2:
            # One phrase cannot be clustered, but it is still a topic of size one.
            print(f"{script}: only {len(group)} phrase, kept as is")
            clusters += [{'label': p, 'script': script, 'posts': len(posts_of_phrase[p]),
                          'phrases': [p]} for p in group]
            continue

        started = time.time()
        vectors = embed(texts=group, model=args.model, endpoint=args.endpoint,
                        timeout=args.timeout, batch=args.batch)
        found = label_clusters(
            phrases=group, vectors=vectors,
            labels=cluster_phrases(vectors=vectors, threshold=args.threshold),
            posts_of_phrase=posts_of_phrase, script=script,
        )
        singles = sum(1 for c in found if len(c['phrases']) == 1)
        print(f"{script}: {len(group)} phrases -> {len(found)} clusters "
              f"({singles} single) in {time.time() - started:.1f}s")
        clusters += found

    clusters.sort(key=lambda c: (-c['posts'], -len(c['phrases']), c['label']))
    print()

    print(f"{'posts':>5} {'size':>4} {'lang':>4}  topic")
    for cluster in clusters[:args.top]:
        members = ', '.join(cluster['phrases'][:5])
        more = '' if len(cluster['phrases']) <= 5 else f", +{len(cluster['phrases']) - 5}"
        print(f"{cluster['posts']:>5} {len(cluster['phrases']):>4} {cluster['script']:>4}  "
              f"{cluster['label']}   [{members}{more}]")

    output = pathlib.Path(args.output)
    output.write_text(json.dumps({
        'model': args.model, 'threshold': args.threshold, 'clusters': clusters,
    }, ensure_ascii=False, indent=1), encoding='utf-8')
    print(f"\nwrote {output}")
    return 0


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        prog='cluster_keywords.py',
        description=f"cluster_keywords.py {__version__}\n\n{__doc__}",
        formatter_class=argparse.RawDescriptionHelpFormatter,
    )
    parser.add_argument('-v', '--version', action='version', version=__version__)
    parser.add_argument('--input', default='llm_keywords.json', help='output of llm_keywords.py')
    parser.add_argument('--output', default='cluster_keywords.json', help='where to write the clusters')
    parser.add_argument('--model', default='nomic-embed-text', help='ollama embedding model')
    parser.add_argument('--endpoint', default='http://localhost:11434', help='ollama base URL')
    parser.add_argument('--threshold', type=float, default=0.45,
                        help='cosine distance to stop merging; lower gives more, narrower topics')
    parser.add_argument('--batch', type=int, default=64, help='phrases per embedding request')
    parser.add_argument('--top', type=int, default=30, help='how many clusters to print')
    parser.add_argument('--timeout', type=int, default=300, help='seconds to wait for one batch')

    if len(sys.argv) == 1:
        parser.print_help()
        raise SystemExit(0)

    args = parser.parse_args()
    if not 0.0 < args.threshold < 2.0:
        parser.error('--threshold must be between 0 and 2; cosine distance cannot leave that range')
    if args.batch < 1:
        parser.error('--batch must be at least 1')
    return args


if __name__ == '__main__':
    raise SystemExit(run(args=parse_args()))
