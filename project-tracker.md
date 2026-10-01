# Project Tracker — User Dashboard RAG

> Source: `plans/user-dashboard-rag.md:10` — Compressed 2-week prototype, OpenRouter gateway, Global KB.
> Mark sub-modules as `[x]` once done. Update `Status` and commit.

## Progress Summary
| Phase | Scope | Status | Completion |
|-------|-------|--------|------------|
| Phase 0 | Scaffold (DB, LLM abstraction, Qdrant, Routes, Frontend shell, Infra) | 🟩 Done | 11/11 |
| Phase 1 | Core Chat (Streaming, Pagination, Retention, Export, Quotas, UI) | ⬜ Not Started | 0/10 |
| Phase 2 | RAG + Moderation (Upload, Parse, Chunk, Embed, Review) | ⬜ Not Started | 0/12 |
| Phase 3 | Hardening (Search, Badges, Dashboard, Summarization, Tests) | ⬜ Not Started | 0/11 |

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

- [ ] **1.1 Conversation CRUD** — `ConversationController` `POST /conversations`, `GET /conversations` (paginated 20), `GET /{id}`, `PATCH /{id}/title`, `DELETE /{id}` (soft)
- [ ] **1.2 Message Streaming** — `MessageController@store` `POST /conversations/{id}/messages` validate `1..8000`, SSE `text/event-stream` via `OpenRouterProvider::streamChat`, save `messages` with `model`, update `conversations.total_tokens/message_count/last_message_at`
- [ ] **1.3 Message Pagination** — `GET /conversations/{id}/messages?cursor=&limit=20` cursor pagination, index `conversation_id,created_at`
- [ ] **1.4 Auto-title** — Generate title on first assistant reply (LLM tiny call fallback `substr(prompt,0,40)`)
- [ ] **1.5 Retention Job** — `PruneConversationsJob` + `php artisan conversations:prune --days=90` soft->hard delete, scheduler `app/Console/Kernel.php:02:00`
- [ ] **1.6 Export** — `GET /conversations/{id}/export` JSON + `GET /conversations/export-all` zip (`ZipArchive`), streamed
- [ ] **1.7 Quotas & Throttle** — `EnsureQuota` middleware (`user_quotas` daily 30 prompts, 20k tokens), `throttle:60,1` on messages, 429 `Retry-After`
- [ ] **1.8 OpenRouter Integration** — Real `OPENROUTER_API_KEY` call, handle `HTTP-Referer`/`X-Title`, model `openai/gpt-4o-mini` configurable `plans/user-dashboard-rag.md:58`
- [ ] **1.9 Chat UI Core** — `ChatMessage.jsx` markdown, `ChatInput.jsx` auto-resize + disable while streaming (fetch ReadableStream, keep `axios.js:18` for REST)
- [ ] **1.10 Cache** — Redis `conversation:{id}:messages:last10` TTL 1h, `user:{id}:conversations:list` TTL 5m, invalidate on write

## Phase 2 — RAG + Moderation (Day 7-11)
**Goal:** Documents feed Global KB via staged review.

- [ ] **2.1 Document Upload API** — `DocumentController@store` `POST /api/documents` multipart, `mimes:pdf,docx,jpeg,png,webp,txt,md|max:10240`, sha256 `409` dedup, store `storage/app/documents/{user_id}/{sha256}_*` (`docker-compose.yml:9`)
- [ ] **2.2 Document List/Detail** — `GET /documents`, `GET /documents/{id}` + chunks, status badge
- [ ] **2.3 Parse Job** — `ParseChunkEmbedJob` dispatch on upload: `smalot/pdfparser`/`pdftotext` for PDF, `phpoffice/phpword` DOCX, `tesseract-ocr` fallback if chars <100, normalize whitespace
- [ ] **2.4 Chunk** — 512 tokens overlap 80 recursive splitter (`strlen/4` estimate), store `document_chunks` rows `plans/user-dashboard-rag.md:47`
- [ ] **2.5 Embed via OpenRouter** — `EmbeddingProvider` `openai/text-embedding-3-small` 1536d via `https://openrouter.ai/api/v1/embeddings`, batch embed
- [ ] **2.6 Qdrant Upsert** — Upsert points `status=awaiting_review`, payload `{document_id, owner_user_id, chunk_index, text, doc_title, mime}`, update `documents.chunk_count`
- [ ] **2.7 Dockerfile Deps** — `docker/php/Dockerfile` add `tesseract-ocr`, `poppler-utils`, `libpng-dev`
- [ ] **2.8 Admin Review API** — `POST /admin/documents/{id}/approve` -> `ready` + payload update, `.../reject` -> `rejected` + `deleteByDocumentId`, role `admin` `routes/api.php:22`
- [ ] **2.9 Admin Review UI** — Extend `App.jsx:46` `AdminDashboard` cards `Pending Reviews (N)`, table approve/reject, stats `Total Documents`/`Vector Chunks`/`Processing Queue`
- [ ] **2.10 Retrieval in Chat** — On `POST /messages`, embed query -> Qdrant `search top_k=5 threshold 0.72` filter `status=ready`, augment system prompt `Use following context`, save `sources`
- [ ] **2.11 Upload UI** — `DocumentUploadZone.jsx` drag-drop, progress, status `pending/processing/awaiting_review/ready`, 10 docs/30d quota badge
- [ ] **2.12 Queue Config** — `QUEUE_CONNECTION=redis` (`redis:6380`), workers `php artisan queue:work --queue=embed,chat,default`, failed jobs table

## Phase 3 — Hardening (Day 12-14)
**Goal:** Polish, observability, tests.

- [ ] **3.1 Conversation Search** — `GET /conversations?search=` MySQL FULLTEXT on title, frontend search in `ChatSidebar`
- [ ] **3.2 Quota Badges** — `GET /api/quotas` frontend badge `remaining uploads/prompts` in sidebar narrow
- [ ] **3.3 Token Dashboard** — Admin stats `Avg Latency`, `Tokens Used`, `Cost` (OpenRouter usage header) in `AdminDashboard`
- [ ] **3.4 Summarization** — `SummarizeConversationJob` when `message_count>10`, keep last 10 + `summary` TEXT, inject `SYSTEM SUMMARY`
- [ ] **3.5 Markdown & Citations** — `ChatMessage.jsx` `react-markdown` + `highlight.js` + `dompurify`, citation badges `[doc.pdf p.2]`, copy/regenerate/retry
- [ ] **3.6 Virtualization** — `react-virtuoso` for long histories, infinite scroll cursor
- [ ] **3.7 Guardrails** — Conversation cap 100 msgs, token estimate `strlen/4 <4000`, moderation via `openai/moderation` through OpenRouter + regex blocklist, log `storage/logs/llm.log`
- [ ] **3.8 Docker Ollama Stub** — Add `docker-compose.yml` `ollama` service disabled via profile, `ReEmbedDocumentsJob` for dim migration `1536->768`
- [ ] **3.9 Tests** — `docker compose exec app php artisan test --filter=ConversationRAGTest` (auth, CRUD, streaming, quotas, RAG retrieval, approve flow)
- [ ] **3.10 Lint & Build** — `docker compose exec app vendor/bin/pint`, `docker compose exec frontend npm run lint`, `vite build`
- [ ] **3.11 Load Test** — `k6` 10->25->50 concurrent (100 ceiling), verify p95 latency, Qdrant 200k points memory

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
curl http://localhost:82/api/conversations -H "Authorization: Bearer $TOKEN"
curl http://localhost:6333/collections/knowledge_base
```

## Open Decisions (from `plans/user-dashboard-rag.md:142`)
- Staged review (recommended) vs immediate global write.
- Quotas 10 docs/30d + 30 prompts/day + 50 msgs/convo final?
