---
name: wp-post-to-github
description: WordPress post 본문을 GitHub markdown 으로 옮기고, post 는 header image block 과 [github_file] shortcode 만 남기도록 변환한다. "post 를 github link 로 대체", "post 본문을 markdown 으로 옮겨줘", "wordpress post 변환" 같은 요청에 사용한다. post 를 수정(update)하는 작업이며 삭제 후 재작성이 아니다. "wordpress 변환 사용법 보여줘" 처럼 사용법을 물을 때도 이 skill 을 열어 section 2 를 보여준다.
---

# WordPress Post To GitHub
Rev. 10 | Created: 2026-08-20 | Updated: 2026-08-21 18:44 UTC

## 1. Purpose

한 개의 WordPress post 를 다음 상태로 바꾼다.

- 본문 산문·표·수식은 markdown repo 의 `.md` 파일로 옮겨 그 파일이 원본이 된다.
- post 본문에는 header image block 하나와 `[github_file ...]` shortcode block 하나만 남는다.
- post id, slug, title, date, 댓글은 그대로 둔다. **post 를 지우고 새로 쓰지 않는다.**

## 2. Usage

사용자가 사용법을 물으면 아래 두 block 을 그대로 보여준다. 값은 사용자의 환경에 맞게 두고,
`<...>` 자리만 채워 쓰라고 안내한다.

```bash
# Environment
export WP_URL='https://ykim.synology.me/wordpress'
export WP_USERNAME='ykim2718@gmail.com'
export WP_APP_PASSWORD='xxxx xxxx xxxx xxxx xxxx xxxx'
```

```text
# Prompt Command
WordPress post를 github markdown link로 대체해줘
- WordPress post: <WordPress Post URL>
- GitHub markdown repo:  ykim2718/AIML → <folder>
- GitHub image repo: ykim2718/WordPress → Images
```

이미 변환한 post 를 찾는 명령도 함께 알려준다.

```bash
python3 "$WPGH"/wp_api.py converted
```

tag 없이 변환된 옛 post 는 shortcode 로 찾아 tag 만 붙인다. taxonomy 는 post field 가 아니므로
revision 이 늘지 않고, markdown 의 Rev 번호도 그대로 남는다.

```bash
for id in $(python3 "$WPGH"/wp_api.py search github_file --types posts | cut -f2); do
    python3 "$WPGH"/wp_api.py tag "$id"
done
```

## 3. Inputs

작업 전에 다음을 확보한다. 사용자가 주지 않은 것은 물어본다.

| Input | Example | Note |
|-------|---------|------|
| Post URL or id | `https://…/some-slug-6147/` | 끝의 숫자가 post id 인 permalink 가 많다. 아니면 slug 로 조회한다. |
| Markdown repo folder | `ykim2718/AIML` → `Metrics/R2` | `.md` 를 둘 위치. |
| Image repo folder | `ykim2718/WordPress` → `Images` | header image 를 둘 위치. |

인증은 환경변수로 읽는다. 값을 대화에 다시 적지 않는다.

```bash
# WP_APP_PASSWORD is a WordPress application password, not the login password.
export WP_URL='https://ykim.synology.me/wordpress'
export WP_USERNAME='ykim2718@gmail.com'
export WP_APP_PASSWORD='xxxx xxxx xxxx xxxx xxxx xxxx'
```

환경변수가 없으면 사용자에게 요청한다. `WP_USERNAME` 은 application password 를 발급할 때 붙인
**이름이 아니라 로그인 계정**이다. 이름을 넣으면 `invalid_username` 이 돌아온다. 계정을 모르면
email 을 먼저 시도한다.

두 GitHub repo 는 `add_repo` 로 세션에 붙이고 clone 한다. 두 repo 모두 `main` 에 직접 commit·push
하며 pull request 를 만들지 않는다.

skill 이 plugin 으로 로드되면 작업 directory 가 skill folder 가 아니므로, script 는 절대 경로로
부른다. 아래를 한 번 정해 두고 이후 `$WPGH` 로 쓴다.

```bash
# plugin install, or the skill folder inside the project
WPGH="${CLAUDE_PLUGIN_ROOT:-.claude}/skills/wp-post-to-github/scripts"
test -f "$WPGH/wp_api.py" || WPGH=".claude/skills/wp-post-to-github/scripts"
```

## 4. Procedure

Step 은 아래 순서대로 진행한다. **각 step 을 마칠 때마다 진행 상태와 그 step 이 저장한 파일의
경로를 화면에 보인다.** commit hash, repo 경로, attachment id 처럼 되짚을 수 있는 값을 함께
남긴다.

| Step | Action |
|------|--------|
| 1 | header image 를 image repo 에 저장한다. |
| 2 | 그 image 를 media library 에서 지운다. featured image 면 남긴다. |
| 3 | 본문을 markdown 으로 옮겨 markdown repo 에 저장한다. |
| 4 | 본문 내용을 모두 지운다. |
| 5 | image block 을 넣고 media URL 과 height 500px 를 준다. |
| 6 | 그 아래에 shortcode block 을 넣는다. |
| 7 | excerpt 를 채우고 post 를 저장하며 `github-hosted` tag 를 붙인다. |

Step 4 부터 7 까지는 한 번의 update 로 처리한다. 4.4 를 본다.

### 4.1 Step 1 — Header Image To The Image Repo

1. `"$WPGH"/wp_api.py get <ID>` 로 본문 raw 를 받아 첫 `wp:image` block 의 `src` 를 읽는다.
2. 그 URL 을 내려받는다. 경로에 `^` 같은 문자가 있으면 percent-encoding 한다 (`%5E`).
3. 파일명은 post title 에서 만든다. 소문자 kebab-case 로 줄이고, 수식 기호는 읽는 대로 편다
   (`($R^2$)` → `r2`). 대상 folder 를 `ls` 해 **이름이 겹치지 않는지 확인**한다.
4. 크기가 0.5 MB 를 넘으면 줄인다. 넘지 않으면 그대로 둔다.
   `python3 -c "import PIL"` 로 Pillow 가 있는지 먼저 보고, 없으면 `pip install Pillow` 한다.

   ```python
   # resize a header image under the 0.5 MB cap
   from PIL import Image
   im = Image.open(src)
   im.thumbnail((1600, 1600))
   im.save(dst, optimize=True, quality=85)
   ```
5. commit·push 한 뒤 raw URL 이 200 과 `image/*` 를 돌려주는지 확인한다.

   ```
   https://github.com/<OWNER>/<REPO>/blob/main/<PATH>?raw=true
   ```

### 4.2 Step 2 — Delete The Image From The Media Library

image 가 image repo 에 올라갔으므로 media library 의 원본은 지운다. **단 featured image 로
쓰이면 지우지 않는다.** 본문에서 뺀다고 featured image 지정이 풀리지는 않으므로, 지우면 목록
page, archive, 공유 카드의 thumbnail 이 사라진다.

이 step 은 본문 교체(Step 4) 보다 앞서므로 변환 중인 post 는 아직 그 image 를 가리키고 있다.
그 참조는 예상된 것이므로 `--exclude-post` 로 제외한다.

```bash
python3 "$WPGH"/wp_api.py orphan-check <ATTACHMENT_ID> --exclude-post <POST_ID>
```

- `featured_image_of` 가 비어 있지 않다 → **지우지 않는다.** 유지했다고 보고하고 Step 3 으로 간다.
- `body_references` 가 비어 있지 않다 → 다른 post 가 쓰고 있다. 지우지 않고 어느 post 인지 보고한다.
- `safe_to_delete` 가 true → 지운다. 지우기 전에 Step 1 의 push 가 끝났는지 확인한다. image repo
  의 사본이 유일한 사본이 된다.

```bash
python3 "$WPGH"/wp_api.py delete-media <ATTACHMENT_ID> --confirm
```

attachment id 는 첫 `wp:image` block 의 `wp-image-<ID>` class 또는 block 주석의 `"id"` 에 있다.
없으면 `"$WPGH"/wp_api.py search '<파일명 일부>' --types media` 로 찾는다.

### 4.3 Step 3 — Body To Markdown

1. 대상 repo 의 문서 규약을 **먼저 읽는다**. `CLAUDE.md` 가 skill 을 가리키면 (예: AIML 의
   `md_rules`) 그 skill 을 로드하고 그대로 따른다. 규약이 없으면 같은 folder 의 기존 `.md` 를
   본보기로 삼는다.
2. 파일명은 post title 을 kebab-case 로 옮긴 것이다 (slug 와 같아지는 경우가 많다).
3. 머리말은 아래 형식이며, 값은 WordPress 에서 읽는다. Step 4 의 교체가 revision 을 하나 더
   늘리므로 **본문을 교체하기 전에** 두 값을 읽어 둔다.

   ```
   # <post title>
   Rev. <N> | Created: <post date> | Updated: <now>
   ```

   - `Created` 는 post 의 생성 일자, 곧 `date` field 의 날짜다 (`YYYY-MM-DD`). 변환한 날이
     아니다.
   - `Rev. N` 은 `GET /wp-json/wp/v2/posts/<ID>/revisions` 응답 헤더의 `X-WP-Total` 에서 1 을
     뺀 값이다. 최초 저장본이 Rev. 0 이기 때문이다.
     `"$WPGH"/wp_api.py revisions <ID>` 가 `x_wp_total` 과 `rev_number` 를 함께 돌려준다.
   - 이 step 은 Step 4 의 교체보다 앞서므로 **`x_wp_total` 을 그대로 쓴다.** 교체가 revision 을
     하나 더하므로, 끝난 뒤 다시 세면 그 값이 `rev_number` 와 같아진다. 변환이 끝난 뒤에 세는
     경우에만 `rev_number` 를 쓴다.
   - `Updated` 의 timezone 은 `date +%Z` 로 읽는다.
4. 본문을 markdown 으로 옮긴다. 옮기면서 다음을 손본다.
   - `<em>` 오염 복구. WordPress 는 수식의 underscore 를 강조로 바꿔 놓는 일이 있다.
     `$\hat{y}<em>i$` → `$\hat{y}_i$`, `$SS</em>{res}$` → `$SS_{res}$`.
   - 본문 안의 image 는 외부 URL 로 남기지 말고 repo 안으로 들여 `<md stem>_fig/` 에 두고 상대
     경로로 참조한다. 원래 링크가 이미 깨져 있는 경우가 있으므로 대상 파일의 존재를 확인한다.
   - heading 번호, 표·figure 제목, 용어는 그 repo 의 규약에 맞춘다.
5. 규약이 검수를 요구하면 (md_rules section 16) 파일을 다시 읽고 위반 후보를 먼저 나열한 뒤
   고친다. 마지막에 발견·수정·보류 건수를 보고한다.
6. commit·push 한다.
7. excerpt 를 확인한다. **비어 있으면 본문에서 영어 50 단어 안팎의 요약을 지어 둔다.**
   Step 4 의 update 가 그 값을 함께 보낸다.

   ```bash
   python3 "$WPGH"/wp_api.py excerpt <ID>          # empty 가 true 이면 지어야 한다
   ```

   - WordPress 는 excerpt field 가 비면 본문에서 발췌를 만든다. 변환이 끝나면 본문에 산문이
     없으므로 그 자동 발췌가 빈 문자열이 된다. 목록 page, 검색 결과, RSS, 공유 카드가 모두
     그것을 쓰므로 field 를 직접 채워야 한다.
   - 이미 값이 있으면 **건드리지 않는다.** 사람이 쓴 문장이다.
   - 문서의 결론을 한두 문장으로 적는다. 제목을 되풀이하지 않고, markup 없이 평문으로 쓴다.

### 4.4 Step 4 To 7 — Replace The Post Body

본문 삭제, image block 추가, shortcode block 추가, 저장은 **한 번의 update 로 처리한다.** 중간
상태를 저장하면 revision 만 늘고 사이트가 잠시 빈 글이 된다.

교체할 본문은 정확히 이 두 block 이다.

```html
<!-- wp:image {"width":"auto","height":"500px","sizeSlug":"large"} -->
<figure class="wp-block-image size-large is-resized"><img src="<IMAGE_RAW_URL>" alt="" style="width:auto;height:500px"/></figure>
<!-- /wp:image -->

<!-- wp:shortcode -->
[github_file user='<OWNER>' repo='<REPO>' file='<FILE_PATH>']
<!-- /wp:shortcode -->
```

- `<FILE_PATH>` 는 repo root 기준 경로다. `https://github.com/ykim2718/AIML/blob/main/Metrics/R2/bayesian-r2.md`
  이면 `<REPO>` 는 `AIML`, `<FILE_PATH>` 는 `Metrics/R2/bayesian-r2.md` 다.
- 교체 전에 원본 본문을 scratchpad 에 백업한다.

```bash
python3 "$WPGH"/wp_api.py get 6147 --raw > "$SCRATCH/post6147.backup.html"
python3 "$WPGH"/wp_api.py update 6147 --content-file "$SCRATCH/new_body.html" \
    --excerpt-file "$SCRATCH/excerpt.txt" --add-tag github-hosted
```

`--add-tag github-hosted` 은 tag 를 같은 요청에 실어 보낸다. 변환한 post 를 나중에 한눈에
찾기 위한 표시이며, 따로 저장하지 않으므로 revision 은 하나만 늘어난다. tag term 이 없으면
만들어 붙인다. 기존 tag 는 지우지 않고 더한다.

`--excerpt-file` 은 4.3 의 7 에서 지은 요약을 같은 요청에 실어 보낸다. excerpt 가 이미 차 있던
post 에는 이 옵션을 주지 않는다.

`update` 는 `content` 와, 준 경우에 한해 `excerpt` 와 `tags` 만 보낸다. `status`, `date`,
`slug`, `title` 은 건드리지 않는다.

변환이 끝난 post 의 excerpt 를 나중에 채우려면 본문을 건드리지 않는 명령을 쓴다.

```bash
python3 "$WPGH"/wp_api.py excerpt <ID> --excerpt-file "$SCRATCH/excerpt.txt"
```

변환한 post 의 목록은 다음으로 본다. tag 로 찾고, tag 가 아직 없으면 shortcode 문자열로 찾는다.

```bash
python3 "$WPGH"/wp_api.py converted
```

## 5. Verification

1. `"$WPGH"/wp_api.py get <ID> --raw` 로 저장된 본문이 두 block 뿐인지 본다.
2. 로그인 없이 permalink 를 받아 확인한다. shortcode 가 실행되었다면 `[github_file` 문자열은
   **보이지 않아야** 하고, markdown 의 heading 과 figure 는 보여야 한다.

   ```bash
   curl -sSL "$PERMALINK" | grep -c "\[github_file"      # expect 0
   curl -sSL "$PERMALINK" | grep -o "height:500px" | head -1
   ```
3. markdown 안의 상대 경로 image 가 실제로 존재하는지 repo 에서 확인한다.
4. `"$WPGH"/wp_api.py excerpt <ID>` 로 excerpt 가 비어 있지 않은지 본다.

## 6. Media Library Notes

Step 2 가 삭제를 담당한다. 이 절은 그 판단의 근거만 적는다.

`orphan-check` 는 posts 와 pages 를 전수 조사해 두 가지를 따로 보고한다.

- `body_references` — 본문이 그 파일을 가리키는 곳. `--exclude-post` 로 제외한 post 는 빠진다.
- `featured_image_of` — 그 파일을 featured image 로 쓰는 post. 제외 대상이 없다. 변환 중인
  post 자신이 여기 걸리는 경우가 흔하며, 그때가 바로 남겨야 하는 경우다.

`delete-media` 는 `--confirm` 없이는 거부한다. 삭제는 `force=true` 라서 휴지통을 거치지 않는다.
`safe_to_delete` 가 false 인데도 지워야 한다면 사용자에게 먼저 확인을 받는다.

## 7. Scope Rules

- 한 번에 post 하나만 다룬다. 여러 개를 요청받으면 같은 절차를 순서대로 반복하고 각 post 마다
  보고한다.
- post 의 `status`, `date`, `slug`, `title`, category, tag 는 바꾸지 않는다.
- 같은 markdown 경로를 쓰는 다른 post 가 있는지 확인한다.
  `"$WPGH"/wp_api.py search '<path fragment>'` 로 찾는다.
- 본문에서 옮긴 문장을 요약하거나 늘리지 않는다. 형식만 바꾸고 내용은 보존한다.

## 8. Maintenance

이 skill 자체를 고칠 때 지킬 것.

- H1 바로 아래의 `Rev. <N> | Created: <YYYY-MM-DD> | Updated: <YYYY-MM-DD HH:MM> <TIMEZONE>`
  표시를 **수정할 때마다 갱신한다.** `N` 은 1 씩 올리고 `Updated` 는 지금 시각으로 바꾼다.
  `Created` 는 바꾸지 않는다. timezone 은 `date +%Z` 로 읽는다.
- 고친 뒤에는 **항상 marketplace plugin 사본에 복사하고 두 곳 모두 push 한다.** 한쪽만 고치면
  세션이 어디서 시작했는지에 따라 다른 skill 이 로드된다.

```bash
SRC=<project>/.claude/skills/wp-post-to-github
DST=<claude-configuration>/plugins/yrocket-plugins/skills/wp-post-to-github
cp "$SRC/SKILL.md" "$DST/SKILL.md"
cp "$SRC/scripts/wp_api.py" "$DST/scripts/wp_api.py"
diff -r --exclude=__pycache__ "$SRC" "$DST"     # expect no output
```

- 두 repo 모두 `main` 에 직접 commit·push 한다. `__pycache__` 는 복사하지 않는다.
