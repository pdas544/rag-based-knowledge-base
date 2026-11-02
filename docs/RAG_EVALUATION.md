# RAG Evaluation

Offline, reproducible evaluation of retrieval and answer quality. It runs inside
Laravel (`php artisan rag:evaluate`), uses the same `RagRetriever` and
`RagPromptBuilder` as chat, and stores every run in MySQL + JSON artifacts.

## Why in-house

No Python/RAGAS runtime is required for the prototype. IR metrics (Recall,
Precision, MRR, nDCG, HitRate) are deterministic PHP; generation checks use a
calibrated LLM-as-judge plus deterministic citation/refusal checks. The judge is
the only non-deterministic dependency and is versioned per run.

## Dataset

`backend/laravel/eval/datasets/golden.jsonl` — 50 cases (45 answerable, 5
unanswerable/injection). Provenance and labeling rules:
`eval/datasets/README.md`.

Each case:

```json
{"id":"q003","question":"...","answerable":true,"expected_answer":"...",
 "relevant_document_ids":[5],"relevant_chunk_ids":[22,27],
 "tags":["dsa","numeric"],"notes":""}
```

The dataset SHA-256 is recorded on every run. A baseline whose hash differs is
rejected unless you intentionally re-freeze it.

## Commands

```bash
# retrieval-only (no API key needed; uses the live embedding provider)
docker compose exec app php artisan rag:evaluate --mode=retrieval \
  --dataset=eval/datasets/golden.jsonl

# full: retrieval + generation + judge + citations + refusals
docker compose exec app php artisan rag:evaluate --mode=full \
  --dataset=eval/datasets/golden.jsonl \
  --baseline=eval/baselines/current.json \
  --calibrate=eval/calibration/human_labels.jsonl

# smoke / CI (no persistence, no live key, fake providers)
php artisan test --filter='RagEvaluationTest|RetrievalMetricsTest'

# freeze a new baseline after an intentional change
docker compose exec app php artisan rag:evaluate --mode=full \
  --accept-baseline --baseline-path=eval/baselines/current.json
```

Useful flags: `--limit=N`, `--top-k=5`, `--threshold=0.5`,
`--chat-model=...`, `--skip-judge`, `--dry-run`/`--no-persist`.

## Metrics

Retrieval is measured twice:

- **ranking** (`threshold=0`, no fallback) — quality of the vector ranker.
- **production** (`QDRANT_SCORE_THRESHOLD`, keyword fallback enabled) — what
  chat actually sees.

Per answerable case: Recall@1/3/5, Precision@3/5, MRR@5, nDCG@5 (binary
relevance), HitRate@5. Aggregates also report empty-result rate, fallback rate,
fallback precision, and retrieval p50/p95 latency.

Full mode adds: answer correctness, faithfulness, answer relevancy, context
precision/recall, citation accuracy, refusal accuracy, false-answer rate,
generation p50/p95, tokens and cost per query.

Citation accuracy is deterministic: every `[title chN]` must match a retrieved
context. A grounded answer with no citation scores 0; a refusal with no citation
scores 1.

## Thresholds and regression gates

Set in `backend/laravel/.env`:

| Variable | Default | Gate |
|---|---|---|
| `RAG_EVAL_MIN_RECALL_AT_5` | 0.70 | production Recall@5 |
| `RAG_EVAL_MIN_FAITHFULNESS` | 0.70 | judge faithfulness |
| `RAG_EVAL_MAX_FALSE_ANSWER_RATE` | 0.20 | unanswerable cases answered |
| `RAG_EVAL_MAX_P95_MS` | 30000 | generation p95 (retrieval p95 in retrieval mode) |

Exit codes: `0` pass, `1` threshold failure, `2` invalid config/dataset/baseline
hash mismatch. Baseline comparison prints current vs baseline deltas; thresholds
are the hard gate.

## Current baseline

Two baselines were frozen on 2026-11-02, 50 cases, `top_k=5`,
`threshold=0.5`:

| File | Generator | Judge |
|---|---|---|
| `eval/baselines/current.json` | `openai/gpt-4o-mini` | `google/gemini-2.5-flash` |
| `eval/baselines/current-gemini.json` | `google/gemini-2.5-flash` | `openai/gpt-4o-mini` |

Nightly should pin the same models (`RAG_EVAL_CHAT_MODEL` /
`RAG_EVAL_JUDGE_MODEL`) and point `RAG_EVAL_BASELINE` at the matching file, or
re-freeze a baseline for a different pair. The retrieval metrics are
model-independent apart from the embedding model.

Final `current.json` run (2026-11-02): ranking Recall@5 `1.000`, production
Recall@5 `0.889`, MRR@5 `0.744`, faithfulness `0.979`, answer correctness
`0.894`, citation accuracy `0.920`, refusal accuracy `1.000`, false-answer rate
`0`, fallback rate `0.467`, generation p95 `2.65s`. All gates passed.

## Reproducibility

Each run records: git SHA, dataset path + hash, chat/embedding model,
`EMBEDDING_DIM`, Qdrant collection, prompt version (`RagPromptBuilder::VERSION`),
judge model + version, `top_k`, threshold, timestamps, PHP version. Per-question
detail includes retrieved IDs/scores, answer, citations and judge JSON.

Artifacts: `storage/app/private/eval/<run_id>/summary.json` and
`results.jsonl`. DB: `eval_runs` / `eval_results`
(`2026_09_19_000009_create_eval_tables.php`).

## Judge and calibration

`RagJudge` calls OpenRouter with `temperature=0` and a strict JSON rubric. Set
`OPENROUTER_JUDGE_MODEL` to a model different from (and ideally stronger than)
the generator; empty falls back to the chat model. Parsing strips code fences,
finds the first JSON object, and repairs trailing commas.

`--calibrate=eval/calibration/human_labels.jsonl` reports Cohen's kappa for
faithfulness and correctness on labeled cases. The shipped labels are
author-assigned, not independent double labels — see
`eval/calibration/README.md` before treating kappa as a quality signal.

## Telemetry

`llm_call` log entries now include:

```json
"retrieval": {"attempted":true,"used_fallback":false,"hits":5,
              "top_score":0.81,"latency_ms":1200,"top_k":5,"threshold":0.5}
```

OpenRouter retries, fallbacks and circuit transitions increment daily cache
counters (`llm:retries:*`, `llm:fallbacks:*`, `llm:circuit_open:*`,
`llm:circuit_opened:*`) and log `llm_retry` / `llm_fallback` /
`llm_circuit_open*`. The admin stats endpoint exposes the latest eval metrics,
the daily counters, and `AdminDashboard` renders Recall@5, faithfulness,
false-answer rate and p95.

## CI strategy

- **PR smoke (hermetic):** `RagEvaluationTest` + `RetrievalMetricsTest` run in
  the normal backend job with fake embeddings/chat/judge and `Http::fake()`; no
  live key, no Qdrant/MySQL.
- **Nightly (live):** `.github/workflows/rag-eval-nightly.yml` runs on a
  self-hosted runner tagged `kb` against the real KB via
  `scripts/rag-eval-nightly.sh`, uploads artifacts, and fails on threshold
  regression. Enable it with the repository variable `RAG_EVAL_NIGHTLY=true`;
  optionally pin models with `RAG_EVAL_CHAT_MODEL` / `RAG_EVAL_JUDGE_MODEL`.
  It is skipped by default so forks/PRs never spend keys.

## Cost and privacy

- Retrieval-only runs cost embeddings only.
- Full runs cost one generation + one judge call per case (50 cases). Free-tier
  models are $0 but retain prompts; use paid models for production/nightly.
- The dataset contains public policy content and one appeal email; no
  credentials, contact details or PII are stored. Do not add production user
  data to the dataset.

## Triage

| Symptom | Check |
|---|---|
| Recall drops | Qdrant collection dim/status, re-index, threshold changes, dataset hash |
| Faithfulness drops | prompt version, model change, retrieval top_k, judge model |
| False-answer rate rises | unanswerable cases, `NO_CONTEXT_MESSAGE` path, moderation |
| p95 regression | provider status, retry/fallback counters, timeouts |
| Judge unavailable | `OPENROUTER_JUDGE_MODEL`, key, `rag_judge_failed` in `llm.log` |
| Dataset hash mismatch | re-label intentionally, then `--accept-baseline` |
