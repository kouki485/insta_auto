"""Instagram の初回セッション生成スクリプト (ローカル PC で実行).

設計書 §4.1.2 「初回セッション生成手順」をそのまま実装する。
チャレンジ要求(メール / SMS)を対話的に通過し、セッションファイルを
``./sessions/<username>.json`` に保存する。
"""

from __future__ import annotations

import getpass
import os
import sys
from pathlib import Path

from dotenv import load_dotenv

try:
    from instagrapi import Client
    from instagrapi.exceptions import (
        BadPassword,
        ChallengeRequired,
        TwoFactorRequired,
    )
except ImportError as exc:  # pragma: no cover - 開発者向けメッセージ
    sys.stderr.write(
        "instagrapi が見つかりません。`pip install instagrapi` を先に実施してください。\n"
    )
    raise SystemExit(1) from exc

SCRIPT_DIR = Path(__file__).resolve().parent
SESSIONS_DIR = SCRIPT_DIR.parent / "sessions"


def main() -> int:
    load_dotenv(SCRIPT_DIR.parent / ".env")
    SESSIONS_DIR.mkdir(parents=True, exist_ok=True)

    username = os.getenv("INSTAGRAM_USERNAME") or input("Instagram username: ").strip()
    password = os.getenv("INSTAGRAM_PASSWORD") or getpass.getpass("Instagram password: ")
    proxy_url = os.getenv("PROXY_URL", "").strip()

    if not username or not password:
        sys.stderr.write("username / password が空です。中断します。\n")
        return 1

    session_path = SESSIONS_DIR / f"{username}.json"

    client = Client()
    if proxy_url:
        print(f"[info] using proxy: {_mask_proxy(proxy_url)}")
        client.set_proxy(proxy_url)
    else:
        print("[warn] PROXY_URL が未設定です。本番運用では必ず住宅用プロキシを設定してください。")

    if session_path.exists():
        print(f"[info] 既存セッション {session_path} を読み込みます。")
        client.load_settings(str(session_path))

    try:
        client.login(username, password)
    except TwoFactorRequired:
        code = input("2FA コードを入力: ").strip()
        client.login(username, password, verification_code=code)
    except ChallengeRequired:
        print("[info] チャレンジが要求されました。メール/SMS のコードを入力してください。")
        code = input("チャレンジコード(空 Enter で自動 resolve を試行): ").strip()
        try:
            if code:
                client.challenge_code_handler(username, code)
            else:
                client.challenge_resolve(client.last_json)
        except AttributeError:
            sys.stderr.write(
                "[error] instagrapi のバージョンが異なる可能性があります。手動でコード入力フローを確認してください。\n"
            )
            return 2
    except BadPassword:
        sys.stderr.write("[error] パスワードが誤っています。\n")
        return 3

    client.dump_settings(str(session_path))
    session_path.chmod(0o600)
    print(f"[ok] セッションを保存しました: {session_path}")
    print("    本番サーバーへは scp で転送し、accounts.ig_session_path に登録してください。")
    return 0


def _mask_proxy(url: str) -> str:
    """user:pass を伏せた状態で proxy URL を返す."""
    if "@" not in url:
        return url
    schema_part, host_part = url.split("@", 1)
    return f"{schema_part.split(':')[0]}://***:***@{host_part}"


if __name__ == "__main__":
    sys.exit(main())
