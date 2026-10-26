#!/bin/sh
# コンテナ起動時の処理。
# 環境変数（DB の接続先など）は実行時に決まるため、設定のキャッシュはビルド時ではなくここで作る。
set -e

php artisan config:cache
php artisan route:cache
php artisan view:cache

php artisan migrate --force
php artisan app:seed-if-empty

exec frankenphp run --config /etc/caddy/Caddyfile
