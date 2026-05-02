"""ローカル動作確認用の Instagrapi スタブクライアント.

LOCAL_MODE=true のとき InstagramClient の実体差し替えに使用する.
実際の Instagram API は叩かず, 成功レスポンスを返すだけのダミー.
プロキシもセッションも実 IG アカウントも不要でエンドツーエンド疎通を確認できる.
"""

from __future__ import annotations

import json
import logging
from dataclasses import dataclass
from pathlib import Path

logger = logging.getLogger(__name__)


@dataclass
class _StubMedia:
    pk: str = "stub_media_0"
    code: str = "STUB000"


class LocalStubClient:
    """instagrapi.Client の最低限のメソッドだけを模した no-op クライアント."""

    def set_proxy(self, url: str) -> None:
        logger.info("local_stub.set_proxy", extra={"url": url or "<none>"})

    def load_settings(self, path: str) -> None:
        logger.info("local_stub.load_settings", extra={"path": path})

    def login(self, username: str, password: str) -> bool:
        logger.info("local_stub.login", extra={"username": username})
        return True

    def dump_settings(self, path: str) -> None:
        target = Path(path)
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(json.dumps({"local_stub": True, "username": "stub"}))

    def photo_upload(self, path: str, caption: str) -> _StubMedia:
        logger.info("local_stub.photo_upload", extra={"path": path, "caption_len": len(caption)})
        return _StubMedia()

    def photo_upload_to_story(self, path: str) -> _StubMedia:
        logger.info("local_stub.photo_upload_to_story", extra={"path": path})
        return _StubMedia()

    def direct_send(self, text: str, user_ids: list[int]) -> dict:
        logger.info(
            "local_stub.direct_send",
            extra={"text_len": len(text), "user_count": len(user_ids)},
        )
        return {"thread_id": "stub_thread", "item_id": "stub_item"}

    def user_info_by_username(self, username: str):
        logger.info("local_stub.user_info_by_username", extra={"username": username})
        return type(
            "StubUser",
            (),
            {
                "pk": 1,
                "username": username,
                "full_name": f"Stub {username}",
                "biography": "local stub user",
                "media_count": 10,
                "follower_count": 100,
                "following_count": 200,
                "is_private": False,
            },
        )()
