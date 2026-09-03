# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

---

## Overview

This is a **Docker‑based full‑stack knowledge‑base application** consisting of:

- **Laravel API** (`backend/laravel`) – PHP backend providing REST endpoints, authentication via Sanctum, and database/MySQL access.
- **React front‑end** (`frontend/react-dashboard`) – Vite‑dev server with HMR, Tailwind CSS, React Router, and Axios calls to the Laravel API.
- **Docker** (`docker-compose.yml`) – orchestrates the following services:
  - `app` (PHP‑FPM Laravel container)
  - `nginx` (reverse‑proxy on port 82 → http://localhost)
  - `frontend` (Node/Vite on port 5173)
  - `mysql` (port 3307)
  - `redis` (port 6380)
  - `qdrant` (vector store dashboard on port 6333)

The default entry point is `http://localhost` (Nginx serving the React app; API calls go to `/api/...`).

---

## Common Commands

### From the repository root

| Goal | Command | Description |
|------|---------|-------------|
| **Start all services** | `docker compose up -d --build` | Build and start containers (as in `README.md`). |
| **Stop all services** | `docker compose down` | Stop and remove containers, networks, volumes. |
| **View service status** | `docker compose ps` | Show running/stopped containers. |
| **Laravel artisan test** | `docker compose exec app php artisan test` | Run the Laravel test suite (uses PHPUnit under the hood). |
| **Run a single Laravel test** | `docker compose exec app php artisan test --filter=YourTestName` | Run one test class/method by name. |
| **Laravel pint (code style)** | `docker compose exec app vendor/bin/pint` | Run Laravel’s code‑formatter (PHP‑CS‑Fixer style). |
| **Composer install / update** | `docker compose exec app composer install` / `composer update` | Install or update PHP dependencies. |
| **Frontend dev server** | `docker compose exec frontend sh -c "npm run dev -- --host 0.0.0.0"` | Start Vite dev server (HMR, accessible on http://localhost:5173). |
| **Frontend build** | `docker compose exec frontend npm run build` | Produce production assets in `frontend/react-dashboard/dist/`. |
| **Frontend lint** | `docker compose exec frontend npm run lint` | Run ESLint on the source. |
| **PHPUnit directly** | `docker compose exec app vendor/bin/phpunit` | Run PHPUnit without artisan wrapper. |
| **Run a single PHPUnit test** | `docker compose exec app vendor/bin/phpunit --filter=YourTest` | Run one test by name/pattern. |
| **Migrate DB** | `docker compose exec app php artisan migrate` | Run pending migrations. |
| **Seed DB** | `docker compose exec app php artisan db:seed` | Run seeders (see `database/seeders/`). |
| **Redis CLI** | `docker compose exec redis redis-cli` | Interact with Redis. |
| **Qdrant dashboard** | Open http://localhost:6333/dashboard in a browser. |

### From the backend directory (if you `cd` there)

- `composer test` → same as `docker compose exec app php artisan test`.
- `composer pint` → run pint formatter.
- `composer lint` → not defined; use `vendor/bin/pint`.

### From the frontend directory (if you `cd` there)

- `npm run dev` → Vite dev server.
- `npm run build` → production build.
- `npm run lint` → ESLint.

---

## Architecture & Structure

### Backend (Laravel)

- **Entry points**: `public/index.php` (HTTP) and `artisan` (CLI).
- **Routing**: 
  - `routes/api.php` – API routes (currently `/register`, `/login`, `/logout`, `/user` under `auth:sanctum` middleware).
  - `routes/web.php` – traditional web routes (not detailed here).
- **Authentication**: Sanctum token‑based; login/register handled by `AuthController` (`app/Http/Controllers/AuthController.php`).
- **Models**: `app/Models/User.php` extends Laravel's `Authenticatable`; a migration (`2026_09_02_175923_add_role_to_users_table.php`) adds a `role` column.
- **Controllers**: `AuthController` handles registration, login, logout, and user retrieval.
- **Database**: MySQL 8.4 (`docker-compose.yml` service `mysql`), migrations live in `database/migrations/`.
- **Seeders**: `database/seeders/DatabaseSeeder.php` (currently minimal).
- **Testing**: PHPUnit (v12) with test classes under `tests/Feature/` and `tests/Unit/`. Run via `php artisan test` or `vendor/bin/phpunit`.
- **Code style**: Laravel Pint (`vendor/bin/pint`) for PHP formatting; ESLint for JS/TS.
- **Key config files**: `config/sanctum.php`, `.env` (database creds, app key), `composer.json` (dev deps include `laravel/pint`, `phpunit/phpunit`, `mockery/mockery`).

### Frontend (React + Vite)

- **Entry**: `frontend/react-dashboard/src/main.jsx` (or `App.jsx` if you look at the repo) – the root React component.
- **Routing**: `react-router-dom`; pages include `Login.jsx`, `App.jsx`, etc.
- **Styling**: Tailwind CSS v4 (`tailwindcss` + `@tailwindcss/vite`).
- **Assets**: `src/` contains components, pages, and context; `public/` holds static assets.
- **API client**: `axios` instance configured to talk to `http://localhost/api/` (via Vite proxy or relative paths in production).
- **Testing**: Currently only ESLint (`npm run lint`). No unit test framework is installed; you can add `vitest` or `jest` if needed.
- **Build**: `vite build` outputs to `dist/`. The Dockerfile for the `frontend` service runs `npm install` then `npm run build`.
- **Key config files**: `vite.config.js`, `eslint.config.js`, `tailwind.config.js` (not shown but auto‑generated by Tailwind v4).

### Data Flow

1. User interacts with the React UI → API calls (`axios`) → Laravel routes.
2. Sanctum guards `/api/*` routes; unauthenticated requests get a 401.
3. Laravel queries MySQL (tables: `users`, etc.) via Eloquent.
4. On routes that need vectors (e.g., semantic search), the app may query **Qdrant** (container `qdrant`) through its API; Qdrant is reachable at `http://localhost:6333`.
5. Redis is used for caching, rate limiting, or session storage (Sanctum can use Redis as driver).

---

## Development Tips

- **Hot‑reload**: `docker compose up -d --build` + `docker compose exec frontend sh -c "npm run dev -- --host 0.0.0.0"` – changes in `frontend/react-dashboard/src/` auto‑refresh in the browser at `http://localhost:5173`. Changes in Laravel code require a restart of the `app` container or `php artisan view:clear` / `cache:clear`.
- **Debugging API**: Use `http://localhost/api/...` or `http://localhost:82/api/...` (Nginx forwards to the app container). Laravel’s `debugbar` or `telescope` can be installed if you need request‑level insight.
- **Running tests in isolation**: `docker compose exec app php artisan test --filter=UserLoginTest` runs just that test class.
- **Linting on save**: Not configured globally; you can run `npm run lint` (frontend) or `vendor/bin/pint` (backend) manually.
- **Docker logs**: `docker compose logs -f app` / `frontend` to see real‑time output.

---

## Reference Files

- `README.md` – high‑level setup (docker compose, services, verification steps).
- `docker-compose.yml` – service definitions, networks, volumes.
- `backend/laravel/composer.json` – PHP dependencies and test/lint scripts.
- `frontend/react-dashboard/package.json` – Node scripts and dependencies.
- `backend/laravel/routes/api.php` – current API surface.
- `backend/laravel/app/Models/User.php` – user model with role column.
- `backend/laravel/database/migrations/` – migration history.
- `backend/laravel/tests/` – PHPUnit test directory.

---

## Next Steps for a New Instance

1. **Bring up the environment**: `docker compose up -d --build`.
2. **Install deps**: `docker compose exec app composer install` and `docker compose exec frontend sh -c "npm install"`.
3. **Generate app key**: `docker compose exec app php artisan key:generate`.
4. **Run migrations**: `docker compose exec app php artisan migrate`.
5. **(Optional) Seed data**: `docker compose exec app php artisan db:seed`.
6. **Start the dev servers** as shown in the “Common Commands” table.
7. **Run the test suite**: `docker compose exec app php artisan test` to verify everything passes.

--- 

*This CLAUDE.md was generated from the repository analysis on 2026‑09‑03.*