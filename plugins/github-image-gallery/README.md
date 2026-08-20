# GitHub Image Gallery

GitHub 공개 저장소의 이미지 폴더를 워드프레스 글에 thumbnail gallery로 넣는 플러그인.

```
[github_image_gallery github_url="https://github.com/ykim2718/WordPress/tree/main/Images"]
```

## 어떻게 도는가

| 층 | 언어 | 하는 일 |
|---|---|---|
| 빌드 | Python (GitHub Actions) | `Images/`를 훑어 `index.json`과 480px 썸네일 생성 |
| 서버 | PHP | `index.json` 한 번 받아 캐시, group 계산, HTML 출력 |
| 화면 | JavaScript | group 다중 선택, 정렬, 검색, 라이트박스 |

목록은 두 경로로 읽는다.

1. **`index.json`** — `tools/build_image_index.py`가 CI에서 만들어 둔 것. `raw.githubusercontent.com`에서
   파일 하나만 받으면 되므로 **API 한도를 쓰지 않고**, commit 날짜·가로세로·썸네일 경로가 이미 들어 있다.
2. **contents API** — `index.json`이 없을 때의 대비책. 시간당 60회 한도를 쓰고, 날짜 정렬과
   썸네일 축소가 꺼진다. 이 경우 관리자에게만 안내 문구가 보인다.

## group 자동 생성

파일명을 하이픈으로 끊어 `min_group`개 이상 모이는 **가장 긴 prefix**를 group으로 잡는다.
여기에 두 가지 보정이 붙는다.

- 더 긴 prefix에 식구를 빼앗겨 혼자 남은 group은 `Other`로 보낸다.
- group 이름을 식구들의 공통 부분까지 늘린다. `apex-ice` → `apex-ice-arena`.

`group_depth=2` 기준으로 현재 폴더는 이렇게 나뉜다.

```
Apex Ice Arena 2   Harper Park 6   Prairie 2        Red 2
Apex Park 3        Heb 2           Prairie Road 2   White 2
Georgetown Resort Pool 2           Pumpjacks 2      Zernike Pyramid Noll 2
Lawn Worms Eye 3   Margaret Hunt Hill Bridge 2      Other 43
```

## 속성

| 속성 | 기본값 | 설명 |
|---|---|---|
| `github_url` | — | 필수. `tree`/`blob` URL 또는 `owner/repo/path` |
| `column` | 4 | 최대 열 수. 좁은 화면에서 자동으로 줄어든다 |
| `gap` | 14 | 타일 간격 px |
| `ratio` | `4/3` | 타일 비율. `auto`면 그림의 실제 비율을 쓴다 |
| `group_depth` | 2 | group으로 삼을 하이픈 마디 수의 최대값 |
| `min_group` | 2 | 이 개수 이상 모여야 group으로 인정 |
| `groups` | `''` | 처음부터 선택해 둘 group slug, 쉼표 구분 |
| `sort` | `name_asc` | `name_asc` \| `name_desc` \| `date_desc` \| `date_asc` |
| `sort_by_date` | 0 | 1이면 날짜 최신순으로 시작 |
| `show_date` | 0 | caption에 commit 날짜 표시 |
| `show_name` | 1 | caption에 파일명 표시 |
| `show_search` | 1 | 검색 입력칸 표시 |
| `lightbox` | 1 | 클릭 시 overlay. 0이면 새 탭 |
| `limit` | 0 | 0이면 전부 |
| `cache` | 60 | 목록 캐시 분 |

## 설치

1. 플러그인 화면 → 새로 추가 → 플러그인 업로드에 `github-image-gallery.zip`.
2. 이후 업데이트는 플러그인 화면에 알림으로 뜬다. 아래 릴리스 절차를 따르면 된다.

비공개 저장소를 읽거나 API 한도를 5000회로 올리려면 `wp-config.php`에 토큰을 둔다.
`index.json` 경로만 쓴다면 없어도 된다.

```php
define('GITHUB_GALLERY_TOKEN', 'ghp_...');
```

## 새 버전 내보내기

1. `github-image-gallery.php`의 `Version:`과 `GIG_VERSION`을 올린다.
2. tag를 민다. 워크플로가 tag와 헤더 버전이 같은지 검사한 뒤 zip을 만들어 release에 붙인다.

```bash
git tag plugin-v1.0.1 && git push origin plugin-v1.0.1
```

## 썸네일 다시 만들기

`Images/`에 그림을 넣고 push하면 CI가 알아서 한다. 손으로 돌리려면:

```bash
pip install pillow
python3 tools/build_image_index.py            # --dir, --width, --quality
```

바뀌지 않은 그림의 썸네일은 sha1로 걸러 다시 만들지 않는다.

## 워크플로 설치가 한 단계 남았다

`tools/workflows/`의 두 파일을 `.github/workflows/`로 옮겨야 CI가 돈다.
이 세션의 토큰에 `workflow` 권한이 없어 대신 넣어 두지 못했다.

```bash
mkdir -p .github/workflows
git mv tools/workflows/image-index.yml   .github/workflows/
git mv tools/workflows/plugin-release.yml .github/workflows/
git commit -m "Install the gallery workflows" && git push
```

옮기기 전까지는 `Images/`에 그림을 넣어도 `index.json`이 갱신되지 않으므로,
위의 `python3 tools/build_image_index.py`를 직접 돌려 커밋하면 된다.
