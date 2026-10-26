#!/bin/sh
set -eu
mkdir -p /app/var /app/bootstrap/cache /backups \
  /app/storage/app/operations /app/storage/app/private /app/storage/app/public \
  /app/storage/framework/cache/data /app/storage/framework/sessions \
  /app/storage/framework/views /app/storage/logs
if [ ! -f /app/var/database.sqlite ]; then
  touch /app/var/database.sqlite
fi
chown -R "${COIN_BOT_UID:-1000}:${COIN_BOT_GID:-1000}" /app/var /app/storage /app/bootstrap/cache /backups
chmod 0700 /app/var /app/storage/app/operations /app/storage/app/private
chmod 0600 /app/var/database.sqlite
