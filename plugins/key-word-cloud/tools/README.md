# Topic Pipeline

Rev. 1 | Created: 2026-08-28 | Updated: 2026-08-28 15:58 CDT

`ranking=topics` 로 그릴 topic 을 만드는 script 네 개이다. plugin 과 별개로 돌며, zip 에도
들어가지 않는다. 네 script 는 순서대로 실행하고, 앞 단계의 JSON 을 다음 단계가 읽는다.

## 1. Reason

Plugin 은 낱말을 센다. 낱말을 아무리 잘 세어도 `wafer` 와 `variation` 이
`within-wafer variation` 이 되지는 않는다. 그 구절은 본문에 하나의 token 으로 있지 않기
때문이다. Language model 은 그것을 읽어내지만 GPU 가 필요하므로, plugin 이 도는 server 가
아니라 GPU 가 있는 기계에서 만들고 결과만 보낸다.

## 2. Requirement

- Ollama 가 돌고 있어야 하며, 기본 주소는 `http://localhost:11434` 이다.
- Model 두 개를 받아 둔다.
- Python 은 `scikit-learn` 과 `numpy` 가 필요하다. 나머지는 표준 library 만 쓴다.

```bash
ollama pull qwen3:8b
ollama pull bge-m3
pip install scikit-learn
```

## 3. Step

### 3.1. Extract

글마다 language model 에게 key phrase 를 묻는다. Database 는 docker CLI 로 읽으므로 접속
정보를 환경에 둔다.

```bash
export KWC_DB_CONTAINER=<CONTAINER_NAME>
export KWC_DB_USER=<DB_USER>
export KWC_DB_PASSWORD=<DB_PASSWORD>

python tools/llm_keywords.py --limit 300 --chars 3000
```

두 번째 pass 가 phrase 를 topic 으로 묶으려 시도하지만, phrase 가 수백 개면 model 이
지시를 놓치고 목록을 그대로 베낀다. 그래서 묶는 일은 3.2 가 맡는다. 이 단계의 산출물인
`llm_keywords.json` 의 `posts` 부분만 다음 단계가 읽는다.

### 3.2. Cluster

Phrase 를 embedding model 로 vector 로 바꾸고 cosine 거리로 묶는다. Model 이 아니라
산술이 묶으므로 phrase 가 몇 개든 결과가 흔들리지 않는다.

```bash
python tools/cluster_keywords.py --model bge-m3 --threshold 0.40
```

`--threshold` 는 두 무리를 더 합칠지 정하는 cosine 거리이다. 글 100 개에서 얻은 phrase
409 개로 잰 결과는 Table 1 과 같다.

Table 1. Effect of the clustering threshold

| Threshold | English clusters | Single-phrase clusters | Largest cluster |
|---|---|---|---|
| 0.35 | 241 | 167 | 7 phrases |
| 0.40 | 197 | 111 | 7 phrases |
| 0.45 | 151 | 64 | 14 phrases |

낮추면 무리가 잘고 정확해지지만 혼자 남는 phrase 가 늘어 글자 크기 차이가 사라진다.
높이면 덩어리가 커지는 대신 뜻이 다른 phrase 가 섞인다. 0.40 이 그 사이이다.

한국어와 영어는 따로 묶는다. Multilingual embedding 에서도 두 언어는 서로 다른 영역에
놓여서, 섞어 두면 한국어 phrase 전부가 하나의 무리로 뭉친다.

Embedding model 의 선택이 결과를 크게 바꾼다. 영어 중심 model 은 한국어 phrase 사이의
거리를 거의 구별하지 못한다. 같은 자료에서 `nomic-embed-text` 는 한국어 54 개를 무리
하나로 만들었고, `bge-m3` 는 33 개로 나누었다.

### 3.3. Label

무리마다 어느 분야의 것인지를 붙인다. 분야 이름은 부르는 쪽이 적고, model 은 각 무리가
그중 어디에 드는지만 답한다. 이 단계는 건너뛸 수 있으며, 건너뛰면 plugin 은 분야를 가르지
않고 전부 그린다.

```bash
python tools/label_fields.py --input cluster_keywords.json \
    --fields "semiconductor, machine learning, applied statistics"
```

무리 하나가 여러 분야에 들 수 있고, 어디에도 안 드는 무리는 그대로 남는다. 목록에 없는
이름을 model 이 지어내면 버리고 몇 개를 버렸는지 센다. 어느 분야에도 무리가 남지 않으면
결과를 쓰지 않고 멈춘다. 그 상태로 올리면 구름이 빈 이유를 site 에서 찾게 된다.

Table 2. Labelling 240 clusters into three fields with `qwen3:8b`

| Field | Clusters |
|---|---|
| applied statistics | 37 |
| machine learning | 26 |
| semiconductor | 15 |
| in none of them | 165 |

240 개를 20 개씩 묶어 물어 224 초가 걸렸고, 목록에 없는 이름 3 개가 버려졌다. 분야가 둘
붙은 무리가 있어 열의 합은 240 을 넘는다. 세 분야로 가르면 대부분의 무리는 어디에도 들지
않는다. 분야를 좁게 잡을수록 그 수는 늘고, 그것이 분야를 고르는 목적이다.

### 3.4. Push

무리를 topic 으로 바꾸어 site 에 올린다. 인증은 WordPress application password 이며,
글을 고칠 수 있는 사용자여야 한다.

```bash
export WP_URL=https://example.com/wordpress
export WP_USERNAME=<LOGIN_NAME>
export WP_APP_PASSWORD=<APPLICATION_PASSWORD>

python tools/push_topics.py --input label_fields.json --dry-run true
python tools/push_topics.py --input label_fields.json --min-posts 2
```

Application password 는 WP 관리자 → 사용자 → 프로필에서 발급한다. Site 가 https 가
아니면 WordPress 가 application password 를 거부하므로, 그 경우 `WP_ENVIRONMENT_TYPE` 을
`local` 로 두어야 한다.

## 4. Limitation

- Language model 이 뽑는 phrase 는 글마다 고유한 편이다. 글 100 개에서 phrase 409 개가
  나왔고 그중 둘 이상의 글에 걸친 것은 15 개뿐이었다. 묶지 않으면 구름이 되지 않는다.
- 무리에 뜻이 다른 phrase 가 섞이는 일이 남는다. Threshold 로 줄일 수는 있으나 없앨 수는
  없다.
- 글이 늘거나 바뀌면 네 단계를 다시 돌려야 한다. Plugin 은 마지막으로 올라온 topic 을
  계속 보여 줄 뿐 스스로 갱신하지 않는다.
