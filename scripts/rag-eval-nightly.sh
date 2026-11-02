#!/usr/bin/env bash
# Nightly live RAG evaluation against the real knowledge base.
#
#   COMPOSE_FILE=docker-compose.prod.yml ./scripts/rag-eval-nightly.sh
#
# Runs full mode (retrieval + generation + judge), compares the frozen baseline
# and exits non-zero on a gate failure. Artifacts are copied to
# eval-artifacts/<timestamp>/ for CI upload.
set -uo pipefail

cd "$(dirname "$0")/.."

COMPOSE_FILE="${COMPOSE_FILE:-docker-compose.prod.yml}"
DATASET="${RAG_EVAL_DATASET:-eval/datasets/golden.jsonl}"
BASELINE="${RAG_EVAL_BASELINE:-eval/baselines/current.json}"
STAMP="$(date +%Y%m%d-%H%M%S)"
ARTIFACT_DIR="${RAG_EVAL_ARTIFACTS:-./eval-artifacts/$STAMP}"

ARGS=("--mode=full" "--dataset=$DATASET")

if [ -n "${RAG_EVAL_CHAT_MODEL:-}" ]; then
    ARGS+=("--chat-model=$RAG_EVAL_CHAT_MODEL")
fi

if [ -n "${RAG_EVAL_JUDGE_MODEL:-}" ]; then
    ARGS+=("--judge-model=$RAG_EVAL_JUDGE_MODEL")
fi

if [ -f "backend/laravel/$BASELINE" ]; then
    ARGS+=("--baseline=$BASELINE")
else
    echo "No frozen baseline at $BASELINE; freezing the first run with --accept-baseline."
    ARGS+=("--accept-baseline" "--baseline-path=$BASELINE")
fi

if [ -f backend/laravel/eval/calibration/human_labels.jsonl ]; then
    ARGS+=("--calibrate=eval/calibration/human_labels.jsonl")
fi

echo "==> rag:evaluate ${ARGS[*]}"
docker compose -f "$COMPOSE_FILE" exec -T app php artisan rag:evaluate "${ARGS[@]}"
CODE=$?

mkdir -p "$ARTIFACT_DIR"
docker compose -f "$COMPOSE_FILE" cp \
    app:/var/www/html/laravel/storage/app/private/eval/. "$ARTIFACT_DIR" 2>/dev/null || true
echo "Artifacts: $ARTIFACT_DIR (exit $CODE)"

exit "$CODE"
