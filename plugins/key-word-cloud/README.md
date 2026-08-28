# Key Word Cloud

Language model이 뽑고 embedding model이 묶은 topic 을 워드프레스에 구름으로 그리는
플러그인. Topic 을 누르면 그 말로 검색한 글 목록이 열린다.

넣는 방법은 두 가지다. 블록 삽입기의 **yRocket** 묶음에서 **Key Word Cloud** 블록을
고르거나, 숏코드를 쓴다.

```
[wpwordcloud]
[wpwordcloud language="ko" max="30" min_posts="3" color_end="#b3202e"]
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
│   ├── version.json
│   └── topics.json          ← 하루 한 번 가져가는 topic
├── tools/                   ← topic 파이프라인 (GPU 있는 기계에서 돈다)
├── DESCRIPTION.md           ← View details 의 Description 탭
├── CHANGELOG.md             ← View details 의 Changelog 탭
└── README.md
```

`src/` 밖의 것은 zip에 들어가지 않는다. `tools/` 의 파이프라인도 그래서 빠진다.

## src 구성

| File | 하는 일 |
|---|---|
| `key-word-cloud.php` | 헤더와 상수, 훅 등록, 캐시 비우기 |
| `includes/defaults.php` | 기본 설정값 |
| `includes/language.php` | 한글/영어 판정 |
| `includes/topics.php` | topic 을 REST 로 받거나 하루 한 번 가져와 저장 |
| `includes/cloud.php` | 저장된 topic 으로 HTML 을 만든다 |
| `includes/shortcode.php` | `[wpwordcloud]` 와 속성 검증 |
| `includes/block.php` | block 등록과 서버 렌더링 |
| `includes/settings.php` | 사이드바 메뉴와 설정 화면 |
| `includes/updater.php` | 저장소에서 갱신, View details 창 |
| `blocks/word-cloud/` | `block.json`, `editor.js`, `editor.asset.php` |
| `assets/kwc.css` | 가로 배치와 오류 문구 서식 |

플러그인은 본문을 읽지 않는다. 받아 둔 topic 을 꺼내 크기와 색만 입힌다. 그래서 GPU 도,
`mbstring` 같은 확장도 필요 없다.

## Block

블록의 사이드바 항목은 아래 숏코드 속성과 이름도 뜻도 같다. 칸을 비워 두면 설정 화면의
값을 쓴다. 편집 화면의 미리보기는 브라우저가 아니라 서버가 그린 것을 받아 온다. 규칙을
PHP 한 곳에만 두려는 것이고, 그래서 미리보기와 실제 화면이 갈라지지 않는다.

`editor.js` 는 JSX 없이 `wp` 전역만 쓰는 평범한 JavaScript다. 이 저장소에는 build 단계가
없으므로, 의존 script 목록은 `editor.asset.php` 에 손으로 적어 둔다.

## Topic

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

`GET` 으로 같은 주소를 부르면 저장된 것을 그대로 돌려준다. 무엇이 올라갔는지 확인할 때
쓴다. 설정 화면 위쪽에도 개수와 시각, generator 가 보인다.

Topic 을 만드는 파이프라인은 `tools/` 에 있다.

## 하루 한 번 가져오기

올리는 길이 하나 더 있다. 파이프라인이 결과를 `dist/topics.json` 에 써서 저장소에
올리면, 플러그인이 하루에 한 번 그 주소에서 받아 저장한다. `updater.php` 가
`version.json` 을 받는 것과 같은 구조라 application password 도, 열린 포트도 필요 없다.

```bash
python3 tools/push_topics.py --write plugins/key-word-cloud/dist/topics.json --dry-run true
```

받아오는 것이지 분석하는 것이 아니다. **갱신 이후에 쓴 글은 파이프라인을 다시 돌려 그
파일을 새로 올릴 때까지 반영되지 않는다.**

받아오기에 실패해도 이미 저장된 topic 은 그대로 두고 무엇이 어긋났는지 기록한다. 설정
화면에 마지막 시도와 그 결과, 다음 자동 갱신 시각이 보이고, 하루를 기다리지 않고 바로
받아오는 단추가 있다. 받아오기를 끄거나 플러그인을 비활성화하면 일정도 걷는다.

## 언어

한글 음절이 한 자라도 있으면 한글로, 없으면 영어로 본다. `종합소득세 신고` 는 한글,
`ats score` 는 영어다. 설정 화면에서 영어 / 한글 / 혼재 중에 고르고 기본값은 영어이며,
숏코드 `language` 로 개별 구름마다 덮어쓴다.

## 속성

| Attribute | Default | 설명 |
|---|---|---|
| `language` | `en` | `en`, `ko`, `both` |
| `min_posts` | 2 | 이보다 적은 글에서 온 topic 은 그리지 않는다 |
| `max` | 60 | 그릴 topic 수, 1..500 |
| `min_size` | 12 | 가장 작은 글자 크기 px |
| `max_size` | 44 | 가장 큰 글자 크기 px |
| `color_start` | `#8aa4c8` | 적은 글에서 온 topic 의 색 |
| `color_end` | `#12355b` | 많은 글에서 온 topic 의 색 |
| `link` | `search` | `search` 또는 `none` |
| `cache` | 3600 | 캐시 초. 0이면 캐시하지 않는다 |

`pull_enabled` 와 `pull_url` 은 사이트 전체에 걸리는 설정이라 숏코드 속성에는 없다.

값이 범위를 벗어나거나 형식이 틀리면 기본값으로 되돌리지 않고 그 자리에 오류를 적는다.
그릴 것이 없을 때도 빈 화면 대신 topic 이 몇 개였고 무엇에 걸렸는지 적는다. 같은 내용이
PHP error log에 `[key-word-cloud]` 로 남는다.

## 설치

1. 플러그인 화면 → 새로 추가 → 플러그인 업로드에 `dist/key-word-cloud.zip`.
2. 이후 업데이트는 플러그인 화면에 알림으로 뜬다.
3. `tools/` 의 파이프라인을 돌려 topic 을 올린다. 올리기 전에는 구름이 그려지지 않는다.

## 새 버전 내보내기

`src/key-word-cloud.php` 의 `Version:` 과 `KWC_VERSION` 을 올리고 `CHANGELOG.md` 맨 위에
항목을 적어서 push한다. tag도 release도 쓰지 않는다.

```bash
python3 tools/build_plugin_dist.py --slug key-word-cloud
```
