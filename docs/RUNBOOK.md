# Runbook — AI Knowledge Base (Production)

Companion to `project-tracker.md` Phase 4. Dev setup lives in `README.md` /
`CLAUDE.md`; this file is for operating the production stack.

## Topology

```
browser ──:80──> frontend (nginx, static SPA + /api proxy)
                     └──> nginx (Laravel origin, private)
                              └──> app (php-fpm) ──> mysql, redis, qdrant
worker  (supervisor: queue:work embed,chat,default --tries=3)
scheduler (schedule:work: conversations:prune 02:00, documents:prune-files 03:00)
clamav  (clamd socket shared with app/worker)
```

Only `frontend` publishes a host port in production. MySQL, Redis, Qdrant,
Laravel and ClamAV are private to the `kb` network; Redis and Qdrant require
credentials.

## First deploy

```bash
git clone <repo> && cd ai-knowledge-base
cp .env.example .env                                  # infra secrets (MySQL/Redis/Qdrant/HTTP_PORT)
cp backend/laravel/.env.production.example backend/laravel/.env
# edit both: APP_KEY (php artisan key:generate), APP_URL, real secrets, API keys
docker compose -f docker-compose.prod.yml up -d --build
docker compose -f docker-compose.prod.yml exec app php artisan migrate --force
docker compose -f docker-compose.prod.yml exec app php artisan db:seed   # optional admin user
```

`MYSQL_*` in the root `.env` must match `DB_*` in `backend/laravel/.env`;
`REDIS_PASSWORD` and `QDRANT_API_KEY` must be identical in both files.

### Database / documents persistence

Named volumes: `mysql_data`, `redis_data`, `qdrant_data`, `documents_data`
(`storage/app/private/documents`), `logs_data`, `clamav_data`. Never
`docker compose down -v` against production.

## Environment matrix

| Variable | Where | Notes |
|---|---|---|
| `APP_ENV`, `APP_DEBUG=false`, `APP_KEY`, `APP_URL` | backend `.env` | `APP_KEY` required |
| `DB_*` | backend `.env` | mirror root `MYSQL_*` |
| `REDIS_HOST=redis`, `REDIS_PASSWORD` | backend `.env` | must match root `.env` |
| `QDRANT_HOST=qdrant`, `QDRANT_API_KEY` | backend `.env` | key must match root `.env` |
| `OPENROUTER_API_KEY`, `OPENROUTER_CHAT_MODEL`, `OPENROUTER_EMBEDDING_MODEL`, `EMBEDDING_DIM` | backend `.env` | chat/embedding model + vector size |
| `LLM_MAX_RETRIES`, `LLM_RETRY_BACKOFF_MS`, `LLM_TIMEOUT`, `LLM_FALLBACK_MODELS`, `LLM_CIRCUIT_THRESHOLD`, `LLM_CIRCUIT_TTL` | backend `.env` | provider fallback/circuit breaker |
| `CLAMAV_ENABLED=true`, `CLAMAV_BINARY=clamscan` | backend `.env` | requires the `clamav` service + shared signature DB |
| `LOG_DAILY_DAYS` | backend `.env` | daily rotation for app + llm channels |
| `TRUSTED_PROXIES` | backend `.env` | LB CIDR or `*` when nginx is the edge |
| `HTTP_PORT` | root `.env` | host port for the SPA |
| `MYSQL_*`, `REDIS_PASSWORD`, `QDRANT_API_KEY` | root `.env` | compose interpolation only |

Use a paid OpenRouter model in production: free-tier prompts are used for
training.

## Health & smoke checks

```bash
curl -fsS http://localhost/api/health | jq          # db/cache/qdrant/queue/failed_jobs
docker compose -f docker-compose.prod.yml ps        # all services healthy
docker compose -f docker-compose.prod.yml logs -f worker scheduler
```

`/api/health` returns 200 when DB + cache are reachable and 503 otherwise;
`qdrant`/`queue` report `degraded` without failing the probe.

## Backups & restore

```bash
COMPOSE_FILE=docker-compose.prod.yml ./scripts/backup.sh
COMPOSE_FILE=docker-compose.prod.yml ./scripts/restore.sh backups/<timestamp>
```

- Backups contain MySQL dump, a Qdrant `knowledge_base` collection snapshot and
  `storage/app/private/documents`.
- Run nightly (host cron): `17 3 * * * cd /srv/kb && COMPOSE_FILE=docker-compose.prod.yml ./scripts/backup.sh`.
- Restore is destructive and asks for confirmation. Stop `worker` first if jobs
  are in flight, then `up -d` afterwards.
- Rehearse restore quarterly on a non-production copy.

## TLS / HSTS

Default: terminate TLS at a load balancer and proxy to `HTTP_PORT` (80). Set
`TRUSTED_PROXIES` to the LB CIDR so rate limiting sees client IPs and generated
URLs use HTTPS.

Direct TLS on nginx: copy `docker/nginx/tls.conf.example` to
`docker/nginx/tls.conf`, install certs in `docker/certs/`, and uncomment the
`tls.conf` + `443` lines in `docker-compose.prod.yml`. HSTS
(`max-age=31536000; includeSubDomains`) is sent by the frontend nginx.

## Queue & scheduler

- `worker` runs supervisor with
  `queue:work --queue=embed,chat,default --tries=3 --timeout=300`; restart with
  `docker compose -f docker-compose.prod.yml restart worker`.
- Failed jobs: `docker compose ... exec app php artisan queue:failed` /
  `queue:retry all`. `/api/health` reports the failed-job count.
- `scheduler` runs `schedule:work`; verify with `php artisan schedule:list`.
- Scaling: run additional `worker` replicas (`docker compose ... up -d --scale worker=N`)
  without touching app/fpm.

## Common operations

| Task | Command |
|---|---|
| Apply migrations | `docker compose -f docker-compose.prod.yml exec app php artisan migrate --force` |
| Clear caches | `... exec app php artisan optimize:clear` |
| Rebuild app image | `... up -d --build app worker scheduler` |
| Rebuild SPA | `... up -d --build frontend` |
| Rotate logs | automatic (daily); files in `logs_data` |
| Prune old uploads (dry run) | `... exec app php artisan documents:prune-files --days=30 --dry-run` |
| Prune conversations | `... exec app php artisan conversations:prune --days=90 --dry-run` |

## Re-embed after an embedding-model change

1. Set a new `QDRANT_COLLECTION` (e.g. `knowledge_base_v2`) plus the new
   `OPENROUTER_EMBEDDING_MODEL` / `EMBEDDING_DIM` in `backend/laravel/.env`.
2. `... exec app php artisan migrate --force` (no schema change needed).
3. Approve/re-upload documents or run `ReEmbedDocumentsJob` (requires a fresh
   collection because vector dimensions are locked per collection).
4. Verify `/api/health`, then point retrieval at the new collection. Keep the old
   collection until the new one is verified.

## Troubleshooting

- **`service app is not running`** — start the stack first; tests run inside the
  container.
- **Host port 6380 already allocated** — another project (e.g. `ecom-cache`)
  owns it. Start only what you need `docker compose up -d --no-deps app`.
- **Empty/truncated SSE replies + `Permission denied` in llm.log** — the log
  channel is fail-open (`ignore_exceptions`), but ensure
  `storage/logs` is writable by `www-data` (tests run as root and can mask this).
- **`Service temporarily overloaded`** — OpenRouter/Nvidia free-tier upstream;
  the provider retries, walks `LLM_FALLBACK_MODELS`, and trips the circuit
  breaker after `LLM_CIRCUIT_THRESHOLD` failures. Use a paid model for prod.
- **Uploads stuck `pending`** — the worker is down: `... ps worker`,
  `... logs worker`.
- **429s for many users behind a proxy** — set `TRUSTED_PROXIES` correctly.
- **Virus scan failures** — if `CLAMAV_ENABLED=true` and the scanner is
  unreachable, jobs fail closed (upload marked `failed`). Check `kb-clamav`
  health and the shared `clamav_data` signature volume (mounted read-only
  at `/var/lib/clamav`), or set `CLAMAV_ENABLED=false` until it is ready.
