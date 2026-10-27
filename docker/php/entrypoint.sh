#!/bin/sh
set -e

# Warm production caches once per container start. Route caching requires
# closure-free routes (all API routes use controllers).
if [ "${APP_ENV:-production}" = "production" ]; then
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
fi

exec "$@"
