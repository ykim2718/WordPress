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
| `assets/kwc.css` | 배치, 색, tooltip, 단추 서식 |
| `assets/kwc.js` | 타원 줄 배치와 새로고침 단추 |

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

## 모양과 색

줄마다 폭을 타원 곡선에 맞춰 낱말을 앉힌다. CSS 의 `shape-outside` 로는 되지 않는다.
그것은 상자 높이가 먼저 정해져 있어야 하는데 높이는 몇 줄이 되느냐에 달려 있고, 칸이
좁으면 긴 구절이 들어갈 자리가 위아래 어디에도 없어 타원 밖으로 밀려난다. 그래서
`assets/kwc.js` 가 직접 줄을 나눈다. 한 줄에 적어도 하나는 담으므로 허용 폭보다 긴
구절도 버려지지 않는다. JavaScript 가 없으면 예전처럼 네모로 흐른다.

줄은 가운데부터 바깥으로 채우고, 줄 안에서도 큰 것이 가운데 오도록 놓는다. 그래서
크기가 아래로만이 아니라 사방으로 줄어든다. 데모 구름에서 재면 가운데 35.4px, 그 바깥
23.4px, 가장자리 18.5px 였다.

글꼴은 여섯 가지에서 고른다: 둥근, 고딕, 명조, 고정폭, 테마 글꼴, 직접 적기. 기본은
둥근이다. 구름은 테마가 본문에 쓰는 딱딱한 글꼴보다 부드러운 글꼴에서 잘 읽힌다. 글꼴을
내려받지는 않고 기기에 있는 것만 부르므로, 둥근 시스템 글꼴이 없는 Windows 에서는 부드러운
고딕으로 내려간다. 직접 적은 값은 style 속성에 들어가므로 글자·숫자·공백과 쉼표, 따옴표,
하이픈, 밑줄만 남기고 걸러 낸다. 걸러 낸 사실은 error log 에 남고, 남는 것이 없으면
기본으로 되돌리지 않고 오류를 낸다.

색은 다섯 가지를 돌려 쓴다. 이웃한 topic 을 갈라 보이게 할 뿐 **색 자체에는 뜻이 없다.**
글 수는 글자 크기가 나타낸다. 다섯 색은 색맹 구분 기준과 배경 대비 4.5:1 을 검증해서
고른 것이다. 여기서는 색이 곧 글자라 대비가 읽기에 직접 걸린다.

| Slot | Hex | Contrast |
|---|---|---|
| 1 | `#1f66bd` | 5.54 |
| 2 | `#0f7d57` | 5.00 |
| 3 | `#c4501f` | 4.53 |
| 4 | `#4a3aa7` | 8.33 |
| 5 | `#7a6a00` | 5.26 |

`color_mode=gradient` 로 두면 예전처럼 `color_start` 에서 `color_end` 로 가는 한 색
그러데이션을 쓴다.

## 새로고침 단추

구름 오른쪽 위에 작은 단추가 붙는다. 글을 고칠 수 있는 사용자에게만 보이고, 누르면
하루를 기다리지 않고 지금 topic 을 받아온 뒤 화면을 새로 그린다. 단추를 감추는 것은
편의일 뿐이고 `POST /wp-json/key-word-cloud/v1/refresh` 가 같은 권한을 다시 확인한다.

단추가 붙는지는 보는 사람에 따라 다르므로 캐시 키에 그 여부가 들어간다.

## 마우스 올림

topic 에 마우스를 올리면 어떤 구절에서 온 것인지와 글 수가 보인다. 브라우저 기본
`title` 풍선이 아니라 CSS 로 그린 것이라 바로 뜬다.

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
| `shape` | `ellipse` | `ellipse` 또는 `block` |
| `font` | `rounded` | `rounded`, `sans`, `serif`, `mono`, `theme`, `custom` |
| `font_custom` | `''` | `font=custom` 일 때 쓸 CSS font-family |
| `color_mode` | `palette` | `palette` 또는 `gradient` |
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
