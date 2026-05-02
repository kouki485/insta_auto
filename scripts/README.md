# scripts/

Instagrapi の初回セッション生成と疎通検証のためのローカル実行スクリプト群。
**サーバー上で実行しないこと。** 設計書 §4.1.2 を参照。

## 前提

- Python 3.10+
- 依存パッケージ: `instagrapi` (リポジトリの worker 側依存と一致させてある)

セットアップ例:

```bash
cd worker
python3.10 -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt
cd ..
```

## generate_session.py

Instagram への初回ログインを対話で実施し、セッションファイル
`./sessions/<username>.json` を生成する。チャレンジ要求 (メール / SMS) が来た場合は
プロンプトに従ってコードを入力する。

```bash
python scripts/generate_session.py
```

完了後、生成された JSON を本番サーバーへ転送する想定:

```bash
scp ./sessions/your_ig_account.json deploy@server:/srv/instaauto/storage/sessions/1.json
ssh deploy@server "chmod 600 /srv/instaauto/storage/sessions/1.json"
```

`accounts.ig_session_path` に `/storage/sessions/1.json` を登録する。

## smoke_test_instagrapi.py

生成済みセッションを使って `account_info()` を呼ぶ疎通確認スクリプト。
プロキシ未設定の場合はオプション化されているが、本番運用では必ず Bright Data の
sticky session URL を `.env` の `PROXY_URL` に設定する。

```bash
python scripts/smoke_test_instagrapi.py
```

## 重要事項

- セッションファイルは git にコミット禁止 (`.gitignore` で `sessions/` を除外済み)
- 同じアカウントに対して **異なる IP** から繰り返しログインすると Challenge が発生する
- 一度生成したセッションは最低 30 日は再生成不要 (失効した場合のみ再実施)
