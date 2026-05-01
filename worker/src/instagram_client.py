"""Instagrapi の Client を BAN 対策の安全装置で包んだラッパ.

設計書 §4.1.1〜4.1.4 の必須項目を Phase 2 から組み込む.
- プロキシ固定 (sticky session)
- セッションファイルの永続ロード
- アクション間隔のランダム化 (human_delay)

実 Instagram API は Phase 6 で叩く。Phase 2 では post_publisher のみが呼ぶ。
"""

from __future__ import annotations

import logging
import os
from dataclasses import dataclass
from pathlib import Path
from typing import Protocol

from src.safety import HumanDelay

logger = logging.getLogger(__name__)


class _InstagrapiClient(Protocol):  # pragma: no cover - 型ヒント用
    def set_proxy(self, url: str) -> None: ...

    def load_settings(self, path: str) -> None: ...

    def login(self, username: str, password: str) -> bool: ...

    def dump_settings(self, path: str) -> None: ...

    def photo_upload(self, path: str, caption: str) -> object: ...

    def photo_upload_to_story(self, path: str) -> object: ...


@dataclass
class AccountContext:
    """Laravel 側 accounts テーブル相当の値オブジェクト.

    将来的には DB 直結 / API 経由で取得する。Phase 2 ではジョブ payload と環境変数から組み立てる.
    """

    account_id: int
    username: str
    password: str
    proxy_url: str
    session_path: str


class InstagramClient:
    """instagrapi.Client + 安全装置のラッパ."""

    def __init__(
        self,
        context: AccountContext,
        client_factory=None,
        delay: HumanDelay | None = None,
    ) -> None:
        self._context = context
        self._delay = delay or HumanDelay()
        self._client = self._build_client(client_factory)

    @property
    def raw(self) -> _InstagrapiClient:
        return self._client

    def login(self) -> None:
        """セッションファイルを優先利用して login する.

        既存ありなら load_settings → login で再認証 (cookie 更新), 終わったら必ず dump_settings.
        新規ならファイルを作成。chmod 600 を毎回適用してパーミッション漂流を防ぐ.
        """
        session_path = Path(self._context.session_path)

        if session_path.exists():
            logger.info("ig_session_load", extra={"path": str(session_path)})
            self._client.load_settings(str(session_path))

        # session が無効ならば例外が飛ぶ。捕捉は呼び出し側で行う.
        self._client.login(self._context.username, self._context.password)
        self.delay()

        # 設計書 §4.1.2: セッション cookie は login 成功時に更新される。常に dump して反映する.
        session_path.parent.mkdir(parents=True, exist_ok=True)
        self._client.dump_settings(str(session_path))
        try:
            os.chmod(session_path, 0o600)
        except OSError as exc:
            logger.warning("session_chmod_failed", extra={"path": str(session_path), "error": str(exc)})

    def delay(self) -> float:
        return self._delay.sleep()

    def _build_client(self, client_factory) -> _InstagrapiClient:
        if client_factory is None:
            from instagrapi import Client  # local import で test 時 import 失敗を避ける

            client = Client()
        else:
            client = client_factory()

        # 設計書 §4.1.1: 直接接続は BAN 直行のため proxy 未設定なら必ず失敗させる.
        if not self._context.proxy_url:
            raise ValueError(
                f"proxy_url is required for account {self._context.account_id} "
                "(see DESIGN.md §4.1.1)"
            )
        client.set_proxy(self._context.proxy_url)
        return client
