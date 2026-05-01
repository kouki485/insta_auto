"""BAN 対策の安全装置 (Phase 2 では基盤、Phase 4 で完成).

設計書 §4.1.4 (アクション間隔ランダム化) と §4.1.5 (例外検知時の即停止) の最小実装。
DB / safety_events への書き込みは Phase 4 の SafetyGuard で実装する。
"""

from __future__ import annotations

import logging
import random
import time
from dataclasses import dataclass
from typing import Iterable

logger = logging.getLogger(__name__)


@dataclass(frozen=True)
class HumanDelay:
    """time.sleep を抽象化してテスト可能にする."""

    min_sec: float = 3.0
    max_sec: float = 15.0

    def sleep(self) -> float:
        seconds = random.uniform(self.min_sec, self.max_sec)
        time.sleep(seconds)
        return seconds


def jittered_delay(min_sec: float, max_sec: float) -> float:
    """設計書 §4.1.4 human_delay 相当のランダム待機."""
    return HumanDelay(min_sec=min_sec, max_sec=max_sec).sleep()


_INSTAGRAPI_EXCEPTION_MAP: dict[str, tuple[str, str]] = {
    "ChallengeRequired": ("challenge_required", "critical"),
    "FeedbackRequired": ("feedback_required", "critical"),
    "LoginRequired": ("login_failed", "warning"),
    "BadPassword": ("login_failed", "critical"),
    "PleaseWaitFewMinutes": ("rate_limited", "warning"),
    "RateLimitError": ("rate_limited", "warning"),
    "ProxyAddressIsBlocked": ("action_blocked", "critical"),
}


def classify_instagrapi_exception(exc: BaseException) -> tuple[str, str]:
    """instagrapi 例外を (event_type, severity) に変換する.

    Phase 2 では post 発行時のみ呼び出される. Phase 4 で DM/scrape にも展開する.

    instagrapi 由来でない例外 (FileNotFoundError 等) は ("internal_error", "info") を返す.
    Phase 4 の safety_events 自動 critical 連携での誤検知を避けるため.
    """
    name = type(exc).__name__
    if name in _INSTAGRAPI_EXCEPTION_MAP:
        return _INSTAGRAPI_EXCEPTION_MAP[name]
    module = type(exc).__module__ or ""
    if module.startswith("instagrapi"):
        return ("action_blocked", "warning")
    return ("internal_error", "info")


def critical_exception_names() -> Iterable[str]:
    return ("ChallengeRequired", "FeedbackRequired", "BadPassword", "ProxyAddressIsBlocked")
