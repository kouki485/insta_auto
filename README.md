# うなら Instagram運用自動化アプリ

浅草の鰻専門店「うなら」向け Instagram 運用自動化システムの MVP。
詳細仕様は [docs/DESIGN.md](docs/DESIGN.md) を参照。

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

### Instagrapi 初回セッション生成 (ローカル PC で対話実行)

新規の Instagram アカウントを使い始めるとき、または セッションが失効したときの 1 回だけ実施。
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
| 3 | 観光客抽出機能 |
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
├── docs/           DESIGN.md, OPERATIONS.md
└── docker-compose.yml
```

## ライセンス / 注意

本プロジェクトは Instagram 利用規約に違反する非公式手段 (Instagrapi) を採用しており、
発注者了承のうえで運用される。BAN リスクは発注者責任。
