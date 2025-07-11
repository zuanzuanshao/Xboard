#!/bin/sh

LOCK_FILE=/www/storage/installed.lock

# Wait for Redis to be ready
echo "⏳ Waiting for Redis..."
while ! redis-cli -s /data/redis.sock ping 2>/dev/null; do
  sleep 2
done
echo "✅ Redis is ready!"

if [ ! -f "$LOCK_FILE" ]; then
  echo "🚀 Running XBoard installation..."
  ENABLE_SQLITE=true ENABLE_REDIS=true ADMIN_ACCOUNT=admin@demo.com php artisan xboard:install --admin=admin@demo.com
  touch "$LOCK_FILE"
else
  echo "✅ Already installed. Skipping install."
fi

php artisan octane:start --server=swoole --host=0.0.0.0 --port=7001
