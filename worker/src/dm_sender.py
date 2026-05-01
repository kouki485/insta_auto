"""DM 送信モジュール (設計書 §3.2 / §4.1.5).

instagrapi の direct_send を呼び、例外は SafetyGuard で分類する.
"""

from __future__ import annotations

import logging
from dataclasses import dataclass

from src.instagram_client import InstagramClient
from src.safety_guard import SafetyGuard

logger = logging.getLogger(__name__)


@dataclass(frozen=True)
class DmOutcome:
    ig_message_id: str | None
    success: bool
    error: str | None = None


class DmSender:
    def __init__(self, client: InstagramClient, guard: SafetyGuard) -> None:
        self._client = client
        self._guard = guard

    def send(self, ig_user_id: str, message: str) -> DmOutcome:
        if not ig_user_id:
            return DmOutcome(ig_message_id=None, success=False, error="ig_user_id missing")
        if not message.strip():
            return DmOutcome(ig_message_id=None, success=False, error="message empty")

        # 設計書 §3.2.1 step 4: direct_send 直前にもランダム待機を 1 度入れる.
        self._client.delay()

        try:
            response = self._client.raw.direct_send(message, [ig_user_id])  # type: ignore[attr-defined]
        except Exception as exc:  # noqa: BLE001 - 例外は SafetyGuard 経由で分類する
            self._guard.record_exception(exc, context="direct_send")
            return DmOutcome(ig_message_id=None, success=False, error=f"{type(exc).__name__}: {exc}")

        message_id = self._extract_message_id(response)
        logger.info(
            "ig_dm_sent",
            extra={"ig_user_id": ig_user_id, "ig_message_id": message_id},
        )
        return DmOutcome(ig_message_id=message_id, success=True)

    @staticmethod
    def _extract_message_id(response: object) -> str | None:
        for attr in ("id", "thread_id", "item_id"):
            value = getattr(response, attr, None)
            if value:
                return str(value)
        if isinstance(response, dict):
            for key in ("id", "thread_id", "item_id"):
                if response.get(key):
                    return str(response[key])
        return None
