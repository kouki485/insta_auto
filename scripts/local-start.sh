#!/usr/bin/env bash
# Insta Auto をローカル動作確認モードで一括起動するスクリプト.
#
# 前提:
#   - Docker Desktop が起動している
#   - リポジトリルートで実行する
#
# 動作:
#   1. .env が無ければ .env.example をコピー
#   2. backend/.env が無ければ backend/.env.example をコピー
#   3. docker compose build && up -d
#   4. Laravel: composer install / key:generate / migrate --seed
#   5. アクセス先 URL を表示
#
# LOCAL_MODE=true なので Worker は実 Instagram API を叩かずスタブで応答する.

set -euo pipefail

cd "$(dirname "$0")/.."

echo "==> [1/5] .env を確認"
if [ ! -f .env ]; then
  cp .env.example .env
  echo "    .env を .env.example から作成しました"
fi
if [ ! -f backend/.env ]; then
  cp backend/.env.example backend/.env
  echo "    backend/.env を backend/.env.example から作成しました"
fi

echo "==> [2/5] Docker イメージビルド"
docker compose build

echo "==> [3/5] サービス起動 (MySQL / Redis / PHP / nginx / Worker / Frontend)"
docker compose up -d

echo "==> [4/5] Laravel 初期化 (composer install / key:generate / migrate --seed)"
# MySQL の起動完了を待つ (healthcheck が green になるまで)
echo "    MySQL の起動完了を待機中..."
for i in {1..30}; do
  if docker compose exec -T mysql mysqladmin ping -h localhost -uroot -p"${DB_ROOT_PASSWORD:-root}" --silent >/dev/null 2>&1; then
    break
  fi
  sleep 2
done

docker compose exec -T php composer install --no-interaction --prefer-dist
docker compose exec -T php php artisan key:generate --force
docker compose exec -T php php artisan migrate --force --seed

echo "==> [5/5] 起動完了"
cat <<EOF

  =========================================================
  Insta Auto ローカル動作確認モードで起動しました.
  =========================================================

    Frontend (Next.js)  : http://localhost:3000
    Backend  API        : http://localhost:8080/api
    MySQL               : 127.0.0.1:${HOST_MYSQL_PORT:-3308}
    Redis               : 127.0.0.1:${HOST_REDIS_PORT:-6380}

  ログイン:
    メール   : staff@example.com
    パスワード: 強いパスワード   (database/seeders/UserSeeder.php 参照)

  ログ確認:
    docker compose logs -f worker
    docker compose logs -f php

  停止:
    docker compose down

EOF
