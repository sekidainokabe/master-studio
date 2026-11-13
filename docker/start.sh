#!/bin/sh
# コンテナ起動時の処理。
# 環境変数（DB の接続先など）は実行時に決まるため、設定のキャッシュはビルド時ではなくここで作る。
set -e

php artisan config:cache
php artisan route:cache
# view:cache は行わない。画面は React で作っており Blade テンプレートを持たないうえ、
# 空の resources/views は Git に入らないため、本番では「フォルダがない」エラーで起動が止まる

php artisan migrate --force
php artisan app:seed-if-empty
# デモ用データは、入れられなくてもアプリの起動は止めない（本番のデータが変わっていて、使う行がない場合など）
php artisan app:seed-demo || echo "デモ用データを入れられませんでした（起動は続けます）"

exec frankenphp run --config /etc/caddy/Caddyfile
