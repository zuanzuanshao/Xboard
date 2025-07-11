#!/bin/bash

LOCK_FILE=/www/storage/installed.lock

if [ ! -f "$LOCK_FILE" ]; then
  echo "🚀 Running XBoard installation..."
  ENABLE_SQLITE=true ENABLE_REDIS=true ADMIN_ACCOUNT=admin@demo.com php artisan xboard:install --admin=admin@demo.com
  touch "$LOCK_FILE"
else
  echo "✅ Already installed. Skipping install."
fi

php artisan octane:start --server=swoole --host=0.0.0.0 --port=7001
