# うなら Instagram運用自動化アプリ 実装設計書

**発注者**: 株式会社Nemesis Code
**対象実装者**: Claude Code
**Version**: 1.0
**最終更新**: 2026-05-01

---

## 0. このドキュメントについて

本書は Claude Code がそのまま実装に着手できる粒度で記述された、うなら Instagram運用自動化アプリの実装設計書である。

### 0.1 前提条件(発注者了承済み)

- Instagram公式APIでは要件を満たせないため、非公式手段(Instagrapi)を採用する
- Instagram利用規約違反は確定。アカウントBANリスクは発注者了承済み
- 法的グレー領域(特定電子メール法、GDPR等)の存在は発注者了承済み
- 既存の「うなら」アカウントを使用(新規アカウントは即BANのため)
- 月額運用予算: 30,000円以内
- 将来的にSaaS展開予定だが、本設計書はうなら1店舗運用のMVPを対象とする

### 0.2 実装フェーズ

| Phase | 内容 | 期間 |
|---|---|---|
| 0 | 環境構築 + Instagrapi疎通検証 | 1〜2日 |
| 1 | バックエンド基盤(Laravel + DB) | 1週間 |
| 2 | 自動投稿機能 | 1週間 |
| 3 | 観光客抽出機能 | 2週間 |
| 4 | 自動DM送信機能 | 2週間 |
| 5 | 管理ダッシュボード | 1週間 |
| 6 | 統合テスト + 段階的本番運用 | 2週間 |

各Phaseは独立してテスト可能な状態で完了させること。Phase未完了状態で次Phaseに進まない。

### 0.3 Claude Code への指示原則

1. 不明点は推測せず、必ず発注者に確認する
2. 各Phase完了時に動作デモ(スクリーンショット or 動画)を発注者に提示する
3. BAN対策のレートリミットは絶対に緩めない(後述の安全装置を必ず実装)
4. テストコードは主要機能について必ず書く(カバレッジ60%以上)
5. 環境変数(APIキー、プロキシ認証情報、Instagramセッション)は絶対にgitにコミットしない

---

## 1. システム全体構成

### 1.1 アーキテクチャ図

```
┌─────────────────────────────────────────────────────────┐
│                  運用代行スタッフ                          │
└────────────────────┬────────────────────────────────────┘
                     │ HTTPS
                     ▼
┌─────────────────────────────────────────────────────────┐
│       Next.js 14 管理ダッシュボード(Vercel)              │
└────────────────────┬────────────────────────────────────┘
                     │ REST API
                     ▼
┌─────────────────────────────────────────────────────────┐
│           Laravel 11 API Server(VPS)                    │
│  ┌──────────┐  ┌──────────┐  ┌──────────────────────┐ │
│  │ Web API  │  │  Queue   │  │  Scheduler(cron)     │ │
│  └──────────┘  └────┬─────┘  └──────────┬───────────┘ │
│                     │                    │              │
│                     ▼                    ▼              │
│  ┌────────────────────────────────────────────────────┐│
│  │  Redis(Job Queue)                                  ││
│  └─────────────────────┬──────────────────────────────┘│
│                        │                                │
│                        ▼                                │
│  ┌────────────────────────────────────────────────────┐│
│  │  Python Worker(Instagrapi)                         ││
│  └─────────────────────┬──────────────────────────────┘│
│                        │                                │
│  ┌─────────────────────┴──────────────────────────────┐│
│  │  MySQL 8.0                                          ││
│  └────────────────────────────────────────────────────┘│
└────────────────────────┬────────────────────────────────┘
                         │ 住宅用プロキシ
                         ▼
                  ┌──────────────┐
                  │  Instagram   │
                  └──────────────┘
                         ▲
                         │ AI API
                         │
                  ┌──────┴───────┐
                  │  Claude API  │
                  └──────────────┘
```

### 1.2 技術スタック(確定)

| 層 | 採用技術 | バージョン | 採用理由 |
|---|---|---|---|
| フロントエンド | Next.js | 14.x (App Router) | Kouki既存スキル、SaaS展開時の拡張性 |
| UI | Tailwind CSS + shadcn/ui | 最新 | 開発速度 |
| バックエンドAPI | Laravel | 11.x | Kouki既存スキル(4年+) |
| 自動化Worker | Python + Instagrapi | 3.11 / 2.x | 非公式IG操作の業界標準OSS |
| ジョブキュー | Laravel Queue + Redis | - | Laravel標準 |
| DB | MySQL | 8.0 | 標準 |
| キャッシュ/Queue | Redis | 7.x | 標準 |
| プロキシ | Bright Data Residential | - | 業界最大手、日本IP取得可 |
| AI(文面生成) | Claude API | claude-sonnet-4-6 | 多言語品質高い |
| 言語判定 | langdetect (Python) | - | 軽量・無料 |
| 監視 | Laravel Telescope + Sentry | - | 標準 |
| インフラ | さくらVPS 2GB | - | 月1,738円 |
| Frontend Hosting | Vercel Hobby | - | 無料 |

### 1.3 Laravel ↔ Python Worker 連携方式

Laravel Queue は PHP Job クラスをシリアライズするため Python から直接読めない。本システムでは **Redis を独自フォーマットの Pub/Sub として使う**。

#### 連携ルール

```
Laravel側(Producer):
  Redis::lpush("unara:queue:{queue_name}", json_encode($payload))

Python Worker側(Consumer):
  redis.brpop(f"unara:queue:{queue_name}", timeout=30)
```

#### キュー名規約

| キュー名 | 用途 | Producer | Consumer |
|---|---|---|---|
| `unara:queue:dm` | DM送信 | Laravel | Python |
| `unara:queue:scrape` | 候補抽出 | Laravel | Python |
| `unara:queue:post_feed` | フィード投稿 | Laravel | Python |
| `unara:queue:post_story` | ストーリーズ投稿 | Laravel | Python |
| `unara:queue:result` | Workerからの結果返却 | Python | Laravel(再Queue) |

#### Payload フォーマット(JSON)

全ジョブ共通スキーマ:

```json
{
  "job_id": "uuid-v4",
  "account_id": 1,
  "type": "send_dm",
  "data": {
    "prospect_id": 12345,
    "message": "Hi! ...",
    "language": "en"
  },
  "created_at": "2026-05-01T10:00:00Z",
  "retry_count": 0
}
```

結果返却(`unara:queue:result`):

```json
{
  "job_id": "uuid-v4",
  "status": "success",
  "result": { "ig_message_id": "..." },
  "error": null,
  "completed_at": "2026-05-01T10:00:30Z"
}
```

Laravel側は `php artisan unara:process-results` コマンドで定期的にresultキューを消費し、DB更新する。

### 1.4 ディレクトリ構成

```
unara-app/
├── backend/                      # Laravel
│   ├── app/
│   │   ├── Http/Controllers/Api/
│   │   ├── Models/
│   │   ├── Services/
│   │   │   ├── InstagramService.php
│   │   │   ├── ProspectFilterService.php
│   │   │   └── DmGeneratorService.php
│   │   ├── Jobs/
│   │   │   ├── SendDmJob.php
│   │   │   ├── PostFeedJob.php
│   │   │   ├── PostStoryJob.php
│   │   │   └── ScrapeProspectsJob.php
│   │   └── Console/Commands/
│   ├── database/migrations/
│   ├── routes/api.php
│   └── tests/
├── worker/                       # Python (Instagrapi)
│   ├── src/
│   │   ├── instagram_client.py
│   │   ├── prospect_scraper.py
│   │   ├── dm_sender.py
│   │   ├── post_publisher.py
│   │   └── safety_guard.py
│   ├── tests/
│   ├── requirements.txt
│   └── main.py
├── frontend/                     # Next.js
│   ├── app/
│   ├── components/
│   ├── lib/
│   └── package.json
├── docs/
│   ├── DESIGN.md (this file)
│   └── OPERATIONS.md
└── docker-compose.yml
```

---

## 2. データベース設計

### 2.1 ER概要

```
accounts (1) ─── (N) prospects
accounts (1) ─── (N) dm_logs
accounts (1) ─── (N) post_schedules
accounts (1) ─── (N) safety_events
prospects (1) ── (N) dm_logs
dm_templates (1) ─ (N) dm_logs
```

### 2.2 テーブル定義

#### 2.2.1 `accounts`

SaaS展開を見越してマルチテナント対応。

```sql
CREATE TABLE accounts (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  store_name         VARCHAR(100) NOT NULL COMMENT '店舗名(例: うなら)',
  ig_username        VARCHAR(50)  NOT NULL UNIQUE,
  ig_session_path    VARCHAR(255) NOT NULL COMMENT 'Instagrapiセッションファイルのパス',
  proxy_url          VARCHAR(255) NOT NULL COMMENT 'http://user:pass@host:port',
  daily_dm_limit     SMALLINT UNSIGNED NOT NULL DEFAULT 5
                     COMMENT '初期5、ウォームアップ後段階的に20まで',
  daily_follow_limit SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  daily_like_limit   SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  status             ENUM('active','paused','banned','warning') NOT NULL DEFAULT 'active',
  account_age_days   INT UNSIGNED NULL COMMENT 'IGアカウント作成からの経過日数',
  timezone           VARCHAR(50)  NOT NULL DEFAULT 'Asia/Tokyo',
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_status (status)
);
```

#### 2.2.2 `prospects`

```sql
CREATE TABLE prospects (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  account_id      BIGINT UNSIGNED NOT NULL,
  ig_user_id      VARCHAR(50)  NOT NULL COMMENT 'Instagram内部ユーザーID',
  ig_username     VARCHAR(50)  NOT NULL,
  full_name       VARCHAR(100) NULL,
  bio             TEXT         NULL,
  follower_count  INT UNSIGNED NOT NULL DEFAULT 0,
  following_count INT UNSIGNED NOT NULL DEFAULT 0,
  post_count      INT UNSIGNED NOT NULL DEFAULT 0,
  detected_lang   VARCHAR(10)  NULL COMMENT 'ISO 639-1: en, zh, ko, th, fr, es ...',
  source_hashtag  VARCHAR(100) NULL COMMENT '抽出元ハッシュタグ',
  source_post_url VARCHAR(255) NULL,
  is_tourist      BOOLEAN NOT NULL DEFAULT FALSE COMMENT '観光客判定結果',
  tourist_score   TINYINT UNSIGNED NULL COMMENT '観光客スコア 0-100',
  status          ENUM('new','queued','dm_sent','replied','skipped','blacklisted')
                  NOT NULL DEFAULT 'new',
  found_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  dm_sent_at      TIMESTAMP NULL,
  replied_at      TIMESTAMP NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_account_iguser (account_id, ig_user_id),
  INDEX idx_status_account (account_id, status),
  INDEX idx_tourist_score (account_id, tourist_score DESC),
  FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
);
```

#### 2.2.3 `dm_templates`

```sql
CREATE TABLE dm_templates (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  account_id   BIGINT UNSIGNED NOT NULL,
  language     VARCHAR(10)  NOT NULL COMMENT 'ISO 639-1',
  template     TEXT         NOT NULL COMMENT 'プレースホルダ: {username}, {store_name}',
  active       BOOLEAN NOT NULL DEFAULT TRUE,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_account_lang (account_id, language),
  FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
);
```

#### 2.2.4 `dm_logs`

```sql
CREATE TABLE dm_logs (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  account_id      BIGINT UNSIGNED NOT NULL,
  prospect_id     BIGINT UNSIGNED NOT NULL,
  template_id     BIGINT UNSIGNED NULL,
  language        VARCHAR(10)  NOT NULL,
  message_sent    TEXT         NOT NULL COMMENT 'AI生成後の最終文面',
  status          ENUM('queued','sent','failed','rate_limited','blocked')
                  NOT NULL DEFAULT 'queued',
  error_message   TEXT NULL,
  sent_at         TIMESTAMP NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_account_sent (account_id, sent_at),
  INDEX idx_status (status),
  FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
  FOREIGN KEY (prospect_id) REFERENCES prospects(id) ON DELETE CASCADE,
  FOREIGN KEY (template_id) REFERENCES dm_templates(id) ON DELETE SET NULL
);
```

#### 2.2.5 `post_schedules`

```sql
CREATE TABLE post_schedules (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  account_id      BIGINT UNSIGNED NOT NULL,
  type            ENUM('feed','story') NOT NULL,
  image_path      VARCHAR(255) NOT NULL COMMENT '事前アップロード済み画像パス',
  caption         TEXT NULL,
  text_overlay    JSON NULL COMMENT 'ストーリーズ用テキスト合成設定',
  scheduled_at    TIMESTAMP NOT NULL,
  posted_at       TIMESTAMP NULL,
  ig_media_id     VARCHAR(50) NULL,
  status          ENUM('scheduled','posting','posted','failed')
                  NOT NULL DEFAULT 'scheduled',
  error_message   TEXT NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_scheduled (status, scheduled_at),
  FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
);
```

#### 2.2.6 `safety_events`

BAN兆候の記録用。

```sql
CREATE TABLE safety_events (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  account_id   BIGINT UNSIGNED NOT NULL,
  event_type   ENUM('challenge_required','login_failed','rate_limited',
                    'feedback_required','action_blocked','checkpoint',
                    'auto_paused','manual_resumed') NOT NULL,
  severity     ENUM('info','warning','critical') NOT NULL,
  details      JSON NULL,
  occurred_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_account_occurred (account_id, occurred_at DESC),
  FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
);
```

#### 2.2.7 `hashtag_watchlist`

監視対象ハッシュタグ管理。

```sql
CREATE TABLE hashtag_watchlist (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  account_id  BIGINT UNSIGNED NOT NULL,
  hashtag     VARCHAR(100) NOT NULL COMMENT '#不要',
  language    VARCHAR(10) NULL,
  priority    TINYINT UNSIGNED NOT NULL DEFAULT 5 COMMENT '1-10、高いほど優先',
  active      BOOLEAN NOT NULL DEFAULT TRUE,
  last_scraped_at TIMESTAMP NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_account_hashtag (account_id, hashtag),
  FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
);
```

初期データ投入対象ハッシュタグ:

| ハッシュタグ | 言語 | 優先度 |
|---|---|---|
| asakusa | en | 10 |
| 浅草 | ja | 9 |
| sensoji | en | 9 |
| 浅草寺 | ja | 8 |
| asakusatemple | en | 8 |
| tokyotrip | en | 7 |
| japantrip | en | 7 |
| 淺草 | zh-tw | 8 |
| 浅草旅行 | zh-cn | 7 |
| 아사쿠사 | ko | 8 |
| 센소지 | ko | 7 |
| asakusafood | en | 7 |
| unagi | en | 6 |
| japanfood | en | 5 |

---

## 3. 機能仕様

### 3.1 観光客抽出機能

#### 3.1.1 処理フロー

```
[Cron: 1時間毎]
    ↓
ScrapeProspectsJob ディスパッチ(優先度高ハッシュタグから順に)
    ↓
[Python Worker]
    ↓
1. ハッシュタグ検索(直近48時間以内の投稿、最大50件)
    ↓
2. 投稿者プロフィール取得
    ↓
3. 観光客スコアリング(下記アルゴリズム)
    ↓
4. tourist_score >= 60 のみ prospects テーブルにINSERT
    ↓
5. 重複チェック(account_id + ig_user_id でUNIQUE)
```

#### 3.1.2 観光客スコアリングアルゴリズム

```python
def calculate_tourist_score(user_info, recent_posts) -> int:
    score = 0

    # フォロワー数(必須条件: 3000以上)
    if user_info.follower_count < 3000:
        return 0
    if user_info.follower_count >= 10000:
        score += 20
    elif user_info.follower_count >= 3000:
        score += 10

    # プロフィール文の言語(日本語以外で加点)
    detected = detect_language(user_info.bio)
    if detected and detected != 'ja':
        score += 30

    # フルネームの言語(日本語以外で加点)
    if not is_japanese_name(user_info.full_name):
        score += 15

    # 直近投稿の位置情報多様性(複数国/都市 = 旅行者)
    locations = extract_locations(recent_posts)
    unique_countries = count_unique_countries(locations)
    if unique_countries >= 2:
        score += 20
    elif unique_countries == 1 and 'Japan' in locations:
        score += 10

    # 直近投稿に旅行関連タグ
    travel_keywords = ['trip', 'travel', 'vacation', 'tokyo', 'japan',
                       '旅行', '여행', '旅行', 'voyage']
    if has_keywords_in_recent_posts(recent_posts, travel_keywords):
        score += 15

    # ペナルティ: 日本語の投稿が直近で多い → 在日の可能性
    if japanese_post_ratio(recent_posts) > 0.5:
        score -= 30

    return max(0, min(100, score))
```

判定閾値: `tourist_score >= 60` を観光客とみなす。

#### 3.1.3 抽出レート制限

| 項目 | 上限 |
|---|---|
| ハッシュタグ検索 | 1時間あたり10タグまで |
| プロフィール取得 | 1時間あたり50ユーザーまで |
| 連続実行間の待機 | 30秒〜120秒のランダム |

### 3.2 自動DM送信機能

#### 3.2.1 処理フロー

```
[Cron: 平日のみ 9:00〜21:00 の間で30分毎にチェック]
    ↓
SendDmJob ディスパッチ
    ↓
1. 当日送信数チェック(daily_dm_limit未満か)
    ↓
2. prospects から status='new' AND tourist_score>=60 を1件取得
   (tourist_score DESC, found_at ASC順)
    ↓
3. 文面生成
   a. dm_templates から相手言語のテンプレ取得
   b. 該当言語テンプレなければ英語にフォールバック
   c. Claude APIでバリエーション生成(後述プロンプト)
    ↓
4. 送信前安全チェック
   - safety_events に直近1時間の critical イベントなし
   - 前回送信から3〜15分経過(ランダム待機)
    ↓
5. Python Workerで送信
    ↓
6. dm_logs にINSERT、prospects.status='dm_sent'更新
    ↓
7. ノイズ動作実行(50%確率): 関係ない投稿を3〜5件閲覧+1件いいね
```

#### 3.2.2 DM文面生成プロンプト(Claude API)

```
SYSTEM:
You are writing a friendly, casual Instagram DM from a traditional unagi
(grilled eel) restaurant in Asakusa, Tokyo, to a tourist. The DM must:
- Be written in {language}
- Sound like a real human staff member, not a bot
- Be 2-4 sentences (max 60 words)
- Mention the restaurant name "Unara" naturally
- Include a small offer (e.g., free appetizer with DM screenshot)
- NOT contain URLs (URLs trigger Instagram spam filters)
- NOT use generic phrases like "We hope you enjoy your stay"
- Vary the wording each time (do not produce identical messages)

Restaurant info:
- Name: Unara (うなら)
- Location: Near Sensoji Temple, Asakusa
- Specialty: Traditional charcoal-grilled unagi (eel)

USER:
Generate a DM for an Instagram user named @{username}.
Their bio: "{bio}"
Their recent post topic: "{recent_post_caption_summary}"

Return ONLY the DM text, no preamble.
```

サポート言語: en, zh-cn, zh-tw, ko, th, fr, es, de, it, pt(優先度順)

#### 3.2.3 送信レート制限(必須・絶対緩和不可)

```python
DAILY_DM_LIMITS = {
    'warmup_week_1': 5,    # アカウント有効化から1週間目
    'warmup_week_2': 10,   # 2週間目
    'warmup_week_3': 15,   # 3週間目
    'normal': 20,          # 4週間目以降
}

# 送信間隔
MIN_INTERVAL_SEC = 180   # 3分
MAX_INTERVAL_SEC = 900   # 15分

# 送信可能時間帯(JST)
ACTIVE_HOURS = (9, 21)   # 9:00 - 20:59
ACTIVE_DAYS = [0, 1, 2, 3, 4]  # 月-金(0=月曜)
```

### 3.3 自動投稿機能

#### 3.3.1 フィード投稿

- 頻度: 週1回(デフォルト水曜日 12:00 JST、設定可)
- 画像: 事前にダッシュボードからアップロード済みのものをスケジュール
- 画像保存先: VPSローカル `/home/unara/storage/images/{account_id}/{yyyy}/{mm}/` (SaaS化時にS3移行)
- キャプション: 事前入力 or AIで生成(オプション)
- 実装: Instagrapi `client.photo_upload()`

#### 3.3.2 ストーリーズ投稿

- 平日: 1日10件、9:00〜21:00の間にランダム時刻で配信
- 土日: 1日10件、同時間帯
- 画像: 事前アップロード済みプール からランダム選択
- 実装: Instagrapi `client.photo_upload_to_story()`

**テキストオーバーレイ機能はPhase 6以降のオプション扱い**。発注者の指示「画像生成不要・テンプレベース」より、初期実装では**事前作成済み完成画像をそのまま投稿**する方式を採用。テキスト合成が必要になった段階で以下のJSON仕様で追加実装する。

```json
{
  "template_name": "daily_special",
  "overlays": [
    {
      "type": "text",
      "text": "Today's Special",
      "font": "NotoSans-Bold.ttf",
      "size": 80,
      "color": "#FFFFFF",
      "position": {"x": 540, "y": 200},
      "align": "center"
    }
  ]
}
```

### 3.4 管理ダッシュボード

#### 3.4.1 画面一覧

| 画面 | パス | 機能 |
|---|---|---|
| ログイン | `/login` | メール+パスワード認証 |
| ダッシュボード | `/` | 本日のサマリー、KPI |
| 候補リスト | `/prospects` | 観光客候補一覧、手動承認/却下 |
| DMログ | `/dm-logs` | 送信履歴、返信状況 |
| 投稿スケジュール | `/posts` | 予約投稿管理、画像アップロード |
| テンプレート | `/templates` | DM文面テンプレ言語別管理 |
| ハッシュタグ | `/hashtags` | 監視タグ管理 |
| 安全イベント | `/safety` | BAN兆候ログ |
| 設定 | `/settings` | レート制限、稼働時間帯 |

#### 3.4.2 ダッシュボード KPI

- 本日の送信DM数 / 当日上限
- 本日の返信数(リアルタイム取得)
- 過去7日間のエンゲージメント推移グラフ
- 候補プールの残数(status='new')
- アカウントヘルススコア(後述、0-100)
- 直近24時間の安全イベント

#### 3.4.3 アカウントヘルススコア算出

```
base = 100
- 直近24時間に rate_limited イベント発生: -10/件
- 直近24時間に action_blocked イベント発生: -30/件
- 直近24時間に challenge_required イベント発生: -50/件
- 直近24時間に feedback_required イベント発生: -40/件
- DM返信率が前週比で50%以上低下: -20

ヘルススコア < 50: 自動で daily_dm_limit を半減
ヘルススコア < 30: 自動で全アクション停止+通知
```

---

## 4. BAN対策・安全装置(最重要)

### 4.1 必須実装事項

以下は実装漏れがあった場合、即座にBANにつながる項目。**絶対に省略しないこと**。

#### 4.1.1 プロキシ固定(スティッキーセッション必須)

```python
# accounts.proxy_url を必ず使用。直接接続禁止
client = Client()
client.set_proxy(account.proxy_url)
```

同一アカウントは常に同一プロキシIPから接続。プロキシ切り替えはBAN直行。

**Bright Data使用時の重要設定**: 標準ではリクエストごとにIPがローテーションするため、**スティッキーセッション**を必ず有効化する。

```
# proxy_url の例(session-XXXXX 部分でセッション固定)
http://brd-customer-hl_xxx-zone-residential-session-{account_id}_{timestamp}:PASSWORD@brd.superproxy.io:22225
```

セッションIDは `account_id` ごとに固有値を生成し、最低24時間は同一IPに固定する。session_idが変わると同一アカウントから別IPでアクセスしたことになり、即チャレンジ要求が来る。

#### 4.1.2 セッション永続化

```python
# 毎回ログインしない。セッションファイルを再利用
SESSION_PATH = f"/storage/sessions/{account.id}.json"

if os.path.exists(SESSION_PATH):
    client.load_settings(SESSION_PATH)
    client.login(USERNAME, PASSWORD)  # セッション有効ならクッキー使われる
else:
    client.login(USERNAME, PASSWORD)
    client.dump_settings(SESSION_PATH)
```

#### 初回セッション生成手順(必須・サーバー上で実行禁止)

新規アカウント追加時、Instagramはほぼ確実にチャレンジ(メール/SMS認証)を要求する。これをサーバー上の自動スクリプトで通すのは困難なため、**初回ログインは必ずローカルマシン(Kouki様のPC)から手動で実施**する。

手順:

```bash
# 1. ローカルでInstagrapiインストール
pip install instagrapi

# 2. 対話的セッション生成スクリプトを実行
python scripts/generate_session.py
# - usernameとpasswordを入力
# - チャレンジ要求が来たらメールに届いたコードを入力
# - 完了後 ./{username}_session.json が生成される

# 3. 生成された session.json を本番サーバーにアップロード
scp ./unara_session.json unara@server:/home/unara/storage/sessions/1.json

# 4. accounts.ig_session_path に絶対パスを登録
```

セッションファイルは**最低でも30日間は再生成不要**。失効した場合のみ同手順で再生成する。

#### 4.1.3 デバイス情報固定

```python
# 同一アカウントは常に同一デバイスとして振る舞う
# Instagrapiのデフォルトでセッション保存時にデバイス情報も保存される
# 手動でデバイス情報を変更しないこと
```

#### 4.1.4 アクション間隔のランダム化

```python
import random
import time

def human_delay(min_sec=3, max_sec=15):
    time.sleep(random.uniform(min_sec, max_sec))

# あらゆるInstagram APIコール後に必ず呼ぶ
```

#### 4.1.5 例外検知時の即停止

```python
from instagrapi.exceptions import (
    ChallengeRequired, LoginRequired, FeedbackRequired,
    PleaseWaitFewMinutes, RateLimitError
)

try:
    client.direct_send(message, [user_id])
except ChallengeRequired:
    log_safety_event('challenge_required', 'critical')
    pause_account(account_id)
    notify_admin()
    raise
except FeedbackRequired:
    log_safety_event('feedback_required', 'critical')
    pause_account(account_id)
    raise
except (PleaseWaitFewMinutes, RateLimitError):
    log_safety_event('rate_limited', 'warning')
    delay_minutes = random.randint(30, 120)
    schedule_resume(account_id, delay_minutes)
    raise
```

#### 4.1.6 ノイズ動作

DMだけ送り続けるアカウントは即BAN。人間らしい行動パターンを混ぜる。

```python
def perform_noise_action(client):
    """50%の確率で実行されるダミー動作"""
    actions = [
        lambda: client.feed_timeline(amount=random.randint(3, 10)),
        lambda: like_random_post_in_feed(client),
        lambda: view_random_story(client),
        lambda: client.user_info_by_username(random_japanese_food_account()),
    ]
    if random.random() < 0.5:
        random.choice(actions)()
        human_delay(5, 30)
```

### 4.2 ウォームアップスケジュール

新規実装直後はアカウントの活動量を段階的に増やす。

| 経過 | DM/日 | フォロー/日 | いいね/日 | ストーリー/日 |
|---|---|---|---|---|
| Week 1 | 5 | 5 | 30 | 3 |
| Week 2 | 10 | 10 | 50 | 5 |
| Week 3 | 15 | 20 | 80 | 8 |
| Week 4+ | 20 | 30 | 100 | 10 |

実装: `accounts.daily_dm_limit` を週次で自動引き上げるscheduled commandを実装する(`php artisan schedule:run`)。

### 4.3 緊急停止条件(自動)

以下を検知したら全アクション即停止+管理者通知:

1. ChallengeRequired例外
2. FeedbackRequired例外
3. ログイン失敗が1時間以内に3回以上
4. ヘルススコア30未満
5. 24時間以内に safety_event の critical が3件以上

### 4.4 緊急停止からの復帰手順

完全自動復帰はしない。以下を運用代行スタッフが手動で実施:

1. ダッシュボードで停止理由を確認
2. ブラウザ/スマホアプリで実際にIGにログインし、チャレンジを通過
3. 24〜72時間放置(自動アクションなし)
4. ダッシュボードの「Resume」ボタンで再開
5. 再開後1週間は daily_dm_limit を5に戻す(再ウォームアップ)

---

## 5. API仕様(Laravel ↔ Next.js)

### 5.1 認証

- 方式: Laravel Sanctum(Bearer Token)
- ログインエンドポイント: `POST /api/auth/login`
- 全APIで `Authorization: Bearer {token}` ヘッダ必須

### 5.2 主要エンドポイント

```
GET    /api/dashboard/summary          ダッシュボード集計
GET    /api/accounts                   アカウント一覧
GET    /api/accounts/{id}              アカウント詳細
PATCH  /api/accounts/{id}              アカウント設定更新
POST   /api/accounts/{id}/pause        手動停止
POST   /api/accounts/{id}/resume       再開

GET    /api/prospects                  候補リスト(ページネーション)
PATCH  /api/prospects/{id}             status更新(承認/却下)
DELETE /api/prospects/{id}             ブラックリスト追加

GET    /api/dm-logs                    DM送信履歴
GET    /api/dm-templates               テンプレ一覧
POST   /api/dm-templates               テンプレ作成
PATCH  /api/dm-templates/{id}          テンプレ更新

GET    /api/posts                      投稿スケジュール一覧
POST   /api/posts                      投稿予約作成
POST   /api/posts/upload-image         画像アップロード(multipart)
DELETE /api/posts/{id}                 投稿予約削除

GET    /api/hashtags                   監視ハッシュタグ一覧
POST   /api/hashtags                   ハッシュタグ追加
DELETE /api/hashtags/{id}              削除

GET    /api/safety-events              安全イベント一覧
```

### 5.3 サンプルレスポンス: `GET /api/dashboard/summary`

```json
{
  "data": {
    "account_id": 1,
    "store_name": "うなら",
    "health_score": 85,
    "today": {
      "dm_sent": 12,
      "dm_limit": 20,
      "dm_replies": 2,
      "stories_posted": 7,
      "stories_planned": 10
    },
    "prospects_pool": {
      "new": 145,
      "queued": 8,
      "dm_sent_total": 1230,
      "replied_total": 47
    },
    "weekly_trend": [
      {"date": "2026-04-25", "sent": 18, "replies": 1},
      {"date": "2026-04-26", "sent": 19, "replies": 3}
    ],
    "recent_safety_events": []
  }
}
```

---

## 6. 環境構築手順

### 6.1 必要なアカウント・契約

| サービス | 用途 | 月額 |
|---|---|---|
| さくらVPS 2GB | サーバー | 1,738円 |
| Bright Data | 住宅用プロキシ | 約10,000円(8GB枠) |
| Anthropic API | DM文面生成 | 約3,000円 |
| Vercel Hobby | フロントエンド | 0円 |
| ドメイン(.com) | - | 約150円 |
| **合計** | | **約14,900円** |

### 6.2 .env(Laravel)

```bash
APP_NAME=Unara
APP_ENV=production
APP_KEY=base64:...
APP_DEBUG=false
APP_URL=https://api.unara.example.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=unara
DB_USERNAME=unara
DB_PASSWORD=

REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

QUEUE_CONNECTION=redis

# Python Worker連携
WORKER_PYTHON_PATH=/home/unara/worker/venv/bin/python
WORKER_SCRIPT_PATH=/home/unara/worker/main.py

# AI
ANTHROPIC_API_KEY=

# Sentry
SENTRY_LARAVEL_DSN=
```

### 6.3 .env(Python Worker)

```bash
DB_HOST=127.0.0.1
DB_PORT=3306
DB_USER=unara
DB_PASSWORD=
DB_NAME=unara

REDIS_HOST=127.0.0.1
REDIS_PORT=6379

INSTAGRAM_USERNAME=
INSTAGRAM_PASSWORD=

PROXY_URL=http://user:pass@brd.superproxy.io:22225

SENTRY_DSN=
LOG_LEVEL=INFO
```

### 6.4 セットアップコマンド

```bash
# Laravel
cd backend
composer install
php artisan key:generate
php artisan migrate
php artisan db:seed

# Worker
cd worker
python3.11 -m venv venv
source venv/bin/activate
pip install -r requirements.txt

# Frontend
cd frontend
npm install
npm run build
```

#### CORS設定(必須)

VercelのフロントエンドからさくらVPSのLaravel APIを叩くため、CORS設定が必要。`backend/config/cors.php`:

```php
return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => [
        env('FRONTEND_URL', 'https://unara.vercel.app'),
        'http://localhost:3000', // 開発用
    ],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];
```

Laravel Sanctum使用のため `SANCTUM_STATEFUL_DOMAINS` も設定:

```bash
SANCTUM_STATEFUL_DOMAINS=unara.vercel.app,localhost:3000
SESSION_DOMAIN=.unara.example.com
```

### 6.5 cron設定(VPS)

```cron
# Laravelスケジューラ(投稿、ウォームアップ調整等)
* * * * * cd /home/unara/backend && php artisan schedule:run >> /dev/null 2>&1

# Queue Worker (supervisor推奨)
# /etc/supervisor/conf.d/unara-worker.conf:
# [program:unara-worker]
# command=php /home/unara/backend/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
# autostart=true
# autorestart=true
# user=unara
# numprocs=2
```

### 6.6 supervisord(Python Worker)

```ini
[program:unara-py-worker]
command=/home/unara/worker/venv/bin/python /home/unara/worker/main.py
directory=/home/unara/worker
autostart=true
autorestart=true
user=unara
stdout_logfile=/var/log/unara/worker.log
stderr_logfile=/var/log/unara/worker.error.log
environment=PYTHONUNBUFFERED=1
```

---

## 7. テスト戦略

### 7.1 ユニットテスト(必須)

| 対象 | テスト内容 |
|---|---|
| ProspectFilterService | スコアリングロジック(各加点・減点パターン) |
| DmGeneratorService | 言語別テンプレ取得、フォールバック動作 |
| 安全装置(SafetyGuard) | レート制限判定、緊急停止条件 |
| API Controllers | 認証、権限、バリデーション |

#### Instagrapiモック例(Python)

```python
from unittest.mock import patch, MagicMock

@patch('worker.src.instagram_client.Client')
def test_send_dm_success(mock_client_class):
    mock_client = MagicMock()
    mock_client.direct_send.return_value = MagicMock(id='msg_123')
    mock_client_class.return_value = mock_client

    sender = DmSender(account_id=1)
    result = sender.send('user_id', 'Hi!')

    assert result['status'] == 'success'
    mock_client.direct_send.assert_called_once()
```

### 7.2 統合テスト

- Instagrapi をモックし、ジョブが正しく状態遷移するか
- Queue → Worker → DB更新の一連の流れ

### 7.3 ロギング規約

| イベント | レベル | 出力先 |
|---|---|---|
| DM送信成功 | INFO | dm_logs + アプリログ |
| DM送信失敗(一時) | WARNING | safety_events + Sentry |
| Challenge検知 | ERROR | safety_events + Sentry + Slack通知 |
| アカウント停止 | ERROR | safety_events + Sentry + Slack通知 |
| ヘルススコア低下 | WARNING | safety_events + Slack通知 |
| 通常API呼び出し | DEBUG | アプリログのみ(本番は出力しない) |

Slack通知は Webhook URL を `.env` に設定し、`severity='critical'` の safety_event 発生時に自動投稿する。

### 7.4 観光客スコア閾値のチューニング

`tourist_score >= 60` は初期値。**運用開始後1ヶ月で以下の指標を見て調整する**:

- 閾値を下げる: 候補プールが枯渇する場合(prospects.status='new' が常に少ない)
- 閾値を上げる: DM返信率が1%未満の場合(質が低い)

調整は `config/scoring.php` の定数で管理し、コード変更なしで切替可能にする。

### 7.5 本番手動テスト(Phase 6)

新規ステージング用アカウントは作らず、うなら本番アカウントで以下を段階実施:

1. Day 1-3: ストーリーズ投稿のみ手動承認モードで実行
2. Day 4-7: フィード投稿を1回実施
3. Day 8-14: DM送信を1日3件、手動承認モードで実施
4. Day 15-21: DM送信を1日5件、自動モードに切替
5. Day 22-: ウォームアップスケジュールに従い段階引き上げ

各段階で safety_events に critical が出ないことを確認してから次段階へ。

---

## 8. SaaS展開時の追加考慮(参考)

本MVPでは実装しないが、設計上以下を見越しておく:

- マルチテナント: 全テーブルに `account_id` を持たせ済み(対応済み)
- 課金: Stripe Subscription、月額3万〜5万円帯
- アカウントごとのプロキシ割当: 1アカウント = 1プロキシIP厳守
- 利用規約: BAN時免責、ベストエフォート明記
- 顧客サポート用Slackチャンネル

---

## 9. リスクと対応

| リスク | 発生確率 | 影響度 | 対応 |
|---|---|---|---|
| アカウントBAN | 中 | 致命的 | ウォームアップ、ノイズ動作、緊急停止装置 |
| プロキシ料金高騰 | 低 | 中 | 代替プロキシ業者(Smartproxy/IPRoyal)に切替可能な抽象化 |
| Instagrapi破壊的変更 | 中 | 大 | バージョン固定、定期的な互換性チェック |
| 訴訟リスク(GDPR等) | 低 | 大 | DM送信ログ保存、要請があれば即停止する運用フロー |
| 返信率が想定以下 | 高 | 中 | 文面ABテスト機能、ハッシュタグ見直しサイクル |
| Claude APIコスト超過 | 中 | 小 | 1日のAPIコール上限を設定、超過時はテンプレ直接使用にフォールバック |

## 9.1 セキュリティ要件

- `accounts.proxy_url`、Instagram認証情報は **Laravel `Crypt::encryptString()` で暗号化保存**
- セッションファイル `/home/unara/storage/sessions/*.json` は `chmod 600`、所有者のみ読書き可
- `.env` ファイルは git管理外、本番デプロイ時は手動配置
- データベースバックアップは暗号化(`mysqldump | openssl enc -aes-256-cbc`)
- API通信は全てHTTPS必須

## 9.2 データ保持ポリシー

| データ種別 | 保持期間 | 削除方法 |
|---|---|---|
| `prospects` (status='new') | 30日 | scheduled commandで自動削除 |
| `prospects` (status='dm_sent') | 365日 | 同上 |
| `dm_logs` | 365日 | 同上 |
| `safety_events` | 90日 | 同上 |
| Instagramセッションファイル | 失効まで | 失効検知時に自動削除 |
| 投稿済み画像 | 永続 | 手動削除のみ |

GDPR/個人情報保護法対応として、**EU圏ユーザーから削除要請があった場合は24時間以内に該当ユーザーのprospects/dm_logsを物理削除する手動運用フローを整備**する。

---

## 10. 完了条件(Definition of Done)

各Phaseは以下を満たしたら完了とみなす:

- [ ] 該当機能のユニットテストが通る(カバレッジ60%以上)
- [ ] 本設計書に記載の機能が動作する
- [ ] BAN対策の必須実装事項が漏れなく入っている
- [ ] 環境変数が `.env.example` に追記されている
- [ ] 該当機能のREADMEが更新されている
- [ ] 発注者への動作デモ完了

---

## 付録A: 用語集

| 用語 | 説明 |
|---|---|
| Instagrapi | Pythonの非公式Instagram APIクライアントOSS |
| 住宅用プロキシ | 一般家庭の回線を経由するプロキシ。検知されにくい |
| スティッキーセッション | プロキシで同一IPを一定時間固定する設定 |
| ウォームアップ | 新規アクションを段階的に増やしてBANを回避する運用 |
| ノイズ動作 | DM以外の人間らしい行動(閲覧、いいね等) |
| シャドウバン | アカウント削除はされないが投稿が他ユーザーに表示されにくくなる制裁 |
| ヘルススコア | アカウントの健全性を0-100で示す独自指標 |

## 付録B: DMテンプレート初期データ

各言語の初期テンプレート(Seederで投入)。Claude APIでバリエーション生成する際のベースとなる。

### 英語(en)

```
Hi {username}! Welcome to Asakusa 🇯🇵
We're Unara, a small unagi (eel) restaurant near Sensoji.
Show this DM at our entrance for a free appetizer 🍶
Hope to see you soon!
```

### 中国語簡体(zh-cn)

```
你好 {username}!欢迎来到浅草 🇯🇵
我们是浅草寺附近的鳗鱼料理店「うなら」
出示此私信即可获赠一份小菜 🍶
期待您的光临!
```

### 中国語繁体(zh-tw)

```
您好 {username}!歡迎來到淺草 🇯🇵
我們是淺草寺附近的鰻魚料理店「うなら」
出示此私訊即可獲贈一份小菜 🍶
期待您的光臨!
```

### 韓国語(ko)

```
안녕하세요 {username}님! 아사쿠사에 오신 것을 환영합니다 🇯🇵
센소지 근처의 장어 요리 전문점 우나라입니다.
입구에서 이 DM을 보여주시면 사이드 메뉴를 무료로 드립니다 🍶
방문을 기다리고 있겠습니다!
```

### タイ語(th)

```
สวัสดีค่ะ {username}! ยินดีต้อนรับสู่อาซากุสะ 🇯🇵
เราคือร้านปลาไหลย่าง Unara ใกล้วัดเซ็นโซจิ
แสดง DM นี้ที่หน้าร้าน รับของแถมฟรี 1 จาน 🍶
รอพบคุณค่ะ!
```

### フランス語(fr)

```
Bonjour {username} ! Bienvenue à Asakusa 🇯🇵
Nous sommes Unara, un restaurant traditionnel d'anguille (unagi) près de Sensoji.
Présentez ce DM à l'entrée pour recevoir une entrée offerte 🍶
À très bientôt !
```

### スペイン語(es)

```
¡Hola {username}! Bienvenido a Asakusa 🇯🇵
Somos Unara, un restaurante tradicional de anguila (unagi) cerca de Sensoji.
Muestra este DM en la entrada y recibe un aperitivo gratis 🍶
¡Esperamos verte pronto!
```

**注意**: テンプレートには **URLを含めない**(IGスパムフィルタ発動防止)。場所は口頭で案内する想定。

## 付録C: 参考リンク

- Instagrapi公式: https://github.com/subzeroid/instagrapi
- Bright Data: https://brightdata.com/
- Laravel 11: https://laravel.com/docs/11.x
- Next.js 14: https://nextjs.org/docs

---

**END OF DOCUMENT**
