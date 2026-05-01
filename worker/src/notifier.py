"""Slack Webhook 通知ヘルパ.

設計書 §7.3: severity='critical' の safety_event 発生時に自動投稿する.
ネットワーク失敗で Worker のメインループを止めないよう、例外は warning ログのみで吸収する.
"""

from __future__ import annotations

import json
import logging
import urllib.error
import urllib.request
from typing import Callable

logger = logging.getLogger(__name__)


def notify_slack(
    webhook_url: str,
    text: str,
    *,
    timeout: float = 5.0,
    request_fn: Callable[..., None] | None = None,
) -> None:
    if not webhook_url:
        return

    payload = json.dumps({"text": text}).encode("utf-8")

    if request_fn is not None:
        try:
            request_fn(webhook_url, payload, timeout)
        except Exception as exc:  # noqa: BLE001 - 通知失敗は致命傷ではない
            logger.warning("slack_notify_failed", extra={"error": str(exc)})
        return

    request = urllib.request.Request(
        webhook_url,
        data=payload,
        headers={"Content-Type": "application/json"},
        method="POST",
    )
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:  # nosec - 外部 webhook
            response.read()
    except (urllib.error.URLError, TimeoutError) as exc:
        logger.warning("slack_notify_failed", extra={"error": str(exc)})
