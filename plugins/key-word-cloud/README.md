# Key Word Cloud

글의 본문 또는 요약문을 읽어 단어 구름을 그리는 워드프레스 플러그인. 단어를 누르면 그
단어로 검색한 글 목록이 열린다.

넣는 방법은 두 가지다. 블록 삽입기에서 **Key Word Cloud** 블록을 고르거나, 숏코드를 쓴다.

```
[wpwordcloud]
[wpwordcloud source="excerpt" category="ai" max="40" min_count="2" color_end="#b3202e"]
```

## 폴더 구조

`plugins/github-image-gallery` 와 같은 모양이다. 플러그인 하나가 폴더 하나를 쓴다.

```
plugins/key-word-cloud/
├── src/                     ← 이 폴더만 zip으로 묶인다
│   ├── key-word-cloud.php
│   ├── includes/
│   ├── blocks/word-cloud/   ← block.json, editor.js
│   └── assets/
├── dist/                    ← 빌드 결과. 워드프레스가 여기서 받는다
│   ├── key-word-cloud.zip
│   └── version.json
├── tools/                   ← topic 파이프라인 (GPU 있는 기계에서 돈다)
├── DESCRIPTION.md           ← View details 의 Description 탭
├── CHANGELOG.md             ← View details 의 Changelog 탭
└── README.md
```

`src/` 밖의 것은 zip에 들어가지 않는다. `tools/` 의 파이프라인도 그래서 빠진다. screenshots 폴더는 아직 없다. 그림을 찍어
`screenshots/` 에 두고 `DESCRIPTION.md` 에서 파일명만으로 참조하면, 빌드가 그 경로를
`raw.githubusercontent.com` 주소로 바꿔 View details 창에 싣는다.

## src 구성

| File | 하는 일 |
|---|---|
| `key-word-cloud.php` | 헤더와 상수, mbstring 확인, 훅 등록, 캐시 비우기 |
| `includes/defaults.php` | 기본 설정값, 기본 불용어, 기본 조사 목록 |
| `includes/tokenizer.php` | 텍스트를 단어로 자르고 조사를 떼어낸다 |
| `includes/cloud.php` | 글을 읽어 빈도를 세고 HTML을 만든다 |
| `includes/shortcode.php` | `[wpwordcloud]` 와 속성 검증 |
| `includes/block.php` | block 등록과 서버 렌더링 |
| `includes/settings.php` | 사이드바 메뉴와 설정 화면 |
| `includes/topics.php` | 밖에서 올린 topic 을 REST 로 받아 저장 |
| `includes/updater.php` | 저장소에서 갱신, View details 창 |
| `blocks/word-cloud/` | `block.json`, `editor.js`, `editor.asset.php` |
| `assets/kwc.css` | 가로 배치와 오류 문구 서식 |

## Block

블록의 사이드바 항목은 아래 숏코드 속성과 이름도 뜻도 같다. 칸을 비워 두면 설정 화면의
값을 쓴다. 편집 화면의 미리보기는 브라우저가 아니라 서버가 그린 것을 받아 온다. 규칙을
PHP 한 곳에만 두려는 것이고, 그래서 미리보기와 실제 화면이 갈라지지 않는다.

`editor.js` 는 JSX 없이 `wp` 전역만 쓰는 평범한 JavaScript다. 이 저장소에는 build 단계가
없으므로, 의존 script 목록은 `editor.asset.php` 에 손으로 적어 둔다.

## 단어를 세는 순서

1. 발행된 글을 최신순으로 `limit` 개까지 읽는다. `source` 에 따라 본문 또는 요약문을 쓴다.
2. 숏코드와 HTML 태그를 걷어내고, 글자와 숫자가 아닌 것을 경계로 삼아 자른다.
3. 소문자로 바꾸고 불용어를 버린다.
4. 한글 단어면 끝의 조사를 뗀다. 뗀 뒤에 다시 한 번 불용어를 대조한다.
5. 길이가 `min_len` 미만이거나 숫자로만 된 토큰을 버린다.
6. `min_count` 미만을 버린다.
7. `ranking=tfidf` 면 글 수 기준 `min_docs_pct` 미만인 단어를 버리고 TF-IDF 점수를 매긴다.
8. 점수순으로 위에서 `max` 개만 남긴다.

## TF-IDF

점수는 `(1 + log TF) × log(1 + N / DF)` 다. TF 는 전체 등장 횟수, DF 는 그 단어가 나온 글
수, N 은 읽은 글 수다. 모든 글에 두루 나오는 단어는 DF 가 커져 점수가 내려가고, 몇 글에
몰려 나오는 단어가 올라온다. 글자 크기는 이 점수를 따르고, 마우스를 올리면 실제 등장
횟수와 글 수가 보인다.

TF-IDF 는 희귀할수록 점수를 올리므로 하한이 없으면 한 글에만 있는 오탈자가 1위가 된다.
`min_docs_pct` 가 그 하한이다. 글 300개를 읽은 저장소에서 잰 결과는 이렇다.

| min_docs_pct | 상위 단어 |
|---|---|
| 0 | slug, df, vectorization, groq, katex, ucxxxxxxxxx |
| 2 | katex, ccc, cai, mathbf, city, embedding |
| 10 | id, team, wafer, variance, report, yield, manufacturing |
| 20 | feature, learning, product, model, user, method |

낮추면 잡음이 올라오고, 높이면 흔한 말만 남는다. 기본값 10 은 그 사이다.

조사 분리는 형태소 분석기가 아니라 규칙이다. 긴 조사부터 맞춰 보고, 떼고 남는 어간이
`kr_min_stem` 음절보다 짧아지면 떼지 않는다. 최대 두 번까지 반복해서 `학교에서는` 이
`학교` 에 닿는다. `고양이` 가 `고양` 이 되는 식의 오분리가 보이면 `kr_min_stem` 을 올리거나
그 조사를 목록에서 뺀다.

## Topic

`ranking=topics` 는 본문을 세지 않는다. 밖에서 만들어 올린 topic 을 읽어 그린다. TF-IDF 가
낱말만 다루는 반면 topic 은 `within-wafer variation` 같은 구절이 될 수 있고, 그건 세는
것으로는 나오지 않는다.

받는 자리는 아래 하나다. WordPress application password 로 인증하며, 글을 고칠 수 있는
사용자만 쓴다.

```bash
POST /wp-json/key-word-cloud/v1/topics
Authorization: Basic <base64 of user:application-password>
Content-Type: application/json
```

```json
{
  "generator": "어떤 방법으로 만들었는지 적어 두는 자리",
  "topics": [
    {
      "label": "ai assisted development",
      "posts": 6,
      "phrases": ["agentic automation", "ai agent worker", "ai assistant"]
    }
  ]
}
```

`posts` 가 글자 크기를 정하고, `phrases` 는 마우스를 올렸을 때 보인다. `label` 이 비었거나
`posts` 가 1 미만인 항목은 버리고 무엇을 왜 버렸는지 응답과 error log 에 적는다. 쓸 수 있는
항목이 하나도 없으면 저장하지 않고 400 을 돌려준다. 저장에 성공하면 캐시를 비운다.

topic 을 만드는 파이프라인은 `tools/` 에 있다. LLM 과 embedding 모델이 필요하므로 GPU 가
있는 기계에서 돌리고, 결과만 위 주소로 보낸다.

## 속성

| Attribute | Default | 설명 |
|---|---|---|
| `ranking` | `tfidf` | `tfidf`, `count`, `topics` |
| `min_docs_pct` | 10 | TF-IDF 후보가 되기 위한 최소 문서 비율(%). 0 이면 제한 없음 |
| `source` | `content` | `content` 또는 `excerpt` |
| `post_type` | `post` | 쉼표 구분. 공개 post type만 |
| `category` | `''` | 카테고리 slug |
| `tag` | `''` | 태그 slug |
| `limit` | 300 | 읽을 글 수, 1..5000 |
| `max` | 60 | 그릴 단어 수, 1..500 |
| `min_count` | 1 | 최소 등장 횟수 |
| `min_len` | 2 | 최소 글자 수 |
| `min_size` | 12 | 가장 작은 글자 크기 px |
| `max_size` | 44 | 가장 큰 글자 크기 px |
| `color_start` | `#8aa4c8` | 적게 나온 단어의 색 |
| `color_end` | `#12355b` | 많이 나온 단어의 색 |
| `link` | `search` | `search` 또는 `none` |
| `cache` | 3600 | 캐시 초. 0이면 캐시하지 않는다 |

값이 범위를 벗어나거나 형식이 틀리면 기본값으로 되돌리지 않고 그 자리에 오류를 적는다.
표시할 단어가 없을 때도 빈 화면 대신 읽은 글 수와 이유를 적는다. 같은 내용이 PHP error
log에 `[key-word-cloud]` 로 남는다.

## 설정

WP Admin 사이드바의 **Key Word Cloud** 메뉴 하나에 다 있다. 위의 속성과 같은 항목에
더해 요약문이 비었을 때 본문으로 대체할지, 불용어와 조사 목록, 최소 어간 길이를 정한다.
화면 아래쪽에 현재 설정으로 그린 구름을 미리보기로 보여주고, 캐시를 비우는 단추가 있다.

## 설치

1. 플러그인 화면 → 새로 추가 → 플러그인 업로드에 `dist/key-word-cloud.zip`.
2. 이후 업데이트는 플러그인 화면에 알림으로 뜬다.

PHP `mbstring` 확장이 필요하다. 없으면 관리자 화면에 경고가 뜨고 구름을 그리지 않는다.

## 새 버전 내보내기

`src/key-word-cloud.php` 의 `Version:` 과 `KWC_VERSION` 을 올리고 `CHANGELOG.md` 맨 위에
항목을 적어서 push한다. tag도 release도 쓰지 않는다.

```bash
python3 tools/build_plugin_dist.py --slug key-word-cloud
```
