#!/usr/bin/env bash
# Back up MySQL, the Qdrant knowledge_base collection and uploaded documents.
#
#   COMPOSE_FILE=docker-compose.prod.yml ./scripts/backup.sh
#
# Output: backups/<timestamp>/{mysql.sql.gz,qdrant.snapshot,documents.tar.gz}
# Old backup directories are pruned after BACKUP_RETENTION_DAYS (default 14).
set -euo pipefail

cd "$(dirname "$0")/.."

COMPOSE_FILE="${COMPOSE_FILE:-docker-compose.yml}"
BACKUP_DIR="${BACKUP_DIR:-./backups}"
RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-14}"
STAMP="$(date +%Y%m%d-%H%M%S)"
DEST="$BACKUP_DIR/$STAMP"
COLLECTION="${QDRANT_COLLECTION:-knowledge_base}"

mkdir -p "$DEST"

echo "==> MySQL dump"
docker compose -f "$COMPOSE_FILE" exec -T mysql sh -c \
    'exec mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --routines --triggers "$MYSQL_DATABASE"' \
    | gzip > "$DEST/mysql.sql.gz"

NETWORK="$(docker compose -f "$COMPOSE_FILE" ps -q qdrant | head -n1 \
    | xargs -r docker inspect -f '{{range $k,$v := .NetworkSettings.Networks}}{{$k}}{{end}}')"
test -n "$NETWORK" || { echo "qdrant is not running" >&2; exit 1; }
CURL_IMAGE="${CURL_IMAGE:-curlimages/curl:8.10.1}"

echo "==> Qdrant collection snapshot ($COLLECTION)"
docker run --rm --network "$NETWORK" -v "$DEST:/backup" \
    -e QDRANT_API_KEY="${QDRANT_API_KEY:-}" -e COLLECTION="$COLLECTION" \
    --entrypoint sh "$CURL_IMAGE" -c '
        set -e
        auth=""
        if [ -n "$QDRANT_API_KEY" ]; then auth="api-key: $QDRANT_API_KEY"; fi
        name=$(curl -fsS -X POST -H "$auth" \
            "http://qdrant:6333/collections/$COLLECTION/snapshots" \
            | sed -n "s/.*\"name\":\"\([^\"]*\)\".*/\1/p")
        test -n "$name"
        curl -fsS -H "$auth" \
            "http://qdrant:6333/collections/$COLLECTION/snapshots/$name" \
            > /backup/qdrant.snapshot
    '

echo "==> Uploaded documents"
docker compose -f "$COMPOSE_FILE" exec -T app \
    tar -czf - -C /var/www/html/laravel/storage/app/private documents \
    > "$DEST/documents.tar.gz"

echo "==> Prune backups older than ${RETENTION_DAYS}d"
find "$BACKUP_DIR" -mindepth 1 -maxdepth 1 -type d -mtime "+$RETENTION_DAYS" -print -exec rm -rf {} +

echo "Backup complete: $DEST"
