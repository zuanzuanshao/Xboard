#!/bin/bash

LOCK_FILE=/www/storage/installed.lock

if [ ! -f "$LOCK_FILE" ]; then
  echo "🔧 Running first-time XBoard setup..."
  php artisan xboard:install --admin="${ADMIN_ACCOUNT:-admin@demo.com}"
  touch "$LOCK_FILE"
else
  echo "✅ XBoard already installed. Skipping install."
fi

php artisan octane:start --server=swoole --host=0.0.0.0 --port=7001
