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
| `assets/icon.svg` | 사이드바 메뉴와 플러그인 목록의 아이콘 |

플러그인은 본문을 읽지 않는다. 받아 둔 topic 을 꺼내 크기와 색만 입힌다. 그래서 GPU 도,
`mbstring` 같은 확장도 필요 없다.

## Block

블록의 사이드바 항목은 아래 숏코드 속성과 이름도 뜻도 같다. 편집 화면의 미리보기는
브라우저가 아니라 서버가 그린 것을 받아 온다. 규칙을 PHP 한 곳에만 두려는 것이고, 그래서
미리보기와 실제 화면이 갈라지지 않는다.

항목은 세 묶음이고, 묶음은 편집기가 이미 가진 탭이다. 탭을 새로 만들지 않는다.

| Group | Tab | 들어가는 것 |
|---|---|---|
| Content | Settings | 언어, 분야, 최소 글 수, topic 수 |
| Appearance | Styles | 모양과 크기, 글꼴, 색 |
| Data | Advanced | 캐시 |

둘로 나누지 않은 것은 캐시 때문이다. 캐시는 무엇을 그리는지도 어떻게 보이는지도 아니라서
둘 중 어디에 넣어도 그 묶음의 이름이 거짓이 된다.

칸을 비워 두면 설정 화면의 값을 쓰는데, 그 값이 무엇인지도 함께 적는다. 고르는 자리에는
`Setting: English` 처럼, 적는 자리에는 옅은 글씨의 `3600` 처럼 나온다. 분야 체크박스도
설정 화면이 체크한 것에서 시작한다. "설정값을 쓴다" 고만 적으면 그 값을 보려고 설정 화면을
열어야 한다.

언어는 고르는 자리 대신 라디오 단추다. 답이 넷뿐이라 닫힌 선택 상자가 차지하는 자리에 넷이
다 들어간다.

글 수와 topic 수는 미끄럼대로 고른다. 양끝은 올라온 topic 이 정한다. 앞의 것은 가장 많은
글에서 나온 topic 의 글 수까지, 뒤의 것은 올라온 topic 수까지다. 그 너머는 어떤 값을 골라도
같은 그림이라 고를 이유가 없다. 되돌리기를 누르면 다시 설정 화면을 따른다.

미리보기의 topic 에는 링크를 걸지 않는다. 편집 캔버스가 iframe 이라, 그 안에서 링크를
누르면 캔버스가 검색 결과로 떠나고 편집기는 제 문서를 잃는다. 다시 읽기 전까지 깨진 채로
남으므로 미리보기에서만 글자로 그린다. 실제 화면의 링크는 그대로다.

그래서 낱말을 눌렀을 때 무엇을 할지는 블록에 두지 않는다. 여기에 두면 무엇이 바뀌는지
보이지 않고, 애초에 사이트 전체에 걸리는 결정이다. 설정 화면과 숏코드 `link` 속성에 있다.

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
      "labels": {"en": "ai assisted development", "ko": "ai 지원 개발"},
      "fields": ["machine learning"],
      "phrases": ["agentic automation", "ai agent worker", "ai assistant"]
    }
  ]
}
```

`posts` 가 글자 크기를 정하고, `phrases` 는 마우스를 올렸을 때 보이며, `fields` 는 어느
분야의 구름에 나올지를 정한다. `labels` 는 언어마다 그릴 이름이고, 없으면 `label` 하나로
예전처럼 언어를 가른다. `fields` 는 없어도 되고, 없으면 분야를 고르지 않은 구름에만
나온다.

`label` 이 비었거나 `posts` 가 1 미만인 항목은 버리고 무엇을 왜 버렸는지 응답과 error log
에 적는다. 쓸 수 있는 항목이 하나도 없으면 저장하지 않고 400 을 돌려준다. 저장에 성공하면
캐시를 비운다.

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

## 분야

Topic 마다 어느 분야의 것인지가 붙어 있고, 고른 분야의 topic 만 그린다. 분야는
`tools/label_fields.py` 가 붙인다. 부를 때 분야 이름을 직접 적으므로 무엇으로 가를지는
글을 쓴 사람이 정하고, 모델은 각 topic 이 그중 어디에 드는지만 답한다.

```bash
python3 tools/label_fields.py --input cluster_keywords.json \
    --fields "semiconductor, machine learning, applied statistics"
```

Topic 하나가 여러 분야에 들 수 있고, 어디에도 안 드는 topic 은 그대로 남되 분야를 고른
구름에는 나오지 않는다. 모델이 목록에 없는 이름을 지어내면 버리고 몇 개를 버렸는지 센다.
전부 버려져 어느 분야에도 남지 않으면 결과를 쓰지 않고 멈춘다. 그 상태로 올리면 구름이
빈 이유를 알 수 없기 때문이다.

어떤 분야가 있는지는 설정 화면의 **분야 목록** 이 정한다. 자료에서 읽지 않는다. 아직
아무 topic 도 들지 않은 분야가 목록에서 사라지면 그 분야를 고를 수 없고, 고를 수 없으니
topic 도 영영 들지 않는다. 목록을 비워 두면 그때만 올라온 topic 이 달고 있는 이름을 쓴다.

기본 목록은 data science, mathematics, semiconductor, sports, liberal arts 이고 앞의 셋이
체크되어 있다. 여기 적은 이름과 `tools/label_fields.py --fields` 에 준 이름이 같아야 한다.
다르면 그 분야의 개수가 `0` 으로 남는다.

고르는 자리는 설정 화면과 블록 사이드바의 체크박스, 그리고 숏코드 `fields` 속성이다.
괄호 안은 그 분야에 든 topic 수이고 `0` 도 그대로 보인다. 목록에 없는 이름을 적으면 있는
이름을 알려 주는 오류가 난다. 오타를 "그 분야에 든 topic 이 없다" 로 흘리면 빈 구름의
이유를 찾을 수 없다.

## 언어

Topic 마다 영어 이름과 한글 이름이 함께 있다. 영어를 고르면 한글 글에서 나온 topic 도
영어 이름으로 그리고, 한글을 고르면 그 반대다. 언어를 고르는 일이 글의 절반을 감추는 일이
되지 않게 하려는 것이다. 혼재를 고르면 원래 쓰인 이름을 그대로 쓴다.

이름은 `tools/translate_topics.py` 가 붙인다. 이름만 옮기고 구절은 그대로 둔다. 구절은
topic 이 무엇에서 묶여 나왔는지를 보이는 것이고, 마우스를 올렸을 때 그렇게 적혀 있기
때문이다.

```bash
python3 tools/translate_topics.py --input label_fields.json
```

두 topic 이 같은 이름으로 번역되면 글 수가 많은 쪽만 남긴다. 둘을 합치지는 않는다. 한 글이
두 topic 에 걸려 있을 수 있어 합계가 실제 글 수보다 커지기 때문이다.

이름이 하나뿐인 예전 자료는 예전 규칙을 그대로 쓴다. 한글 음절이 한 자라도 있으면 한글로,
없으면 영어로 보아 그 언어의 구름에만 나온다. `종합소득세 신고` 는 한글, `ats score` 는
영어다.

설정 화면에서 영어 / 한글 / 혼재 중에 고르고 기본값은 영어이며, 숏코드 `language` 로 개별
구름마다 덮어쓴다.

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

## 판 번호

구름 왼쪽 위에 판 번호가 흐리게 적힌다. 화면에 보이는 구름이 어느 판이 그린 것인지 바로
알기 위한 것이라 누구에게나 보이고, 가장 작은 낱말보다 작으며, 클릭을 받지 않는다.

판 번호는 캐시 키에도 들어간다. 그러지 않으면 갱신한 뒤에도 캐시가 옛 번호를 보여 주어,
번호가 가리키는 것과 그린 것이 어긋난다.

## 마우스 올림

topic 에 마우스를 올리면 어떤 구절에서 온 것인지와 글 수가 보인다. 브라우저 기본
`title` 풍선이 아니라 CSS 로 그린 것이라 바로 뜬다.

## 속성

| Attribute | Default | 설명 |
|---|---|---|
| `language` | `en` | `en`, `ko`, `both` |
| `fields` | `data science,mathematics,semiconductor` | 그릴 분야를 쉼표로. 비거나 `*` 면 분야를 가리지 않는다 |
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
| `width` | 0 | 가로 px. 0 이면 칸 너비. 좁은 화면에서는 칸을 넘지 않는다 |
| `height` | 0 | 세로 px. 0 이면 `ratio` 가 정한다. 주면 `ratio` 는 쓰이지 않는다 |
| `link` | `search` | `search` 또는 `none` |
| `cache` | 3600 | 캐시 초. 0이면 캐시하지 않는다 |

`pull_enabled`, `pull_url`, `field_list` 는 사이트 전체에 걸리는 설정이라 숏코드 속성에는
없다.

값이 범위를 벗어나거나 형식이 틀리면 기본값으로 되돌리지 않고 그 자리에 오류를 적는다.
그릴 것이 없을 때도 빈 화면 대신 topic 이 몇 개였고 무엇에 걸렸는지 적는다. 같은 내용이
PHP error log에 `[key-word-cloud]` 로 남는다.

## 아이콘

구름 위에 KWC 를 얹은 `assets/icon.svg` 하나를 세 곳에서 쓴다. 사이드바 메뉴, 플러그인
목록, View details 창이다. 메뉴에서는 20px 로 그려지므로 글자에 여섯 픽셀쯤이 주어진다.
그 크기에 맞춰 구름을 납작하고 넓게 눕혀 글자 자리를 한 줄 비우고, 세 글자를 그 폭에 꽉
차게 늘렸다. 색은 구름이 쓰는 것과 같은 파랑이고, 글자가 앉는 아래쪽이 진해서 흰 글자가
대비 5.5:1 을 넘는다. 그 대비가 여섯 픽셀짜리 글자를 붙들어 준다.

메뉴에는 파일 주소가 아니라 data URI 로 실어 보낸다. 주소를 주면 관리 화면마다 그림을 한
번씩 더 받아 오는데, 이만한 SVG 는 그냥 싣는 편이 싸다. 파일이 없으면 기본 아이콘으로
내려가고 그 사실을 error log 에 남긴다.

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
