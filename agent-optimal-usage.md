# Agent Optimal Usage — Muse Spark 1.2 (Free) for Industry-Grade Projects

> Purpose: Maximize `muse-spark-1.2-contributor-free` (`opencode.ai/zen/v1/responses`) utility within token/rate limits while delivering production quality. Read this before any `Task` or `Edit`.

## 0. Does One Guideline Fit All Projects?

**Both.** Core is universal (70%), project layer overrides (30%).

*   **Universal (this file):** Token hygiene, sub-agent discipline, exploration vs build, file-ops, testing, security, observability. Applies to any Laravel/React/Qdrant/Redis stack.
*   **Project-specific (override):** `AGENTS.md`, `plans/user-dashboard-rag.md`, `project-tracker.md`, `.env` keys (`LLM_DRIVER=openrouter`), domain rules (e.g., `global KB staged review`, `sha256 dedup`, quotas `10 docs/30d`). Project file wins on conflict.
*   **Layering:** `agent-optimal-usage.md` (general) → `CLAUDE.md`/`AGENTS.md` (stack) → `plans/*.md` (feature) → `project-tracker.md` (task). Keep general stable, vary project via last two.

---

## 1. Token Hygiene (Muse Spark 1.2 Free: ~1M window, contributor-tier training opt-in)

1.  **Read once, write once:** `Read` target files fully on first pass; avoid re-reading same file in same turn. Pass file paths explicitly to sub-agents instead of broad `Glob`.
2.  **Explore `quick` by default:** `Task(subagent_type=explore, thoroughness=quick)` for file location. Use `medium/very thorough` only for RAG/Qdrant schema audits.
3.  **Cap context:** Keep active context <150K per phase. Close phase, commit, start fresh turn for next phase. Use `project-tracker.md` checkboxes to persist state, not chat history.
4.  **Batched verification:** Run `vendor/bin/pint`, `php artisan test --filter`, `npm run lint` once per phase, not per file. Batch `Bash` calls in one turn where independent.
5.  **No echo:** Don't `cat` large files to output; use `Grep` to extract lines. Truncate logs after 200 lines.

## 2. Sub-Agent Discipline

*   **When to use:** Only for **independent** parallel tracks (e.g., `Phase 2`: `ParseChunkEmbedJob` vs `DocumentUploadZone.jsx` vs `Admin review API`). Never for sequential dependencies (migration → model → policy).
*   **Concurrency limit:** Max **2-3** concurrent. Each sub-agent adds ~25-40% token overhead (duplicate system prompt + reads). Primary does scaffold (Phase 0-1) solo.
*   **Contract:** Sub-agent prompt must include: task slice, exact file paths, return format (e.g., "return 5 bullet findings, no code"). Primary merges, deduplicates.
*   **Anti-pattern:** Launching 4+ `general` agents for same file → waste. Use `explore` for reads, `general` only for writes in parallel lanes.

## 3. Exploration vs Build

1.  `plan` mode: reads only, no `Edit/Write`.
2.  `build` mode: `TodoWrite` first, then `Edit`. Every `Edit` preceded by `Read` of that file (preserve exact indentation per `file_path:line_number` citation).
3.  Verify by execution: `docker compose exec app php artisan test` after each phase, not assumption.

## 4. File Operations

*   Prefer `Edit` over `Write`; keep diff small, preserve surrounding lines.
*   Never create docs (`*.md`) unless requested; reuse `plans/`, `project-tracker.md`.
*   Quote paths with spaces, use `workdir` param not `cd &&`.
*   Use `/tmp/opencode` for scratch outside workspace.

## 5. Industry-Grade Quality Gates

*   **Code style:** `vendor/bin/pint` (backend), `npm run lint` (frontend) before commit.
*   **Tests:** Add `Feature` test per API route (`php artisan test --filter=ConversationTest`), `tests/` mirror `app/` structure.
*   **Security:** Validate `mimes|max:10240`, `sha256` dedup, Sanctum `auth:sanctum`, `role` middleware, `Policy` ownership check, no secrets in repo.
*   **Observability:** Log `model, tokens, latency, sources` per LLM call to `storage/logs/llm.log`; expose metrics in `AdminDashboard`.
*   **Resilience:** Queue `ParseChunkEmbedJob` (retries 3, backoff), idempotent Qdrant upsert by `qdrant_point_id`, softDeletes + prune.
*   **Docs:** Update `project-tracker.md` `[x]` + commit message `feat(scope): ...` + `plans/*.md` if contract changes.

## 6. Project Workflow (This Repo Example)

1.  Read `plans/user-dashboard-rag.md`, `project-tracker.md`, `docker-compose.yml`, `routes/api.php`, `App.jsx`.
2.  `TodoWrite` for current phase sub-modules (e.g., `0.1-0.11`).
3.  Implement slice, mark `[x]` in `project-tracker.md` immediately after verify.
4.  Commit per sub-module, not per phase.

## 7. Muse Spark 1.2 Specifics

*   Strengths: Long-horizon agentic, terminal coding, tool calling — use `Bash` + `Read`/`Edit` chain, not chat.
*   Weakness: Tends to over-read; enforce file path scoping.
*   Free tier: Requests retained 30d, prompts used for training (contributor) — avoid pasting secrets/PII in prompts.
*   Rate: Prefer OpenRouter gateway (`OPENROUTER_*`) to keep model choice config-driven; Spark handles `streamChat` well via SSE.

## 8. Checklist Before Every Turn

- [ ] Is this read necessary or already cached?
- [ ] Can this be 1 `Bash` with `&&` vs 3 turns?
- [ ] Is sub-agent needed or can primary do it cheaper?
- [ ] Will `project-tracker.md` need `[x]` after?

*Keep this file <300 lines. Project overrides belong in `plans/`.*
