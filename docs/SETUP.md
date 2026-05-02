# Insta Auto 本番投入セットアップガイド

設計書 `docs/DESIGN.md` の MVP 実装は完了済み。本書は **コードの実装以外で 担当者自身に行っていただく準備作業** を、ゼロから順番に説明します。

すべて完了するまでの目安: **約 4〜6 時間 + 各種審査の待ち時間 (合計 1〜3 営業日)**。

---

## 0. 全体像

| ステップ | 所要 | ブロッカー |
|---|---|---|
| 1. ドメイン取得 | 30 分 | DNS 反映待ち |
| 2. さくら VPS 契約 | 30 分 | 即時 |
| 3. Bright Data 契約 | 30 分〜1 日 | 法人審査が入ることあり |
| 4. Anthropic API キー取得 | 15 分 | 即時 |
| 5. Slack Webhook 作成 | 15 分 | 即時 |
| 6. Sentry プロジェクト作成 | 15 分 | 即時 |
| 7. Insta Auto IG アカウント整備 | 30 分 | 2FA を SMS に変更 |
| 8. ローカル PC で初回セッション生成 | 15 分 | チャレンジ通過 |
| 9. Vercel に Frontend デプロイ | 30 分 | GitHub 連携 |
| 10. VPS に Backend + Worker デプロイ | 1 時間 | SSH キー、DNS 反映 |
| 11. 段階的本番運用開始 (Day 1〜22+) | 約 1 ヶ月 | safety_events 監視 |

---

## 1. ドメイン取得

### 推奨レジストラ

- **お名前.com** または **Cloudflare Registrar** (.com で年間 1,300 円前後)

### 必要なドメイン

1 つで足ります。以下は本書での例:

```
example.com
```

**サブドメイン構成 (DNS 設定は後で)**:

| サブドメイン | 用途 |
|---|---|
| `api.example.com` | さくら VPS 上の Laravel API |
| `example.com` または `app.example.com` | Vercel 上のダッシュボード(任意) |

実際のドメインを決めたら、以後本書の `example.com` を読み替えてください。

---

## 2. さくら VPS 契約

### 2.1 契約

1. https://vps.sakura.ad.jp/ にアクセス
2. **「2GB プラン (月 1,738 円)」** を選択
3. リージョン: **石狩** または **東京** (どちらでも可)
4. OS: **Ubuntu 22.04 LTS amd64**
5. アカウント作成 → クレジットカード登録 → 契約

### 2.2 初回ログイン

契約完了後、コントロールパネルで:

1. **管理ユーザー** (例: `ubuntu`) のパスワードを記録
2. **公開鍵 SSH** を登録 (推奨)
   ```bash
   # ローカル PC で
   ssh-keygen -t ed25519 -C "instaauto-vps"
   # 公開鍵 ~/.ssh/id_ed25519.pub の中身をコントロールパネルに貼り付ける
   ```
3. VPS の **IPv4 アドレス** を控える(例: `203.0.113.42`)

### 2.3 ドメインを VPS に向ける

ドメインレジストラのDNS管理画面で:

```
Type:  A
Name:  api.example.com
Value: 203.0.113.42  (VPS の IPv4)
TTL:   300
```

DNS 反映まで最大 1 時間。`dig api.example.com` で確認できます。

### 2.4 SSH 接続テスト

```bash
ssh ubuntu@api.example.com
# 公開鍵認証ならパスワード不要
```

---

## 3. Bright Data (住宅用プロキシ) 契約

これが **MVP 全体で一番面倒** です。

### 3.1 契約

1. https://brightdata.com/ にアクセス
2. **Sign Up** → 法人/個人を選択(個人でも OK)
3. メール認証 → 電話番号認証
4. 支払い方法を登録(クレジットカード)

法人/用途審査が入ることがあります。**用途には「マーケティング業務での自社ソーシャルメディア運用」** と回答してください。Instagram スクレイピング目的だと拒否される可能性が高いです。

### 3.2 Residential Proxy ゾーンを作成

ダッシュボード → **Proxies & Scraping → Add → Residential Proxies** を選択:

| 設定項目 | 値 |
|---|---|
| Zone name | `instaauto-residential` |
| Country | **Japan** に固定 |
| IP Type | Residential |
| Sticky session | **Enabled** (必須) |
| Session duration | **24 hours** |

### 3.3 認証情報を控える

ゾーン作成後、**Access parameters** タブで以下を確認:

- Customer ID: `brd-customer-hl_xxxxxxxx`
- Zone password: `xxxxxxxxxxxxxxxx`
- Host: `brd.superproxy.io`
- Port: `22225`

最終的な PROXY_URL の形式 (設計書 §4.1.1):

```
http://brd-customer-hl_xxxxxxxx-zone-instaauto-residential-session-1_static:PASSWORD@brd.superproxy.io:22225
```

`session-1_static` の `1` は `account_id`(MVPでは1固定)。`_static` を付けることで 24 時間セッション固定。

### 3.4 月額予算

最低 **8GB プラン (約 60 USD / 月)** を推奨。1 アカウントの運用なら十分です。

---

## 4. Anthropic API キー取得

### 4.1 取得

1. https://console.anthropic.com/ にアクセス
2. アカウント作成 (Google ログイン or メール)
3. **Settings → API Keys → Create Key**
4. キー名: `instaauto-production`
5. 表示された **`sk-ant-...`** を控える(再表示不可)

### 4.2 課金

**Settings → Billing** で

- 支払い方法登録
- **Auto-recharge を $50** で設定 (月予算 ~3,000 円相当)

### 4.3 動作確認 (ローカル PC で)

```bash
curl https://api.anthropic.com/v1/messages \
  -H "x-api-key: sk-ant-xxxxxxx" \
  -H "anthropic-version: 2023-06-01" \
  -H "content-type: application/json" \
  -d '{
    "model": "claude-sonnet-4-6",
    "max_tokens": 50,
    "messages": [{"role":"user","content":"hello"}]
  }'
```

200 が返れば OK。

---

## 5. Slack Webhook 作成

緊急停止イベントの即時通知用。

1. https://api.slack.com/apps?new_app=1 で **Create New App → From scratch**
2. App Name: `instaauto-alerts` / Workspace: 任意
3. **Incoming Webhooks → Activate Incoming Webhooks → On**
4. **Add New Webhook to Workspace** → 通知を流すチャンネル(例: `#instaauto-ops`)を選択
5. 表示された **Webhook URL** (`https://hooks.slack.com/services/T0xx/B0xx/yyyy`) を控える

---

## 6. Sentry プロジェクト作成 (任意だが推奨)

### 6.1 PHP/Laravel プロジェクト

1. https://sentry.io/ に登録 (個人 Free プランで十分)
2. **Create Project → Platform: Laravel → Project name: `instaauto-backend`**
3. 表示された **DSN** (`https://xxx@oxx.ingest.sentry.io/xxx`) を控える

### 6.2 Python プロジェクト (任意)

同じく **Platform: Python → Project name: `instaauto-worker`** で別 DSN を発行。Worker と Backend で別管理にすると切り分けが楽です。

---

## 7. Insta Auto Instagram アカウント整備

### 7.1 認証情報の整理

- IG のユーザー名(`your_ig_account` など)
- IG のパスワード(直近で変更している場合は最新の値)
- IG に紐づくメールアドレスへのアクセス
- IG に紐づく電話番号(2FA 用 SMS が届く番号)

### 7.2 二段階認証は SMS にする

- アプリ認証 (Authenticator) ではなく **SMS ベース** に変更
- 理由: Instagrapi の `challenge_code_handler` は SMS の数字コード入力に最適化されています
- 設定: スマホアプリ → 設定 → セキュリティ → 二段階認証 → SMS

### 7.3 過去のログイン IP を確認

- 最近、海外からアクセスされた形跡がないか確認
- 直近 1 週間で大きな変動があったら、Bright Data 経由の初回ログイン時に Challenge が確実に来ます (これは想定内)

---

## 8. ローカル PC で初回セッション生成

設計書 §4.1.2 / docs/OPERATIONS.md §3。**サーバーでは絶対に実行しない**(チャレンジを通せないため)。

### 8.1 環境準備 (ローカル PC = Mac の前提)

```bash
cd /path/to/insta_auto
# 仮想環境はすでに作成済み (Phase 0 で構築)
cd worker && source .venv/bin/activate
```

未作成の場合:

```bash
python3.10 -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt
```

### 8.2 ローカル `.env` を作成

```bash
cd /path/to/insta_auto
cat > .env <<'EOF'
INSTAGRAM_USERNAME=your_ig_account
INSTAGRAM_PASSWORD=実際のパスワード
PROXY_URL=http://brd-customer-hl_xxxx-zone-instaauto-residential-session-1_static:PASSWORD@brd.superproxy.io:22225
EOF
chmod 600 .env
```

### 8.3 セッション生成

```bash
cd /path/to/insta_auto
worker/.venv/bin/python scripts/generate_session.py
```

対話プロンプト:

1. `Instagram username:` → 既に `.env` から読み込まれている場合は省略
2. `Instagram password:` → 同上
3. **チャレンジが要求された場合**、SMS / メールに届いた 6 桁コードを入力
4. 成功すると `./sessions/your_ig_account.json` が生成される

### 8.4 疎通確認

```bash
worker/.venv/bin/python scripts/smoke_test_instagrapi.py
```

`account_info` が JSON で表示されれば OK。

### 8.5 セッションを VPS に転送 (このタイミングではまだ VPS が立っていなければ後回しでOK)

```bash
scp sessions/your_ig_account.json ubuntu@api.example.com:/tmp/
ssh ubuntu@api.example.com "
  sudo mv /tmp/your_ig_account.json /srv/instaauto/storage/sessions/1.json &&
  sudo chown instaauto:instaauto /srv/instaauto/storage/sessions/1.json &&
  sudo chmod 600 /srv/instaauto/storage/sessions/1.json
"
```

`accounts.ig_session_path` には `/storage/sessions/1.json` を登録します(後述)。

---

## 9. Vercel に Frontend をデプロイ

### 9.1 Vercel アカウント

1. https://vercel.com/signup → GitHub アカウントで登録 (kouki485)
2. **Add New → Project**
3. リポジトリ `YOUR_ORG/insta_auto` をインポート
4. **Root Directory** に `frontend` を指定
5. Framework: **Next.js** が自動検出される

### 9.2 環境変数

Project Settings → Environment Variables:

| Key | Value | Environment |
|---|---|---|
| `NEXT_PUBLIC_API_URL` | `https://api.example.com/api` | Production / Preview |
| `INTERNAL_API_URL` | `https://api.example.com/api` | Production / Preview |
| `NEXT_PUBLIC_SANCTUM_DOMAIN` | `api.example.com` | Production / Preview |

### 9.3 デプロイ

**Deploy** ボタンを押すと自動でビルド → 数分で完了。

成功すると `https://app.example.com` のような URL が発行されます。Vercel の **Settings → Domains** で `app.example.com` などに変更可能。

最終的な Frontend URL を控えてください(後で Backend の CORS 設定に必要)。

---

## 10. VPS に Backend + Worker をデプロイ

ここからは VPS 上での作業です。`docs/OPERATIONS.md §2` のコマンドを実行していきます。

### 10.1 SSH ログイン + ベースパッケージ

```bash
ssh ubuntu@api.example.com

# システム更新
sudo apt update && sudo apt upgrade -y

# 必要パッケージ
sudo apt install -y \
  nginx \
  mysql-server \
  redis-server \
  python3.11 python3.11-venv python3.11-dev \
  php8.3-fpm php8.3-cli php8.3-mysql php8.3-redis \
  php8.3-mbstring php8.3-xml php8.3-zip php8.3-curl php8.3-bcmath \
  composer \
  supervisor \
  certbot python3-certbot-nginx \
  git unzip
```

### 10.2 instaauto ユーザーとディレクトリ

```bash
sudo adduser --disabled-password --gecos "" instaauto
sudo mkdir -p /srv/instaauto/storage/sessions /srv/instaauto/storage/images /srv/instaauto/backups /var/log/instaauto
sudo chown -R instaauto:instaauto /srv/instaauto /var/log/instaauto
sudo chmod 700 /srv/instaauto/storage/sessions
```

### 10.3 ソース取得

```bash
sudo -u instaauto git clone https://github.com/YOUR_ORG/insta_auto.git /srv/instaauto/app
sudo -u instaauto ln -s /srv/instaauto/app/backend /srv/instaauto/backend
sudo -u instaauto ln -s /srv/instaauto/app/worker /srv/instaauto/worker
```

### 10.4 MySQL データベース作成

```bash
sudo mysql_secure_installation   # root パスワードを設定
sudo mysql -uroot -p
```

```sql
CREATE DATABASE instaauto CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'instaauto'@'localhost' IDENTIFIED BY '強いパスワード';
GRANT ALL PRIVILEGES ON instaauto.* TO 'instaauto'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

### 10.5 Redis 設定 (パスワード設定推奨)

```bash
sudo nano /etc/redis/redis.conf
# requirepass 強いパスワード
sudo systemctl restart redis-server
```

### 10.6 Backend `.env` を配置

ローカル PC で以下を `backend.env.production` として作成:

```dotenv
APP_NAME=InstaAuto
APP_ENV=production
APP_KEY=                       # この後 key:generate で生成
APP_DEBUG=false
APP_TIMEZONE=Asia/Tokyo
APP_URL=https://api.example.com

APP_LOCALE=ja
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=ja_JP
APP_MAINTENANCE_DRIVER=file
PHP_CLI_SERVER_WORKERS=4
BCRYPT_ROUNDS=12
LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=info

# DB
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=instaauto
DB_USERNAME=instaauto
DB_PASSWORD=10.4で設定したMySQLパスワード

# Redis
SESSION_DRIVER=redis
SESSION_LIFETIME=120
QUEUE_CONNECTION=redis
CACHE_STORE=redis
CACHE_PREFIX=instaauto
REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=10.5で設定したRedisパスワード
REDIS_PORT=6379

# Sanctum / CORS
SANCTUM_STATEFUL_DOMAINS=app.example.com
FRONTEND_URL=https://app.example.com
SESSION_DOMAIN=null

# Anthropic
ANTHROPIC_API_KEY=sk-ant-xxxxxxx
CLAUDE_MODEL=claude-sonnet-4-6
CLAUDE_API_DAILY_LIMIT=200

# Slack
SLACK_WEBHOOK_URL=https://hooks.slack.com/services/T0xx/B0xx/yyyy

# Sentry
SENTRY_LARAVEL_DSN=https://xxx@oxx.ingest.sentry.io/xxx
SENTRY_TRACES_SAMPLE_RATE=0.1

# Worker (絶対パス)
WORKER_PYTHON_PATH=/srv/instaauto/worker/.venv/bin/python
WORKER_SCRIPT_PATH=/srv/instaauto/worker/main.py
WORKER_SESSION_DIR=/srv/instaauto/storage/sessions

# 初期管理ユーザー (db:seed 用)
SEED_ADMIN_EMAIL=staff@example.com
SEED_ADMIN_PASSWORD=強いパスワード
SEED_ADMIN_NAME=運用担当者

# 初期 Account (本番 IG アカウント)
SEED_IG_USERNAME=your_ig_account
SEED_IG_PASSWORD=実際のIGパスワード
SEED_PROXY_URL=http://brd-customer-hl_xxxx-zone-instaauto-residential-session-1_static:PASSWORD@brd.superproxy.io:22225
SEED_SESSION_PATH=/srv/instaauto/storage/sessions/1.json
SEED_STORE_NAME=Insta Auto
```

転送:

```bash
scp backend.env.production ubuntu@api.example.com:/tmp/backend.env
ssh ubuntu@api.example.com "
  sudo mv /tmp/backend.env /srv/instaauto/backend/.env &&
  sudo chown instaauto:instaauto /srv/instaauto/backend/.env &&
  sudo chmod 600 /srv/instaauto/backend/.env
"
```

### 10.7 Backend セットアップ

```bash
ssh ubuntu@api.example.com
sudo -u instaauto bash <<'EOF'
cd /srv/instaauto/backend
composer install --no-dev --optimize-autoloader
php artisan key:generate --force
php artisan migrate --force --seed
php artisan storage:link
php artisan config:cache
php artisan route:cache
EOF
```

### 10.8 Worker `.env` を配置

ローカル PC で `worker.env.production`:

```dotenv
DB_HOST=127.0.0.1
DB_PORT=3306
DB_USER=instaauto
DB_PASSWORD=10.4で設定したMySQLパスワード
DB_NAME=instaauto

REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_DB=0
# REDIS_PASSWORD は redis-py 直アクセス用 (Phase 4 で必要なら追加)

INSTAGRAM_USERNAME=your_ig_account
INSTAGRAM_PASSWORD=実際のIGパスワード
PROXY_URL=http://brd-customer-hl_xxxx-zone-instaauto-residential-session-1_static:PASSWORD@brd.superproxy.io:22225
SESSION_DIR=/srv/instaauto/storage/sessions

SENTRY_DSN=https://xxx@oxx.ingest.sentry.io/yyy   # Worker 用 DSN
SLACK_WEBHOOK_URL=https://hooks.slack.com/services/T0xx/B0xx/yyyy
LOG_LEVEL=INFO
WORKER_QUEUE_TIMEOUT=30
```

転送:

```bash
scp worker.env.production ubuntu@api.example.com:/tmp/worker.env
ssh ubuntu@api.example.com "
  sudo mv /tmp/worker.env /srv/instaauto/worker/.env &&
  sudo chown instaauto:instaauto /srv/instaauto/worker/.env &&
  sudo chmod 600 /srv/instaauto/worker/.env
"
```

### 10.9 Worker セットアップ

```bash
sudo -u instaauto bash <<'EOF'
cd /srv/instaauto/worker
python3.11 -m venv .venv
.venv/bin/pip install --upgrade pip
.venv/bin/pip install -r requirements.txt
EOF
```

### 10.10 セッションファイルを VPS に転送

§8.5 の手順を実行(まだしていなければ)。

`accounts.ig_session_path` は `db:seed` で `/srv/instaauto/storage/sessions/1.json` に既にセット済み。`account_id=1` のレコードに対応します。

### 10.11 nginx + HTTPS

```bash
sudo cp /srv/instaauto/app/deploy/nginx-prod.conf /etc/nginx/sites-available/instaauto
# server_name api.example.com を実際のドメインに合わせて編集
sudo nano /etc/nginx/sites-available/instaauto
sudo ln -s /etc/nginx/sites-available/instaauto /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t
sudo systemctl restart nginx

# Let's Encrypt 証明書
sudo certbot --nginx -d api.example.com
# メールアドレス入力 → 規約同意 → リダイレクト設定 (Yes 推奨)
```

### 10.12 supervisor

```bash
sudo cp /srv/instaauto/app/deploy/supervisor.conf /etc/supervisor/conf.d/instaauto.conf
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status
# instaauto-py-worker  RUNNING  pid xxxx, uptime 0:00:05
```

### 10.13 cron

```bash
sudo -u instaauto crontab /srv/instaauto/app/deploy/crontab.example
sudo -u instaauto crontab -l   # 反映確認
```

### 10.14 動作確認

```bash
# API ヘルスチェック
curl https://api.example.com/up
# → {"status":"ok"} に近いレスポンスならOK

# ログイン (初期管理ユーザーで)
curl -X POST https://api.example.com/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"staff@example.com","password":"強いパスワード"}'
# → {"data":{"token":"...","user":{...}}}
```

### 10.15 Vercel から API を叩けるか確認

ブラウザで Vercel の URL (`https://app.example.com`) を開き、ログイン画面にメール/パスワードを入力。

ダッシュボードに遷移し、KPI カードが表示されれば **本番疎通完了** 🎉

---

## 11. 段階的本番運用 (設計書 §7.5 / docs/OPERATIONS.md §8)

ここからは **コード変更不要、運用フェーズ** です。

### Day 1〜3: ストーリー投稿のみ手動承認モード

1. ダッシュボード `/posts` 画面で画像をアップロード → ストーリー予約
2. Worker が投稿 → Instagram で確認
3. `/safety` に critical イベントが出ていないか毎日チェック

### Day 4〜7: フィード投稿を 1 回

1. `/posts` でフィード予約 (キャプション込み)
2. 投稿成功を Instagram で確認

### Day 8〜14: DM を 1 日 3 件、手動承認モード

1. ダッシュボード `/settings` で `daily_dm_limit=3` に設定
2. `/prospects` で候補を 3 件「承認」
3. cron で `instaauto:dispatch-dm` が実行されると 30 分間隔で送信
4. `/dm-logs` で送信履歴と返信を毎日確認

### Day 15〜21: DM を 1 日 5 件、自動モード

1. `/settings` で `daily_dm_limit=5`
2. `/prospects` の状態は自動で進行(候補プールから tourist_score 高い順に投入)

### Day 22+: ウォームアップ自動引き上げ

`instaauto:adjust-warmup` が毎日 0:00 に実行され、warmup_started_at からの経過週で 5/10/15/20 へ自動引き上げ。

**critical イベントが出たら即停止 → docs/OPERATIONS.md §4 の復帰手順** で対応してください。

---

## 12. 月次でやること

| 頻度 | 作業 | 参照 |
|---|---|---|
| 毎日 | Slack の daily report 確認 | OPERATIONS.md §7 |
| 毎日 | `/safety` で critical 0 件か確認 | - |
| 毎週 | DM 返信率を `/dm-logs` で集計 | - |
| 毎月 | mysqldump バックアップが取得できているか | OPERATIONS.md §6 |
| 30 日毎 | セッションファイルの失効確認 | OPERATIONS.md §3 |
| 四半期 | Bright Data / Anthropic / VPS の支払額確認 | - |
| 半年 | Instagrapi のバージョン更新確認 (破壊的変更チェック) | - |

---

## 13. トラブル別 連絡先 / 対応

| 症状 | 連絡先 / 対応 |
|---|---|
| Bright Data でアカウント停止 | サポート (chat) → 用途を再説明 |
| Anthropic API エラー | `/safety` でクォータ超過か確認 → console で予算引き上げ |
| Instagram から「確認が必要」のメール | スマホアプリで通常ログイン → /safety 確認 → §4 復帰手順 |
| VPS が応答しない | さくらコントロールパネルから VNC コンソールで再起動 |
| Vercel ビルドエラー | GitHub Actions ログ + Vercel Dashboard ログを確認 |

---

## 14. チェックリスト (本番投入前)

- [ ] さくら VPS 契約 + ドメインが api.example.com を指している
- [ ] Bright Data Residential ゾーン作成 + sticky session 24h 設定
- [ ] Anthropic API キー発行 + 課金有効化
- [ ] Slack Webhook URL 取得済み
- [ ] Sentry プロジェクト 2 つ (backend / worker) 作成
- [ ] Insta Auto IG: パスワード把握 + 2FA を SMS に
- [ ] ローカル PC でセッション生成成功 + smoke test 成功
- [ ] VPS に backend.env / worker.env 配置済み
- [ ] `php artisan migrate --force --seed` 完了
- [ ] セッションファイル `/srv/instaauto/storage/sessions/1.json` 配置 + chmod 600
- [ ] supervisor で `instaauto-py-worker` が RUNNING
- [ ] cron で `schedule:run` が毎分動いている
- [ ] HTTPS 証明書発行済み (`https://api.example.com/up` が ok)
- [ ] Vercel デプロイ済み + `NEXT_PUBLIC_API_URL` 設定済み
- [ ] ダッシュボードにログインできる
- [ ] 初回手動投稿が成功 (Instagram で目視確認)
- [ ] /safety に critical 0 件で 24 時間経過

すべてチェックがつけば Day 1 開始です。

---

## 15. 質問が出た時のドキュメント参照順

1. 設計仕様の確認 → `docs/DESIGN.md`
2. 運用手順 → `docs/OPERATIONS.md`
3. 本書 (準備手順) → `docs/SETUP.md`
4. 実装の挙動 → 各 `tests/` の振る舞い
5. それでも不明 → コード自体 (Auto-generated comments と PHPDoc を参照)
