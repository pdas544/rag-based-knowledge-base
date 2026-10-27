# Project Tracker — User Dashboard RAG

> Source: `plans/user-dashboard-rag.md:10` — Compressed 2-week prototype, OpenRouter gateway, Global KB.
> Mark sub-modules as `[x]` once done. Update `Status` and commit.

## Progress Summary
| Phase | Scope | Status | Completion |
|-------|-------|--------|------------|
| Phase 0 | Scaffold (DB, LLM abstraction, Qdrant, Routes, Frontend shell, Infra) | 🟩 Done | 11/11 |
| Phase 1 | Core Chat (Streaming, Pagination, Retention, Export, Quotas, UI) | 🟩 Done | 10/10 |
| Phase 2 | RAG + Moderation (Upload, Parse, Chunk, Embed, Review) | 🟩 Done | 12/12 |
| Phase 3 | Hardening (Search, Badges, Dashboard, Summarization, Tests) | 🟩 Done | 11/11 |
| Phase 4 | Production Readiness (Infra, Reliability, Security) | 🟩 Done | 22/22 |
| Phase 5 | RAG Evaluation (Retrieval, Generation, Regression Gates) | ⬜ Not Started | 0/12 |

---

## Phase 0 — Scaffold (Day 1-2)
**Goal:** DB + LLM + Qdrant skeletons, no business logic yet.

- [x] **0.1 Migrations** — `conversations` (softDeletes, indexes `backend/laravel/database/migrations/`) `plans/user-dashboard-rag.md:13`
- [x] **0.2 Migrations** — `messages` (FULLTEXT, `conversation_id, created_at` index)
- [x] **0.3 Migrations** — `documents` + `document_chunks` (sha256 unique, qdrant_point_id)
- [x] **0.4 Migrations** — `user_quotas` (unique user_id+date)
- [x] **0.5 Models & Policies** — `Conversation`, `Message`, `Document`, `DocumentChunk`, `UserQuota` + `ConversationPolicy`, `DocumentPolicy` (user_id ownership)
- [x] **0.6 LLM Abstraction** — `LLMProviderInterface` + `OpenRouterProvider` stub (Guzzle `https://openrouter.ai/api/v1`, headers `HTTP-Referer`/`X-Title`) + `EmbeddingProviderInterface` + `LLMFactory` (`config/services.php`)
- [x] **0.7 QdrantService** — Guzzle client `http://qdrant:6333`, methods `createCollection`, `upsert`, `search`, `deleteByDocumentId` (`qdrant:6333` from `docker-compose.yml:68`)
- [x] **0.8 Routes Skeleton** — `routes/api.php:10` group `auth:sanctum` placeholders for `/conversations`, `/messages`, `/documents`, `/admin/documents`, `/quotas`
- [x] **0.9 Frontend Shell** — `pages/Chat.jsx` + `components/ChatSidebar.jsx` skeleton replacing `App.jsx:27` `UserChat`, `context/ChatContext.jsx`
- [x] **0.10 Infra Config** — `.env` additions `LLM_DRIVER=openrouter`, `OPENROUTER_API_KEY`, `OPENROUTER_CHAT_MODEL`, `EMBEDDING_DIM`, `QDRANT_*`; `config/services.php` bindings
- [x] **0.11 Composer Deps** — `composer require smalot/pdfparser phpoffice/phpword` (OpenRouter via HTTP client, no extra SDK) + verified `php artisan migrate`

## Phase 1 — Core Chat (Day 3-6)
**Goal:** Chat works end-to-end without RAG (streaming LLM via OpenRouter).

- [x] **1.1 Conversation CRUD** — `ConversationController` `POST /conversations`, `GET /conversations` (paginated 20), `GET /{id}`, `PATCH /{id}/title`, `DELETE /{id}` (soft)
- [x] **1.2 Message Streaming** — `MessageController@store` `POST /conversations/{id}/messages` validate `1..8000`, SSE `text/event-stream` via `OpenRouterProvider::streamChat`, save `messages` with `model`, update `conversations.total_tokens/message_count/last_message_at`
- [x] **1.3 Message Pagination** — `GET /conversations/{id}/messages?cursor=&limit=20` cursor pagination, index `conversation_id,created_at`
- [x] **1.4 Auto-title** — Generate title on first assistant reply (LLM tiny call fallback `substr(prompt,0,40)`)
- [x] **1.5 Retention Job** — `PruneConversations` command + `php artisan conversations:prune --days=90` soft->hard delete, scheduler `routes/console.php:02:00`
- [x] **1.6 Export** — `GET /conversations/{id}/export` JSON + `GET /conversations/export-all` zip (`ZipArchive`), streamed
- [x] **1.7 Quotas & Throttle** — `EnsureQuota` middleware (`user_quotas` daily 30 prompts, 20k tokens), `throttle:60,1` on messages, 429 `Retry-After`
- [x] **1.8 OpenRouter Integration** — Real `OPENROUTER_API_KEY` call (true SSE via Guzzle `stream:true`), handle `HTTP-Referer`/`X-Title`, model `openai/gpt-4o-mini` configurable `plans/user-dashboard-rag.md:58`, stub when key empty
- [x] **1.9 Chat UI Core** — `ChatMessage.jsx` bubbles+copy, `ChatInput.jsx` auto-resize + disable while streaming (fetch ReadableStream, keep `axios.js:18` for REST)
- [x] **1.10 Cache** — `user:{id}:conversations:list` TTL 5m (`Cache::remember`), `conversation:{id}:messages:last10` invalidation key reserved, invalidate on write

## Phase 2 — RAG + Moderation (Day 7-11)
**Goal:** Documents feed Global KB via staged review.

- [x] **2.1 Document Upload API** — `DocumentController@store` `POST /api/documents` multipart, `mimes:pdf,docx,jpeg,png,webp,txt,md|max:10240`, sha256 `409` dedup, store `storage/app/documents/{user_id}/{sha256}_*` (`docker-compose.yml:9`)
- [x] **2.2 Document List/Detail** — `GET /documents`, `GET /documents/{id}` + chunks, status badge
- [x] **2.3 Parse Job** — `ParseChunkEmbedJob` dispatch on upload: `smalot/pdfparser`/`pdftotext` for PDF, `phpoffice/phpword` DOCX, `tesseract-ocr` fallback if chars <100, normalize whitespace
- [x] **2.4 Chunk** — 512 tokens overlap 80 recursive splitter (`strlen/4` estimate), store `document_chunks` rows `plans/user-dashboard-rag.md:47`
- [x] **2.5 Embed via OpenRouter** — `EmbeddingProvider` `nvidia/nemotron-3-embed-1b:free` 2048d via `https://openrouter.ai/api/v1/embeddings`, batch embed
- [x] **2.6 Qdrant Upsert** — Upsert points `status=awaiting_review`, payload `{document_id, owner_user_id, chunk_index, text, doc_title, mime}`, update `documents.chunk_count`
- [x] **2.7 Dockerfile Deps** — `docker/php/Dockerfile` add `tesseract-ocr`, `poppler-utils`, `phpredis` (queue needs ext), rebuilt image verified
- [x] **2.8 Admin Review API** — `POST /admin/documents/{id}/approve` -> `ready` + payload update, `.../reject` -> `rejected` + `deleteByDocumentId`, role `admin` `routes/api.php:22`
- [x] **2.9 Admin Review UI** — Extend `App.jsx:46` `AdminDashboard` cards `Pending Reviews (N)`, table approve/reject, stats `Total Documents`/`Vector Chunks`/`Processing Queue`
- [x] **2.10 Retrieval in Chat** — On `POST /messages`, embed query -> Qdrant `search top_k=5 threshold 0.5` (`QDRANT_SCORE_THRESHOLD`) filter `status=ready`, augment system prompt `Use following context`, save `sources`
- [x] **2.11 Upload UI** — `DocumentUploadZone.jsx` drag-drop, progress, status `pending/processing/awaiting_review/ready`, 10 docs/30d quota badge
- [x] **2.12 Queue Config** — `QUEUE_CONNECTION=redis` (`redis:6380`), workers `php artisan queue:work --queue=embed,chat,default`, failed jobs table

## Phase 3 — Hardening (Day 12-14)
**Goal:** Polish, observability, tests.

- [x] **3.1 Conversation Search** — `GET /conversations?search=` MySQL FULLTEXT on title (LIKE fallback on sqlite), debounced search box in `ChatSidebar`
- [x] **3.2 Quota Badges** — `GET /api/quotas` sidebar badge `prompts used/limit · docs left`
- [x] **3.3 Token Dashboard** — Admin stats `Tokens Used`, `Avg Tokens/Msg`, `Est. Cost` (DB-backed aggregates + blended rates) in `AdminDashboard`
- [x] **3.4 Summarization** — `SummarizeConversationJob` once `message_count>=12`, keep last 10 + `summary` TEXT, inject `SYSTEM SUMMARY`
- [x] **3.5 Markdown & Citations** — `ChatMessage.jsx` `react-markdown` + `highlight.js` + `dompurify`, citation badges `[title]`, copy/retry (re-ask as fresh turn), SSE logic moved to `ChatContext.sendMessage`
- [x] **3.6 Virtualization** — `react-virtuoso` message list (`firstItemIndex` prepend) + `Load older` cursor button
- [x] **3.7 Guardrails** — Cap 100 + token estimate kept; `ModerationService` (prompt-injection blocklist + optional LLM judge via `MODERATION_MODEL`, fail-open), `llm.log` channel for `llm_call`/`moderation_blocked`
- [x] **3.8 Docker Ollama Stub** — `docker-compose.yml` `ollama` service behind `ollama` profile (off by default), `ReEmbedDocumentsJob` (fresh collection required for dim change)
- [x] **3.9 Tests** — `HardeningTest` (search, injection-422, quota-429, summarization); full suite 22/22, frontend vitest 7/7
- [x] **3.10 Lint & Build** — `vendor/bin/pint`, `npm run lint`, `vite build` all clean
- [x] **3.11 Load Test** — `k6` 10->25->50 VUs on non-LLM paths: 6666/6666 checks, 0% failed, p95 839ms (script in `/tmp/opencode`, not committed)

## Phase 4 — Production Readiness (Infra, Reliability, Security)
**Goal:** Deployable beyond single-node dev: durable workers, TLS, backups, resilience. Phases 0-3 are prototype-complete only — none cover these. Evidence gathered 2026-10-11.

### P0 — Blockers
- [x] **4.1 Queue Worker Service** — `docker-compose.yml` has no `worker`; jobs only drain via manual `exec` (AGENTS.md). Add supervisor worker (`--queue=embed,chat,default --tries=3`), `restart: unless-stopped`
- [x] **4.2 Scheduler** — `routes/console.php` registers `conversations:prune` 02:00 but nothing runs `schedule:work`/cron; retention never executes. Add scheduler service
- [x] **4.3 Frontend Prod Serve** — compose `frontend` runs `npm run dev`; nginx `root` is Laravel `public/` and never serves the built SPA. Add `vite build` + nginx static/proxy path
- [x] **4.4 Prod Env & Secrets** — `.env.example` ships `APP_ENV=local`/`APP_DEBUG=true`/`LOG_LEVEL=debug`; add `.env.production.example`, force `APP_DEBUG=false`, move `root_secret`/`knowledgebase_secret` out of `docker-compose.yml`
- [x] **4.5 Network Isolation** — drop host port exposure for MySQL `3307`, Redis `6380`, Qdrant `6333` (compose); add auth on internal services
- [x] **4.6 TLS + HSTS** — nginx `listen 80` only; API Bearer tokens in cleartext. Add TLS termination + HSTS
- [x] **4.7 Restart / Limits / Healthchecks** — add to app, nginx, frontend, redis, qdrant (only MySQL has healthcheck); Qdrant memory cap 2G (`plans/user-dashboard-rag.md:38`)
- [x] **4.8 Backups** — `scripts/backup.sh` + `restore.sh` (MySQL dump, Qdrant collection snapshot, `storage/app/private/documents`), nightly cron + runbook
- [x] **4.9 CI/CD** — no `.github/workflows`; add PR pipeline for `php artisan test`, `vendor/bin/pint`, `npm run lint`, `npm run test`, `vite build`

### P1 — Required before real traffic
- [x] **4.10 LLM Resilience** — single provider, no retry/backoff/fallback; observed Nvidia free-tier `Service temporarily overloaded` 3x. Add retry + provider fallback + circuit breaker; use paid model for prod (free tier trains on prompts — privacy)
- [x] **4.11 Log Rotation** — `config/logging.php` `llm_file` is `single` (unbounded). Switch app + llm channels to `daily`
- [x] **4.12 Upload Content Scan** — plan §6 "scan placeholder" never implemented; `mimes:` trusts extension. Add ClamAV content scan in `ParseChunkEmbedJob`
- [x] **4.13 Atomic Quotas** — `UserQuota` counters are read-modify-write; concurrent sends race. Use atomic `increment` in a transaction
- [x] **4.14 Trusted Proxies** — `throttle:60,1` (`routes/api.php:25`) is per-IP; behind a proxy all users share one bucket -> false 429s. Configure `TrustProxies`/forwarded headers
- [x] **4.15 Session Config** — `SESSION_DRIVER=database` but no `sessions` migration (only `cache`/`jobs`). Align config or add table
- [x] **4.16 Health & Metrics** — `GET /api/health` (DB/cache/Qdrant/queue/failed jobs, 200/503) + token/cost aggregates in `AdminDashboard`

### P2 — Hardening / scale
- [x] **4.17 Dockerfile** — runs as root, no `composer install --no-dev --optimize-autoloader`, no opcache tuning, no `.dockerignore`; add `artisan optimize` at deploy
- [x] **4.18 Nginx Hardening** — no gzip, no security headers (CSP/X-Frame-Options/X-Content-Type-Options), no static caching
- [x] **4.19 Orphan File Prune** — soft-deleted/rejected uploads remain on disk (prune only touches DB); add storage cleanup
- [x] **4.20 Index Review** — admin token/cost aggregates unindexed; audit `messages`/`documents` indexes at scale
- [x] **4.21 Test Gaps** — `ProductionReadinessTest` covers job failures, atomic quota at cap, Qdrant outage, virus scanning, file pruning; API flow covered by feature suite. Browser E2E (Playwright) remains a separate gap
- [x] **4.22 Runbook & Docs** — deploy steps, env matrix, backup/restore, `ReEmbedDocumentsJob` dim-change runbook

---

## Phase 5 — RAG Evaluation & Quality Gates
**Goal:** reproducible offline evaluation of retrieval and answer quality, with regression gates and production telemetry.
**Baseline:** industry IR metrics (Recall@k, MRR, nDCG) + RAGAS-style generation checks (faithfulness, relevancy, context precision/recall) + calibrated LLM-as-judge; no Python/RAGAS runtime dependency.

- [ ] **5.1 Golden dataset & taxonomy** — `backend/laravel/eval/datasets/golden.jsonl` (versioned, hashed): `id`, `question`, `answerable`, `expected_answer`, `relevant_document_ids`, `relevant_chunk_ids`, `tags`, `notes`. 40–60 cases from approved KB docs: acronyms (DSA/NIFT), numeric/budget, eligibility, PDF/DOCX/OCR, multi-chunk, unanswerable, prompt-injection. No PII/secrets; record provenance in `eval/datasets/README.md`
- [ ] **5.2 Shared retrieval/prompt path** — extract inline retrieval, `keywordFallback`, and prompt construction from `MessageController` into `RagRetriever` + `RagPromptBuilder` so chat and eval run identical logic (prevents eval/chat drift); version the prompt with a hash
- [ ] **5.3 Retrieval metrics** — `php artisan rag:evaluate --mode=retrieval`; compute **Recall@1/3/5, Precision@3/5, MRR@5, nDCG@5, HitRate@5**, empty-result rate, keyword-fallback rate/precision, retrieval p50/p95. Measure ranking at threshold `0` and production threshold `0.5` separately (`QdrantService::search`)
- [ ] **5.4 Generation metrics** — `--mode=full`: answer correctness, faithfulness/groundedness, answer relevancy, context precision/recall, **citation accuracy** (cited `[title chN]` must be retrieved/GT), **refusal accuracy** on unanswerable cases, false-answer rate, generation p50/p95, tokens/cost per query. Reuse `messages.sources` JSON
- [ ] **5.5 LLM-as-judge + calibration** — `RagJudge` via OpenRouter, `temperature=0`, strict JSON rubric, judge model/version logged; human-label 20% sample and report agreement (Cohen's kappa); use a stronger/different judge than the generator; pairwise baseline-vs-candidate for regressions
- [ ] **5.6 Run persistence & reproducibility** — record git SHA, chat/embedding model, `EMBEDDING_DIM`, Qdrant collection + snapshot, dataset hash, prompt version, `top_k`/threshold, judge model, timestamp; per-question detail (retrieved IDs/scores, answer, citations, judge scores). DB `eval_runs`/`eval_results` (portable MySQL/SQLite DDL, prefix `2026_09_19_000009_…`) + JSON artifacts in `storage/app/private/eval/`
- [ ] **5.7 Baseline & regression gate** — `--baseline=<run|file>`, per-metric deltas, non-zero exit below `RAG_EVAL_MIN_RECALL_AT_5`, `…MIN_FAITHFULNESS`, `…MAX_FALSE_ANSWER_RATE`, `…MAX_P95_MS`; refuse silent dataset changes unless `--accept-baseline`
- [ ] **5.8 Production telemetry** — extend `llm_call` logging with `retrieval` block (`used_fallback`, `hits`, `top_score`, retrieval latency); aggregate p50/p95 latency, refusal rate, fallback rate, provider retry/circuit rates, tokens/cost per query; admin-only summary in `AdminDashboard`
- [ ] **5.9 CI strategy** — PR smoke: 10–15 retrieval-only cases with fake embeddings/`Http::fake()` (no live key, stays hermetic per `phpunit.xml`); nightly full eval on a snapshot collection with a paid key, publishing artifacts and failing on threshold regression
- [ ] **5.10 Tests** — `RagEvaluationTest` for metric math (Recall/MRR/nDCG), judge JSON parsing/repair, command exit codes and `--dry-run`; live-LLM evaluation stays out of the default suite
- [ ] **5.11 Docs & runbook** — `docs/RAG_EVALUATION.md` (labeling policy, commands, thresholds, baseline updates, cost/privacy) and `docs/RUNBOOK.md` eval cadence + failure triage
- [ ] **5.12 Acceptance criteria** — 40+ labeled cases; retrieval + full runs reproducible within tolerance; baseline frozen for 2 models; CI smoke + nightly gate green; admin metrics visible; docs complete

**Open decisions:** judge model + budget; DB tables vs JSON-only; human thumbs-up/down feedback timing; dataset labeling owner; nightly live-eval cost approval.

---

## How to Update
1. Complete a sub-module, check its box `[x]` and update `Progress Summary` count.
2. Commit with message `tracker: mark X.Y done — <detail>`.
3. Keep `Status` per phase: `⬜ Not Started` → `🟨 In Progress` → `🟩 Done`.

## Verification Commands
```bash
docker compose exec app php artisan test --filter=ConversationRAGTest
docker compose exec app vendor/bin/pint
docker compose exec frontend npm run lint
docker compose exec app php artisan rag:evaluate --mode=retrieval --dataset=eval/datasets/golden.jsonl
docker compose exec app php artisan rag:evaluate --mode=full --dataset=eval/datasets/golden.jsonl --baseline=eval/baselines/current.json
curl http://localhost:82/api/conversations -H "Authorization: Bearer $TOKEN"
curl http://localhost:6333/collections/knowledge_base
```

## Open Decisions (from `plans/user-dashboard-rag.md:142`)
- Staged review (recommended) vs immediate global write.
- Quotas 10 docs/30d + 30 prompts/day + 50 msgs/convo final?
