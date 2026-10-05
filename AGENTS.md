# AGENTS.md

Docker full-stack RAG app: Laravel API (`backend/laravel`, Sanctum) + React Vite
(`frontend/react-dashboard`) + MySQL + Redis + Qdrant. API `http://localhost:82/api`,
frontend dev `:5173`, Qdrant `:6333`, MySQL host port `3307`, Redis `6380`.

Loaded alongside this file via `opencode.json`: `agent-optimal-usage.md`
(token/sub-agent discipline), `CLAUDE.md` (stack reference), `plans/user-dashboard-rag.md`
(architecture/API contract), `project-tracker.md` (phase checkboxes). Don't duplicate them.

## Commands (run from repo root; containers must be up)

- `docker compose up -d` first — `docker compose exec app ...` fails with
  "service app is not running" otherwise.
- Backend: `docker compose exec app php artisan test --filter=ConversationTest`
  (single test), `docker compose exec app vendor/bin/pint <touched-files>` —
  scope pint to files you changed; pre-existing style issues elsewhere are out of scope.
- Frontend: `docker compose exec frontend npm run lint` (axios is REST-only;
  SSE streaming uses native `fetch` + ReadableStream, see `src/components/ChatInput.jsx`),
  `docker compose exec frontend npm run test` (vitest + jsdom; explicit
  `afterEach(cleanup)` — no globals mode, so RTL can't auto-cleanup).
- Migrations: `docker compose exec app php artisan migrate`; verify with
  `php artisan route:list --path=api/...`.
- Queue is `redis`: uploads stall in `pending` unless a worker runs —
  `docker compose exec -d app php artisan queue:work --queue=embed,chat,default --tries=3`.
  After editing `docker/php/Dockerfile`, rebuild with `docker compose up -d --build app`.
- Frontend REST uses `fetch` + Bearer token from `localStorage` (axios instance has
  no auth attached); new API components must follow `ChatContext.jsx`/`DocumentUploadZone.jsx`.

## Test env ≠ dev env (`backend/laravel/phpunit.xml` forces sqlite :memory:, array cache, sync queue)

- MySQL-only DDL must be driver-guarded — see `000002_create_messages_table.php` FULLTEXT pattern.
- `date` casts serialize to `Y-m-d H:i:s` on write; plain `where('date','Y-m-d')`
  misses on SQLite — use `whereDate` (see `UserQuota::forToday()`).
- Sanctum guard memoizes the user across requests in one test method — call
  `$this->app['auth']->forgetGuards()` before requests with different Bearer tokens.
- `StreamedResponse` bodies are invisible to `assertSee` — capture via
  `sendContent()` inside a swallowing `ob_start` callback (see `ConversationTest.php`).
- `phpunit.xml` pins `OPENROUTER_API_KEY` empty so tests always take the LLM stub —
  never rely on a developer's live key in tests (swap fakes + `Http::fake()` instead,
  see `DocumentRAGTest.php`).

## Repo conventions (easy to miss)

- Commit only when explicitly asked; never push unless asked. Style: `feat(phase-N): ...`.
- Tracker is mirrored: after checking `[x]` in `project-tracker.md`, run
  `cp project-tracker.md plans/project-tracker.md`.
- `QDRANT_HOST` may be bare hostname (`qdrant`) or full URL — `AppServiceProvider`
  normalizes it; reuse that for new service clients.
- `OpenRouterProvider` yields a deterministic stub when `OPENROUTER_API_KEY` is empty —
  tests depend on it; don't "fix" the stub into a live call.
- AuthZ: base `Controller` already uses `AuthorizesRequests`; policies in
  `app/Policies/` auto-resolve (`{Model}Policy`). Always `authorize()` ownership on
  `Conversation`/`Document` routes.
- Deps: OpenRouter via plain HTTP (no SDK); `smalot/pdfparser` + `phpoffice/phpword`
  for document parsing. RAG migrations use prefix `2026_09_19_00000N`.
