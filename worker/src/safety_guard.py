"""SafetyGuard: 設計書 §4.3 緊急停止条件 + §4.1.5 例外即停止.

Worker は DB に直接書き込まず、以下を JobResult.result.safety_events として
Laravel 側 ProcessWorkerResults へ渡す。Laravel が safety_events 永続化と
auto_pause / Slack を実行する.
"""

from __future__ import annotations

import logging
from dataclasses import asdict, dataclass, field
from datetime import datetime, timezone
from typing import Any

from src.notifier import notify_slack
from src.safety import classify_instagrapi_exception

logger = logging.getLogger(__name__)


@dataclass
class SafetyEvent:
    event_type: str
    severity: str
    details: dict[str, Any] = field(default_factory=dict)
    occurred_at: str = field(
        default_factory=lambda: datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
    )


@dataclass
class SafetyVerdict:
    """Worker から Laravel に渡す安全イベント情報."""

    events: list[SafetyEvent] = field(default_factory=list)
    auto_pause_requested: bool = False

    def add(self, event: SafetyEvent) -> None:
        self.events.append(event)
        if event.severity == "critical":
            self.auto_pause_requested = True

    def to_dict(self) -> dict[str, Any]:
        return {
            "events": [asdict(e) for e in self.events],
            "auto_pause_requested": self.auto_pause_requested,
        }


class SafetyGuard:
    def __init__(self, account_id: int, slack_webhook_url: str = "") -> None:
        self.account_id = account_id
        self._webhook = slack_webhook_url
        self._verdict = SafetyVerdict()

    @property
    def verdict(self) -> SafetyVerdict:
        return self._verdict

    def record_exception(self, exc: BaseException, *, context: str) -> SafetyEvent:
        """Instagrapi 例外を分類して SafetyEvent に積む.

        critical の場合は Slack 通知を試みる(失敗してもメインループは止めない).
        """
        event_type, severity = classify_instagrapi_exception(exc)
        event = SafetyEvent(
            event_type=event_type,
            severity=severity,
            details={
                "context": context,
                "account_id": self.account_id,
                "exception": type(exc).__name__,
                "message": str(exc),
            },
        )
        self._verdict.add(event)
        logger.error(
            "safety_event_recorded",
            extra={
                "account_id": self.account_id,
                "context": context,
                "event_type": event_type,
                "severity": severity,
            },
        )

        if severity == "critical":
            notify_slack(
                self._webhook,
                text=(
                    f":rotating_light: [unara] account_id={self.account_id} "
                    f"context={context} event={event_type} "
                    f"exception={type(exc).__name__}: {exc}"
                ),
            )
        return event
