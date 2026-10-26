# syntax=docker/dockerfile:1
#
# 本番用イメージ（Railway で使う）。3段階のマルチステージビルドで、
# ビルドにしか使わない道具（Node、Composer、開発用パッケージ）を最終イメージに残さない。

# 1. frontend のビルド
FROM node:24-slim AS frontend
WORKDIR /app/frontend
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci
COPY frontend/ ./
RUN npm run build

# 2. PHP の実行環境（FrankenPHP = PHP と Web サーバー Caddy が一体になったもの）
FROM dunglas/frankenphp:1-php8.5 AS base
RUN install-php-extensions pdo_mysql
WORKDIR /app

# 3. Composer で本番用パッケージだけを入れる
FROM base AS vendor
RUN install-php-extensions zip
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
# composer.json が変わらない限り、この層はキャッシュが使われる
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader
COPY backend/ ./
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

# 4. 実行用イメージ
FROM base AS app
ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr

COPY --from=vendor /app /app
# ビルドした SPA：JS・CSS などは public/ から配信し、index.html は Laravel から返す（5-2）
COPY --from=frontend /app/frontend/dist/ /app/public/
RUN mkdir -p resources/spa && mv public/index.html resources/spa/index.html

COPY docker/Caddyfile /etc/caddy/Caddyfile
COPY --chmod=755 docker/start.sh /usr/local/bin/start

# root ではなく専用ユーザーで動かす（乗っ取られたときの被害を小さくするため）
RUN useradd --create-home app \
    && chown -R app:app storage bootstrap/cache /data/caddy /config/caddy
USER app

EXPOSE 8080
CMD ["start"]
