"""生成済みセッションでの Instagrapi 疎通検証スクリプト.

設計書 Phase 0 の完了条件「`account_info()` が取得できる」を確認するための
最小限のスモークテスト。プロキシが設定されていれば必ず経由する。
"""

from __future__ import annotations

import json
import os
import sys
from pathlib import Path

from dotenv import load_dotenv

try:
    from instagrapi import Client
    from instagrapi.exceptions import (
        BadPassword,
        ChallengeRequired,
        FeedbackRequired,
        LoginRequired,
        PleaseWaitFewMinutes,
        TwoFactorRequired,
    )
except ImportError as exc:  # pragma: no cover
    sys.stderr.write(
        "instagrapi が見つかりません。`pip install instagrapi` を先に実施してください。\n"
    )
    raise SystemExit(1) from exc

SCRIPT_DIR = Path(__file__).resolve().parent
SESSIONS_DIR = SCRIPT_DIR.parent / "sessions"


def main() -> int:
    load_dotenv(SCRIPT_DIR.parent / ".env")

    username = os.getenv("INSTAGRAM_USERNAME") or _prompt_username()
    password = os.getenv("INSTAGRAM_PASSWORD")
    proxy_url = os.getenv("PROXY_URL", "").strip()
    session_path = SESSIONS_DIR / f"{username}.json"

    if not session_path.exists():
        sys.stderr.write(
            f"[error] セッションファイルがありません: {session_path}\n"
            "        先に scripts/generate_session.py を実行してください。\n"
        )
        return 2

    client = Client()
    if proxy_url:
        print(f"[info] using proxy: {_mask_proxy(proxy_url)}")
        client.set_proxy(proxy_url)
    else:
        print("[warn] PROXY_URL 未設定。本番では必ず Bright Data sticky session を使うこと。")

    print(f"[info] セッション読み込み: {session_path}")
    client.load_settings(str(session_path))

    try:
        if password:
            client.login(username, password)
        info = client.account_info()
    except (ChallengeRequired, FeedbackRequired) as exc:
        sys.stderr.write(f"[critical] BAN兆候の検知: {type(exc).__name__}: {exc}\n")
        return 10
    except (LoginRequired, PleaseWaitFewMinutes) as exc:
        sys.stderr.write(
            f"[error] セッション失効 or レート制限: {type(exc).__name__}: {exc}\n"
        )
        return 11
    except BadPassword as exc:
        sys.stderr.write(f"[error] パスワード不一致: {exc}\n")
        return 13
    except TwoFactorRequired as exc:
        sys.stderr.write(
            f"[error] 2FA が要求されました。generate_session.py で再生成してください: {exc}\n"
        )
        return 14
    except Exception as exc:  # noqa: BLE001 - 疎通確認なので最終 catch を許容
        sys.stderr.write(f"[error] 予期しないエラー: {type(exc).__name__}: {exc}\n")
        return 12

    summary = {
        "username": info.username,
        "full_name": info.full_name,
        "follower_count": info.follower_count,
        "following_count": info.following_count,
        "media_count": info.media_count,
        "is_business": info.is_business,
    }
    print("[ok] account_info 取得成功")
    print(json.dumps(summary, ensure_ascii=False, indent=2))
    return 0


def _prompt_username() -> str:
    return input("Instagram username: ").strip()


def _mask_proxy(url: str) -> str:
    if "@" not in url:
        return url
    schema_part, host_part = url.split("@", 1)
    return f"{schema_part.split(':')[0]}://***:***@{host_part}"


if __name__ == "__main__":
    sys.exit(main())
