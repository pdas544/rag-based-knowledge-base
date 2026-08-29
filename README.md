# AI Knowledge Base — Docker Setup

## Services
- Laravel PHP-FPM: internal service `app`
- Nginx: http://localhost
- React/Vite development server: http://localhost:5173
- MySQL: localhost:3306
- Redis: localhost:6379
- Qdrant dashboard/API: http://localhost:6333/dashboard

## Start
```bash
cp .env.example .env
# Place/create Laravel in ./backend and React/Vite in ./frontend
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
```

## Verify
```bash
docker compose ps
curl http://localhost/up
curl http://localhost:6333/healthz
```

Use service names (`mysql`, `redis`, `qdrant`) from containers, not `localhost`.
This is a development baseline; add authentication, TLS, backups, secrets management, and resource limits before production.
