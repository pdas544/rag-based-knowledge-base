# User Dashboard RAG Plan — ChatGPT-style Chat + Global KB

## 1. Context & Constraints
- Stack: Laravel `backend/laravel` (Sanctum `routes/api.php:10`), React Vite `frontend/react-dashboard/src/App.jsx:27`, MySQL `docker-compose.yml:43`, Redis `6380`, Qdrant `6333`, Nginx `82`.
- Prototype ceiling: 100 concurrent (design for ~10-15 concurrent peak test), 1000 docs (10/user), 10MB/file, PDF/DOCX/Images, global KB, **OpenRouter gateway now -> hybrid (OpenRouter + local Ollama) later**, 90d retention, JSON export, 10 docs/month free tier.
- Existing `storage/documents` mount `docker-compose.yml:9`, `client_max_body_size 50M` `docker/nginx/default.conf:14`.
- Entry: `http://localhost` via Nginx, API `http://localhost:82/api`, frontend dev `http://localhost:5173`.

## 2. Architecture

### 2.1 DB Schema (migrations in `backend/laravel/database/migrations/`)
```php
conversations: id BIGINT PK, user_id FK -> users.id cascade, title VARCHAR(255) nullable, model VARCHAR(100) default 'openai/gpt-4o-mini' (OpenRouter model id, e.g., anthropic/claude-3.5-sonnet, google/gemini-1.5-flash), total_tokens INT default 0, message_count INT default 0, summary TEXT nullable, last_message_at TIMESTAMP nullable, is_archived BOOL default false, created_at, updated_at, deleted_at (softDeletes)
  INDEX(user_id, updated_at DESC)
  INDEX(user_id, last_message_at DESC)

messages: id BIGINT PK, conversation_id FK -> conversations.id cascade, user_id FK -> users.id, role ENUM('user','assistant','system'), content MEDIUMTEXT, tokens INT nullable, model VARCHAR(100) nullable (OpenRouter model id used for this turn), sources JSON nullable (Qdrant point ids + doc refs), created_at, updated_at
  INDEX(conversation_id, created_at)
  FULLTEXT(content)

documents: id BIGINT PK, user_id FK, filename VARCHAR(255), mime VARCHAR(100), size_bytes INT UNSIGNED, sha256 CHAR(64) unique, path VARCHAR(500), status ENUM('pending','processing','awaiting_review','ready','rejected','failed') default 'pending', chunk_count INT default 0, qdrant_collection VARCHAR(100) default 'knowledge_base', error TEXT nullable, created_at, updated_at
  INDEX(user_id, status)
  INDEX(sha256)

document_chunks: id BIGINT PK, document_id FK cascade, chunk_index INT, content TEXT, tokens INT UNSIGNED, qdrant_point_id CHAR(36) nullable unique, created_at
  INDEX(document_id, chunk_index)

user_quotas: id BIGINT PK, user_id FK, date DATE, prompt_count INT default 0, token_count INT default 0, doc_upload_count INT default 0, created_at, updated_at
  UNIQUE(user_id, date)
```

### 2.2 Qdrant (`qdrant:6333`)
- Collection `knowledge_base`, vector size `2048` (`nvidia/nemotron-3-embed-1b:free` via OpenRouter, was `1536`/`text-embedding-3-small`), distance Cosine, on-disk payload enabled.
- Payload per point: `{document_id, owner_user_id, chunk_index, text, doc_title, mime, created_at, status, is_global:true}`
- Filter on retrieval: `status == 'ready'` (only admin-approved global docs).
- Dedup by `sha256` before embedding; rejection deletes points via `QdrantService::deleteByDocumentId()`.
- Scale: 200k points * 2048 *4B ~0.8GB vectors. Single node sufficient for prototype; add `qdrant` memory limit 2G in compose for prod, sharding later via `QDRANT__STORAGE__STORAGE_PATH`.

### 2.3 Storage & Queue
- Path `storage/app/documents/{user_id}/{sha256}_{filename}` abstract via `FILESYSTEM_DISK=local` (`config/filesystems.php`); S3 driver later via `AWS_*` env.
- `QUEUE_CONNECTION=redis` (prototype may start `database`), Redis `redis:6380`, workers `php artisan queue:work --queue=embed,chat,default`.
- Jobs: `ParseChunkEmbedJob` (parse->chunk->embed->qdrant), `SummarizeConversationJob` (when message_count >10), `PruneConversationsJob` (90d).

## 3. RAG Pipeline
1. **Upload** `POST /api/documents` multipart `file` (validate `mimes:pdf,docx,jpeg,png,webp,txt,md|max:10240`, sha256 calc, duplicate 409).
2. **Parse** in `ParseChunkEmbedJob`: detect mime -> `smalot/pdfparser` or `pdftotext` for PDF, `phpoffice/phpword` for DOCX, `tesseract-ocr` fallback if extracted chars <100 (scanned PDF/image). Normalize whitespace.
3. **Chunk** 512 tokens overlap 80, recursive character splitter (tiktoken PHP port estimate `strlen/4`). Store `document_chunks` rows.
4. **Embed** via `EmbeddingProvider` through OpenRouter (model `nvidia/nemotron-3-embed-1b:free` 2048d, configurable via `OPENROUTER_EMBEDDING_MODEL`) -> upsert Qdrant points with `status=awaiting_review`, update `documents.chunk_count`, set `awaiting_review`.
5. **Review** Admin `POST /api/admin/documents/{id}/approve` -> set `ready`, update Qdrant payload `status=ready`; `.../reject` -> `rejected` + delete points.
6. **Chat** `POST /api/conversations/{id}/messages` {content} -> validate + quota -> embed query -> Qdrant search `top_k=5, score_threshold=0.72` filtered `status=ready` -> augment system prompt `Use following context:\n{citations}` -> `LLMProvider::streamChat()` -> stream SSE, save `messages` with `sources=[{point_id, doc_id, score}]`, update `conversations.total_tokens/message_count/last_message_at`, auto-title on first assistant reply via small LLM call fallback `substr(first prompt,0,40)`.
7. **Summarization** when `message_count>10`: keep last 10 messages + `summary` TEXT compressed via LLM, injected as `SYSTEM SUMMARY`.

## 4. LLM Abstraction (OpenRouter-first, Hybrid-ready)
- Interface `app/Services/LLM/LLMProviderInterface::streamChat(array $messages, array $context): Generator<string>`
- Primary implementation: `OpenRouterProvider` (OpenAI-compatible client, base `https://openrouter.ai/api/v1`, uses `openai-php/laravel` or Guzzle). Keeps options open — model is config, not code: `openai/gpt-4o-mini`, `anthropic/claude-3.5-sonnet`, `google/gemini-1.5-flash`, `meta-llama/llama-3.1-8b-instruct` etc. Switch via env without deploy.
- Future implementation: `OllamaProvider` (http `http://ollama:11434/api/chat`) for fully local hybrid; factory can route `LLM_DRIVER=ollama` for on-prem.
- `EmbeddingProviderInterface::embed(string $text): array<float>` / `embedBatch(array $texts)` — also via OpenRouter (e.g., `nvidia/nemotron-3-embed-1b:free` 2048d) so embeddings stay OpenAI-compatible. Store `embedding_model` + `embedding_dim` per document to allow migration.
- Factory `LLMFactory` via `config/services.php`: `llm.driver = env('LLM_DRIVER','openrouter')`, `llm.chat_model=env('OPENROUTER_CHAT_MODEL','openai/gpt-4o-mini')`, `llm.embedding_model=env('OPENROUTER_EMBEDDING_MODEL','nvidia/nemotron-3-embed-1b:free')`, `llm.embedding_dim=2048`, `llm.openrouter_base_url=https://openrouter.ai/api/v1`
- OpenRouter headers required: `Authorization: Bearer OPENROUTER_API_KEY`, `HTTP-Referer: APP_URL`, `X-Title: APP_NAME` + optional `provider` routing.
- Future: add `docker-compose.yml` service `ollama` disabled initially. Re-embed migration `ReEmbedDocumentsJob` when switching embedding dim (1536 -> 768 for local nomic-embed-text).

## 5. API Spec (all under `auth:sanctum` in `routes/api.php:10`)
```
POST   /api/conversations                      {title?} -> 201 {conversation}
GET    /api/conversations?page=1&limit=20      -> paginated 20 order updated_at desc, include last_message
GET    /api/conversations/{id}                 -> conversation + message_count
PATCH  /api/conversations/{id}/title           {title} -> 200
DELETE /api/conversations/{id}                 -> soft delete, 204
GET    /api/conversations/{id}/messages?cursor=&limit=20 -> cursor pagination
POST   /api/conversations/{id}/messages        {content:string 1..8000} -> SSE text/event-stream chunks {delta, done, sources, conversation}
GET    /api/conversations/{id}/export          -> JSON {conversation, messages:[{role,content,created_at,sources}], exported_at}
GET    /api/conversations/export-all           -> zip of all JSONs
POST   /api/documents                          multipart file -> 201 {document status pending}
GET    /api/documents                          -> list own docs with status
GET    /api/documents/{id}                     -> detail + chunks
GET    /api/admin/documents?status=awaiting_review -> admin only (role:admin middleware routes/api.php:22)
POST   /api/admin/documents/{id}/approve      -> 200
POST   /api/admin/documents/{id}/reject       {reason?} -> 200 + delete Qdrant points
GET    /api/quotas                             -> {doc_uploads_used, prompts_used, limits}
```
- Middleware: `throttle:60,1` on messages, custom `EnsureQuota` (checks `user_quotas` daily prompt 30, tokens 20k, doc uploads 10/30d), return `429` with `Retry-After`.
- Policies `ConversationPolicy`, `DocumentPolicy` ensure `user_id` ownership.

## 6. Guardrails (Request-Response Cycle)
- Validation `prompt required|string|min:1|max:8000` + token estimate `strlen/4 <4000` -> 422.
- Conversation cap `message_count>=100` -> 422 `Create new conversation`.
- File validation `mimes:pdf,docx,jpeg,png,webp,txt,md|max:10240`, scan placeholder, sha256 duplicate 409.
- Moderation: OpenRouter moderation via `openai/moderation` model or OpenAI moderation endpoint through OpenRouter, regex blocklist fallback, log flagged to `storage/logs/llm.log`.
- Frontend: disable input while streaming, show quota badge, token counter, remaining uploads.

## 7. Frontend (`frontend/react-dashboard/src/`)
- Replace placeholder `App.jsx:27` `UserChat` with `pages/Chat.jsx` layout:
  - `components/ChatSidebar.jsx`: conversation list (search, new, rename, delete), virtualized, polling `GET /conversations`.
  - `components/ChatMessage.jsx`: markdown via `react-markdown` + `highlight.js` for code, copy button, citation badges `[doc.pdf p.2]`, retry.
  - `components/ChatInput.jsx`: auto-resize textarea, attachment button (drag-drop), send via SSE `fetch` ReadableStream (axios.js:18 kept for REST, not stream).
  - `components/DocumentUploadZone.jsx`: progress, status badge `pending/processing/awaiting_review/ready`.
  - `context/ChatContext.jsx` or `zustand` store for conversations/messages/streaming state.
- Deps to add: `react-markdown`, `highlight.js`, `react-virtuoso`, `dompurify` for sanitization.
- Extend `App.jsx:46` `AdminDashboard` stats cards: Total Documents, Vector Chunks, Pending Reviews (N), Processing Queue, Avg Latency.
- Routing: `App.jsx:150` `dashboardPath` stays `/chat` for users, `/admin` admin sees both chat + document management.

## 8. Retention, Export & Compliance
- Command `php artisan conversations:prune --days=90 --dry-run` + scheduler `app/Console/Kernel.php` daily `02:00`.
- SoftDeletes 90d -> hard delete `messages` cascade; Qdrant docs unaffected (global KB retention separate).
- Export JSON via streamed response, `export-all` zips with `ZipArchive`.
- Redis cache: `conversation:{id}:messages:last10` TTL 1h, `user:{id}:conversations:list` TTL 5m, invalidated on write.

## 9. Infra & Config Changes
- `docker-compose.yml` add:
  ```yaml
  ollama:
    image: ollama/ollama:latest
    container_name: kb-ollama
    ports: ["11434:11434"]
    volumes: [ollama_data:/root/.ollama]
    networks: [kb]
  # volumes: ollama_data:
  ```
  Disabled by default via profile or env.
- `docker/php/Dockerfile` add `tesseract-ocr`, `poppler-utils`, `libpng-dev`.
- `.env` additions: `LLM_DRIVER=openrouter`, `OPENROUTER_API_KEY=`, `OPENROUTER_BASE_URL=https://openrouter.ai/api/v1`, `OPENROUTER_CHAT_MODEL=openai/gpt-4o-mini` (or `anthropic/claude-3.5-sonnet`, `google/gemini-1.5-flash`), `OPENROUTER_EMBEDDING_MODEL=nvidia/nemotron-3-embed-1b:free`, `EMBEDDING_DIM=2048`, `OPENROUTER_APP_URL=http://localhost`, `OPENROUTER_APP_TITLE=AI-KnowledgeBase`, `QDRANT_HOST=qdrant`, `QDRANT_COLLECTION=knowledge_base`, `QUEUE_CONNECTION=redis`, `REDIS_HOST=redis`.
- Run `docker compose exec app composer require openai-php/laravel smalot/pdfparser phpoffice/phpword` (OpenRouter uses OpenAI-compatible client, no extra SDK) + `docker compose exec app php artisan migrate`.

## 10. Delivery Phases (Compressed 2 weeks for Prototype)
- **Phase 0 (Day 1-2) Scaffold:** migrations, models `Conversation/Message/Document/DocumentChunk/UserQuota`, policies, `LLMProviderInterface` + `OpenRouterProvider` stub (Guzzle with `https://openrouter.ai/api/v1` + headers `HTTP-Referer`/`X-Title`), `QdrantService` (Guzzle `http://qdrant:6333`), routes skeleton, frontend shell `Chat.jsx` + `ChatSidebar`.
- **Phase 1 (Day 3-6) Core Chat:** streaming `POST /messages` SSE, pagination `GET /messages?cursor`, 90d prune command, JSON export, Redis throttle/quotas, `ChatMessage` markdown. Verify `php artisan test --filter=ConversationTest`.
- **Phase 2 (Day 7-11) RAG + Moderation:** `DocumentController@store` + `ParseChunkEmbedJob` (PDF/DOCX/image OCR), Qdrant upsert `awaiting_review`, admin approve/reject UI, retrieval `top_k=5 threshold 0.72` injected into prompt with citations.
- **Phase 3 (Day 12-14) Hardening:** search conversations, quota badges, token usage dashboard, summarization job, `react-virtuoso` virtualization, tests `ConversationRAGTest`, lint `vendor/bin/pint` + `npm run lint`, `k6` 10->25->50 concurrent test.

## 11. Tests & Verification
- `docker compose exec app php artisan test --filter=ConversationTest`
- `docker compose exec app vendor/bin/pint`
- `docker compose exec frontend npm run lint`
- Manual: upload 10MB PDF -> admin approve -> query -> verify citation -> export JSON -> dry-run prune.

## 12. Risks & Mitigations
- Global KB pollution -> staged `awaiting_review` + sha256 dedup + admin gate (reputation auto-approve after 3 approvals).
- Image OCR latency -> queue + `processing` polling UI.
- Embedding dimension lock -> store `embedding_dim` in `documents` meta, provide `ReEmbedDocumentsJob` for hybrid switch.
- Cost spike -> daily quotas + pre-call token estimate + 429 hard cap.

## 13. Open Decisions for Build Kickoff
- Confirm staged review (vs immediate global write) — recommended.
- Confirm quotas: 10 docs/30d + 30 prompts/day + 50 msgs/convo.
