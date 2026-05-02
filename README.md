# Insta Auto — Instagram 運用自動化ツール

任意のテナント(店舗 / ブランド / 個人事業主)の Instagram 運用を自動化する SaaS 型ツールです。
最初の発注時の MVP 仕様(うなら向け運用)は `docs/DESIGN.md` に保管していますが、
コード本体は **マルチテナント対応の汎用プロダクト** として実装されています。

## アーキテクチャ概要

| 層 | 技術 | ディレクトリ |
|---|---|---|
| フロントエンド | Next.js 14 (App Router) | `frontend/` |
| バックエンド API | Laravel 11 + Sanctum | `backend/` |
| Instagram 自動化 Worker | Python 3.11 + Instagrapi | `worker/` |
| DB / Queue | MySQL 8.0 / Redis 7 | docker-compose |
| 文面生成 | Anthropic Claude API | `backend/app/Services/DmGeneratorService.php` |
| プロキシ | Bright Data Residential (sticky session) | - |

詳細なアーキテクチャ図は `docs/DESIGN.md` §1.1。

## 5分でローカル動作確認 (LOCAL_MODE)

ドメイン / VPS / Bright Data プロキシ / Anthropic API キー / 実 Instagram アカウント
**すべて不要** で、Backend + Frontend + Worker の疎通だけ確認したい場合の手順です.

LOCAL_MODE では Worker は実 Instagram API を叩かずスタブクライアント
(`worker/src/local_stub_client.py`) で応答します. DM 文面も Anthropic API キーが
空であれば `dm_templates` のテンプレ展開へ自動フォールバックします.

```bash
# 1. ワンコマンド起動 (.env 自動生成 → docker compose up → migrate --seed)
./scripts/local-start.sh
```

起動後のアクセス先:

| サービス | URL |
|---|---|
| Frontend (Next.js) | http://localhost:3000 |
| Backend API | http://localhost:8080/api |

ログイン情報 (`backend/.env.example` の `SEED_ADMIN_*` で変更可):

- メール: `admin@example.com`
- パスワード: `password`

ログ確認:

```bash
docker compose logs -f worker   # スタブで応答するジョブハンドラのログ
docker compose logs -f php      # Laravel API のログ
```

停止:

```bash
docker compose down
```

本番運用に切り替えるときは `.env` の `LOCAL_MODE=false` にし、
`docs/SETUP.md` の手順で各種シークレット/プロキシ/セッションを揃えてください.

---

## 開発フロー

### 初回セットアップ

```bash
# 1. 環境変数の雛形をコピー (4 ファイルすべて必要)
cp .env.example .env                  # docker-compose ルート用 (HOST_MYSQL_PORT 等)
cp backend/.env.example backend/.env
cp worker/.env.example worker/.env
cp frontend/.env.example frontend/.env.local

# 2. .env 系すべてに DB/Redis/IG 認証情報/プロキシURL/Anthropic キー を記入
#    DB_PASSWORD と DB_ROOT_PASSWORD は必ず強いパスワードに差し替える

# 3. Docker でローカル環境を立ち上げ (デフォルトでは MySQL=3308, Redis=6380 を使用)
docker compose up -d mysql redis

# 4. Laravel 初期化
docker compose run --rm php composer install
docker compose run --rm php php artisan key:generate
docker compose run --rm php php artisan migrate:fresh --seed

# 5. Worker 初期化
cd worker
python3.11 -m venv venv
source venv/bin/activate
pip install -r requirements.txt
cd ..

# 6. Frontend 初期化
cd frontend && npm install && cd ..

# 7. 全サービス起動
docker compose up
```

### 初回セッション生成 (ローカル PC で対話実行)

新規 Instagram アカウントを使い始めるとき、またはセッションが失効したときに 1 回だけ実施。
**サーバー上で実行しない。** チャレンジ要求 (メール / SMS) を対話で通す必要があるため。

```bash
cd scripts
python3.11 generate_session.py
# username, password を入力
# 必要に応じてチャレンジコードを入力
# → ./sessions/<username>.json が出力される

# 出力されたセッションを本番サーバーへ scp で転送する想定
```

### 疎通検証

```bash
python3.11 scripts/smoke_test_instagrapi.py
# プロキシ経由で account_info() が取れれば OK
```

## 実装フェーズ

`docs/DESIGN.md` §0.2 に準拠。

| Phase | 内容 |
|---|---|
| 0 | 環境構築 + Instagrapi 疎通検証 |
| 1 | バックエンド基盤 (Laravel + DB) |
| 2 | 自動投稿機能 |
| 3 | 候補抽出機能 |
| 4 | 自動 DM 送信機能 |
| 5 | 管理ダッシュボード |
| 6 | 統合テスト + 段階的本番運用 |

各 Phase の完了条件は `~/.claude/plans/instagram-vast-dolphin.md` を参照。

## BAN 対策 (最重要)

`docs/DESIGN.md` §4 の安全装置を**絶対に省略しない**。

- 同一アカウント = 同一プロキシ IP (Bright Data sticky session)
- セッション永続化 (毎回ログインしない)
- アクション間隔ランダム化 (3〜15 秒)
- 例外検知時は即停止 + Slack 通知
- ノイズ動作 (DM 以外の人間らしい行動を混ぜる)
- ウォームアップ (Week1: 5DM/日 → Week4+: 20DM/日)

## ディレクトリ構成

```
insta_auto/
├── backend/        Laravel 11
├── worker/         Python 3.11 + Instagrapi
├── frontend/       Next.js 14
├── scripts/        ローカル実行ユーティリティ
├── docs/           DESIGN.md, OPERATIONS.md, SETUP.md
├── deploy/         supervisor / nginx / crontab テンプレ
└── docker-compose.yml
```

## ドキュメント

| ファイル | 用途 |
|---|---|
| `docs/DESIGN.md` | 初版発注時の設計書(うなら向け固有要件を含むので歴史的資料として保管) |
| `docs/OPERATIONS.md` | 運用担当者向け日常運用手順 (緊急停止 / 復帰 / GDPR / バックアップ) |
| `docs/SETUP.md` | 本番投入セットアップガイド (ドメイン / VPS / Bright Data 等) |

## ライセンス / 注意

本プロジェクトは Instagram 利用規約に抵触する非公式手段 (Instagrapi) を採用しており、
利用するテナント自身の責任で運用してください。BAN リスクはテナント責任です。
