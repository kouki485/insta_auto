# うなら 運用手順書 (OPERATIONS.md)

本書は **運用代行スタッフ向け** の手順書です。設計書(`docs/DESIGN.md`)と併せて参照してください。

---

## 1. 本番環境構成 (さくら VPS 2GB)

```
┌──────────────────────────┐    ┌──────────────────────────┐
│  Vercel (frontend)        │ ←→ │  api.unara.example.com    │
└──────────────────────────┘    │  (nginx + php-fpm)        │
                                 │  /home/unara/backend      │
                                 │  /home/unara/worker       │
                                 │  /home/unara/storage      │
                                 │  MySQL 8.0 + Redis 7      │
                                 └──────────────────────────┘
                                          │ (Bright Data)
                                          ▼
                                    Instagram
```

- ホスト: さくら VPS 2GB / Ubuntu 22.04
- ユーザー: `unara`(`/home/unara` 配下に backend/worker/storage)
- supervisor で `unara-py-worker` と `unara-laravel-results` を常駐
- cron で `php artisan schedule:run` を毎分

設定テンプレ:
- `deploy/supervisor.conf`
- `deploy/crontab.example`
- `deploy/nginx-prod.conf`

---

## 2. 初回デプロイ手順

```bash
# 1. ユーザーとディレクトリ
sudo adduser --disabled-password --gecos "" unara
sudo mkdir -p /home/unara/storage/sessions /var/log/unara
sudo chown -R unara:unara /home/unara /var/log/unara
sudo chmod 700 /home/unara/storage/sessions

# 2. ソースを配置
sudo -u unara git clone https://github.com/kouki485/insta_auto.git /home/unara/app
sudo -u unara ln -s /home/unara/app/backend /home/unara/backend
sudo -u unara ln -s /home/unara/app/worker /home/unara/worker

# 3. PHP / Composer / Python
sudo apt install php8.3-fpm php8.3-mysql php8.3-redis php8.3-mbstring php8.3-xml php8.3-zip composer mysql-server redis-server python3.11 python3.11-venv

# 4. .env を配置 (scp で本番値を送る)
scp local-secrets/backend.env unara@server:/home/unara/backend/.env
scp local-secrets/worker.env  unara@server:/home/unara/worker/.env

# 5. backend
sudo -u unara bash -c 'cd /home/unara/backend && composer install --no-dev --optimize-autoloader'
sudo -u unara php /home/unara/backend/artisan key:generate --force
sudo -u unara php /home/unara/backend/artisan migrate --force --seed
sudo -u unara php /home/unara/backend/artisan storage:link
sudo -u unara php /home/unara/backend/artisan config:cache
sudo -u unara php /home/unara/backend/artisan route:cache

# 6. worker
sudo -u unara bash -c 'cd /home/unara/worker && python3.11 -m venv venv && venv/bin/pip install -r requirements.txt'

# 7. nginx + Let's Encrypt
sudo cp /home/unara/app/deploy/nginx-prod.conf /etc/nginx/sites-available/unara
sudo ln -s /etc/nginx/sites-available/unara /etc/nginx/sites-enabled/
sudo certbot --nginx -d api.unara.example.com
sudo systemctl restart nginx

# 8. supervisor
sudo cp /home/unara/app/deploy/supervisor.conf /etc/supervisor/conf.d/unara.conf
sudo supervisorctl reread && sudo supervisorctl update

# 9. cron
sudo -u unara crontab /home/unara/app/deploy/crontab.example
```

---

## 3. Instagram セッションの初回生成 / 失効時の再生成

設計書 §4.1.2 に従い **Kouki 様のローカル PC で対話実行** してください。サーバー上では行わない(Challenge を通せない)。

```bash
# ローカル PC (macOS)
cd /Users/koukikaida/Desktop/insta_auto/worker
source .venv/bin/activate
python ../scripts/generate_session.py
# username + password + (必要なら) チャレンジコードを入力
# → ./sessions/<username>.json が生成される

# 本番サーバーへ転送
scp ../sessions/<username>.json unara@server:/home/unara/storage/sessions/<account_id>.json
ssh unara@server "chmod 600 /home/unara/storage/sessions/<account_id>.json"
```

セッションファイルは **30 日以上は再生成不要**(設計書 §4.1.2)。失効すると Worker が `LoginRequired` を返すので safety_events に記録されます → 再生成 → supervisor 再起動。

---

## 4. 緊急停止からの復帰手順 (設計書 §4.4)

1. ダッシュボード `/safety` で停止理由を確認
2. ブラウザ / スマホアプリで実際に Instagram にログインし Challenge を通過
3. **24〜72 時間放置** (自動アクションを止めたまま)
4. ダッシュボード `/settings` の「再開(再ウォームアップ)」ボタンを押す
   - `accounts.status='active'`, `daily_dm_limit=5`, `warmup_started_at=now()` にリセットされる
5. 再開後 1 週間は `daily_dm_limit=5`、その後ウォームアップが自動引き上げ

---

## 5. GDPR / 個人情報保護法対応 — 削除要請

設計書 §9.2: EU 圏ユーザーから削除要請があった場合は **24 時間以内** に prospects と dm_logs を物理削除します。

```sql
-- 1. 該当 prospect を取得
SELECT id, ig_username FROM prospects WHERE ig_username = 'foo' AND account_id = 1;

-- 2. dm_logs と一緒に削除 (FK CASCADE で連動)
DELETE FROM prospects WHERE id = ?;
```

実行後、Slack に削除完了レポートを残します。

---

## 6. 月次バックアップ

`deploy/crontab.example` に毎月 1 日 04:00 JST で MySQL ダンプを暗号化保存する例があります。

復号:

```bash
openssl enc -d -aes-256-cbc -salt \
  -in /home/unara/backups/unara_202605.sql.enc \
  -pass file:/home/unara/.backup-key \
  > /tmp/unara_202605.sql
```

---

## 7. アラート / 監視

- `unara:daily-report` が毎日 09:00 JST に Slack に前日サマリーを投稿
- critical イベントは即時 Slack(Worker 側 SafetyGuard / Laravel ProcessWorkerResults)
- Sentry が backend / worker のエラーを記録
- 1 週間連続で critical 0 件であれば段階的本番運用 Day 22 へ進める(設計書 §7.5)

---

## 8. 段階的本番運用スケジュール (設計書 §7.5)

| 期間 | 内容 | 完了条件 |
|---|---|---|
| Day 1-3  | ストーリー投稿のみ手動承認 | safety_events に critical 0 件 |
| Day 4-7  | フィード投稿を 1 回 | 同上 |
| Day 8-14 | DM を 1 日 3 件、手動承認モード | 返信率 / critical を毎日確認 |
| Day 15-21| DM を 1 日 5 件、自動モードへ | 同上 |
| Day 22+  | ウォームアップスケジュールに従い段階引き上げ | 異常時は §4 復帰手順へ |

各段階は前段階で critical 0 件を確認してから進めてください。

---

## 9. データ保持ポリシー (自動)

`unara:prune-old-records` が毎日 03:00 JST に実行されます (設計書 §9.2)。

| データ | 保持期間 |
|---|---|
| `prospects` (status='new') | 30 日 |
| `prospects` (status='dm_sent') | 365 日 |
| `dm_logs` | 365 日 |
| `safety_events` | 90 日 |
| Instagram セッションファイル | 失効まで(手動) |
| 投稿済み画像 | 永続(手動削除のみ) |

`--dry-run` オプションで事前確認可能:

```bash
php artisan unara:prune-old-records --dry-run
```

---

## 10. トラブルシュート

| 症状 | 対処 |
|---|---|
| `LoginRequired` が頻発 | セッション再生成 → supervisor 再起動 |
| `ChallengeRequired` 検知 | 自動 pause 済み。§4 復帰手順 |
| プロキシ料金が逼迫 | Bright Data 代替(Smartproxy / IPRoyal)へ切替。`accounts.proxy_url` の URL 形式は同じスキーマ |
| ヘルススコアが低下 | `/safety` で原因イベントを確認、必要なら手動 pause |
| Anthropic API クォータ超過 | テンプレ直接送信にフォールバック済み(動作継続)。月次予算超過時は `CLAUDE_API_DAILY_LIMIT` を下げる |
