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
├── DESCRIPTION.md           ← View details 의 Description 탭
├── CHANGELOG.md             ← View details 의 Changelog 탭
└── README.md
```

`src/` 밖의 것은 zip에 들어가지 않는다. screenshots 폴더는 아직 없다. 그림을 찍어
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
6. 빈도순으로 정렬해 `min_count` 미만을 버리고 위에서 `max` 개만 남긴다.

조사 분리는 형태소 분석기가 아니라 규칙이다. 긴 조사부터 맞춰 보고, 떼고 남는 어간이
`kr_min_stem` 음절보다 짧아지면 떼지 않는다. 최대 두 번까지 반복해서 `학교에서는` 이
`학교` 에 닿는다. `고양이` 가 `고양` 이 되는 식의 오분리가 보이면 `kr_min_stem` 을 올리거나
그 조사를 목록에서 뺀다.

## 속성

| Attribute | Default | 설명 |
|---|---|---|
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
