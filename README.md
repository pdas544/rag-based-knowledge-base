# AI Knowledge Base — ChatGPT-style Chat + RAG over a Global Knowledge Base

Ask questions in a chat UI and get answers grounded in admin-approved documents.
Users upload documents (PDF/DOCX/images) into a staged pipeline; admins review
them into the global knowledge base; chat retrieves citations at answer time.

Stack: Laravel API (Sanctum) + React/Vite + MySQL + Redis (queue) + Qdrant (vectors),
all via Docker. LLM calls go through the **OpenRouter** gateway (configurable model).

## Services & URLs

| Service | URL |
|---|---|
| App (via Nginx) | http://localhost:82 |
| API | http://localhost:82/api |
| Frontend dev (HMR) | http://localhost:5173 |
| Qdrant dashboard | http://localhost:6333/dashboard |
| MySQL (host) | localhost:3307 (user/password/db: `knowledgebase`/`knowledgebase_secret`/`knowledgebase`) |
| Redis (host) | localhost:6380 |

Inside containers use hostnames `mysql`, `redis`, `qdrant` — not `localhost`.

## Setup

```bash
# 1. Start everything
docker compose up -d --build

# 2. Backend: install deps, key, migrate
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate

# 3. Start the queue worker (REQUIRED — uploads stall in "pending" without it)
docker compose exec -d app php artisan queue:work --queue=embed,chat,default --tries=3

# 4. Frontend deps (only needed first time / after package changes)
docker compose exec frontend sh -c "npm install"
```

Open http://localhost:5173 → Register → you land in `/chat`.

## Configuration (`backend/laravel/.env`)

| Key | Purpose | Default |
|---|---|---|
| `OPENROUTER_API_KEY` | LLM + embeddings via OpenRouter. **Empty = deterministic stub** (chat replies with a canned message, uploads chunk but skip vectors) — fine for UI testing, set a real key for actual RAG | empty |
| `OPENROUTER_CHAT_MODEL` | Chat model id, e.g. `openai/gpt-4o-mini`, `anthropic/claude-3.5-sonnet` | `openai/gpt-4o-mini` |
| `OPENROUTER_EMBEDDING_MODEL` / `EMBEDDING_DIM` | Embedding model + Qdrant vector size (must match; free option) | `nvidia/nemotron-3-embed-1b:free` / `2048` |
| `OPENROUTER_APP_URL` / `OPENROUTER_APP_TITLE` | Required `HTTP-Referer` / `X-Title` headers for OpenRouter | `http://localhost` / `AI-KnowledgeBase` |
| `QDRANT_HOST` / `QDRANT_COLLECTION` | Vector store host (bare hostname OK) + collection | `qdrant` / `knowledge_base` |
| `QUEUE_CONNECTION` | Must be `redis` (with worker running, see Setup step 3) | `redis` |

After changing `docker/php/Dockerfile`: `docker compose up -d --build app`.

## Using the app

**As a user (`/chat`):**
1. **Chat** — new conversation, streaming replies, history in the sidebar.
   Questions with no KB match (vector search, then keyword fallback for
   acronyms/names) get a fixed `Not found in the available knowledge base.`
   reply (no LLM call, no drift).
   Export a conversation: `GET /api/conversations/{id}/export` (JSON).
2. **Upload documents** — drag PDF/DOCX/image/TXT/MD (≤10MB) into the
   "Knowledge docs" zone in the sidebar. Status flows
   `pending → processing → awaiting_review → ready` (auto-polls).
   Same file content twice (even renamed) → `409 Duplicate`.
3. **Limits** — 30 prompts + 20k tokens/day, 10 uploads/30 days, 100 msgs/conversation.

**As an admin (`/admin`, needs `role=admin`):**
1. Make a user admin: `docker compose exec app php artisan tinker --execute='App\Models\User::where("email","you@x.com")->update(["role" => "admin"]);'`
2. Review queue shows `awaiting_review` docs → **Approve** (goes live in global
   KB: Qdrant payload flips to `ready`) or **Reject** (vectors deleted).
3. Stats cards: total docs, vector chunks, pending reviews, processing queue.

90-day retention: `docker compose exec app php artisan conversations:prune --days=90 --dry-run`
(remove `--dry-run` to delete; also scheduled daily at 02:00).

## Verify & troubleshoot

```bash
docker compose ps
curl http://localhost:6333/healthz
docker compose exec app php artisan test          # full suite (sqlite, no services needed)
docker compose exec app php artisan test --filter=DocumentRAGTest
docker compose exec app vendor/bin/pint --test    # backend style
docker compose exec frontend npm run lint         # frontend lint
docker compose logs -f app                        # API logs
```

| Symptom | Fix |
|---|---|
| Upload stuck in `pending` | Start the queue worker (Setup step 3) |
| Chat replies with stub text | Set `OPENROUTER_API_KEY` in `backend/laravel/.env` |
| `service app is not running` | `docker compose up -d` first |
| 401 after register/login | Token expired — log in again; check `Authorization: Bearer` header |

> Development baseline: add TLS, backups, secrets management, rate-limit tuning,
> and resource limits before production. See `plans/user-dashboard-rag.md` for the
> architecture and `project-tracker.md` for build progress.
