#!/usr/bin/env bash
# Restore a backup produced by scripts/backup.sh.
#
#   COMPOSE_FILE=docker-compose.prod.yml ./scripts/restore.sh backups/20260101-020000
#
# The app is put into maintenance mode; MySQL and documents are overwritten and
# the Qdrant collection snapshot is uploaded. Stop workers first in production.
set -euo pipefail

cd "$(dirname "$0")/.."

COMPOSE_FILE="${COMPOSE_FILE:-docker-compose.yml}"
SRC="${1:?usage: restore.sh <backup-dir>}"
COLLECTION="${QDRANT_COLLECTION:-knowledge_base}"

for f in mysql.sql.gz qdrant.snapshot documents.tar.gz; do
    test -f "$SRC/$f" || { echo "missing $SRC/$f" >&2; exit 1; }
done

read -r -p "This overwrites MySQL, Qdrant and uploaded documents. Continue? [y/N] " ok
[ "$ok" = "y" ] || exit 1

echo "==> Maintenance mode"
docker compose -f "$COMPOSE_FILE" exec -T app php artisan down || true

echo "==> MySQL restore"
gunzip -c "$SRC/mysql.sql.gz" | docker compose -f "$COMPOSE_FILE" exec -T mysql sh -c \
    'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"'

NETWORK="$(docker compose -f "$COMPOSE_FILE" ps -q qdrant | head -n1 \
    | xargs -r docker inspect -f '{{range $k,$v := .NetworkSettings.Networks}}{{$k}}{{end}}')"
test -n "$NETWORK" || { echo "qdrant is not running" >&2; exit 1; }
CURL_IMAGE="${CURL_IMAGE:-curlimages/curl:8.10.1}"

echo "==> Qdrant snapshot upload ($COLLECTION)"
ABS_SRC="$(cd "$SRC" && pwd)"
docker run --rm --network "$NETWORK" \
    -e QDRANT_API_KEY="${QDRANT_API_KEY:-}" -e COLLECTION="$COLLECTION" \
    -v "$ABS_SRC:/backup:ro" \
    --entrypoint sh "$CURL_IMAGE" -c '
        set -e
        auth=""
        if [ -n "$QDRANT_API_KEY" ]; then auth="api-key: $QDRANT_API_KEY"; fi
        curl -fsS -X POST -H "$auth" \
            -F "snapshot=@/backup/qdrant.snapshot" \
            "http://qdrant:6333/collections/$COLLECTION/snapshots/upload?priority=snapshot"
    '

echo "==> Documents restore"
docker compose -f "$COMPOSE_FILE" exec -T app sh -c \
    'rm -rf /var/www/html/laravel/storage/app/private/documents && mkdir -p /var/www/html/laravel/storage/app/private'
docker compose -f "$COMPOSE_FILE" exec -T app \
    tar -xzf - -C /var/www/html/laravel/storage/app/private < "$SRC/documents.tar.gz"

echo "==> Leaving maintenance mode"
docker compose -f "$COMPOSE_FILE" exec -T app php artisan up

echo "Restore complete from $SRC"
