#!/bin/bash

LOCK_FILE=/www/storage/installed.lock

if [ ! -f "$LOCK_FILE" ]; then
  echo "🚀 Running XBoard installation..."
  INSTALL_CMD="php artisan xboard:install --admin=${ADMIN_ACCOUNT:-admin@demo.com}"

  [ "$ENABLE_SQLITE" = "true" ] && INSTALL_CMD="$INSTALL_CMD --sqlite"
  [ "$ENABLE_REDIS" = "true" ] && INSTALL_CMD="$INSTALL_CMD --redis"

  eval $INSTALL_CMD

  touch "$LOCK_FILE"
else
  echo "✅ Already installed. Skipping install."
fi

php artisan octane:start --server=swoole --host=0.0.0.0 --port=7001
