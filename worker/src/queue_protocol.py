"""Laravel ↔ Worker 共通の Redis JSON プロトコル.

設計書 §1.3 に準拠。Laravel が `unara:queue:{name}` に LPUSH したペイロードを
BRPOP で取り出し、結果を `unara:queue:result` に LPUSH で書き戻す。
"""

from __future__ import annotations

import json
import logging
from dataclasses import asdict, dataclass, field
from datetime import datetime, timezone
from typing import Any

import redis

logger = logging.getLogger(__name__)

QUEUE_PREFIX = "unara:queue:"
RESULT_QUEUE = f"{QUEUE_PREFIX}result"


@dataclass
class JobPayload:
    """Laravel から受け取るジョブの共通スキーマ."""

    job_id: str
    account_id: int
    type: str
    data: dict[str, Any]
    created_at: str
    retry_count: int = 0

    @classmethod
    def from_json(cls, raw: str) -> "JobPayload":
        parsed = json.loads(raw)
        return cls(
            job_id=parsed["job_id"],
            account_id=int(parsed["account_id"]),
            type=parsed["type"],
            data=parsed.get("data", {}),
            created_at=parsed.get("created_at", _now_iso()),
            retry_count=int(parsed.get("retry_count", 0)),
        )


@dataclass
class JobResult:
    """Laravel に書き戻す結果のスキーマ.

    account_id は scrape ジョブのように worker_job_id を持たないリソース
    (prospects upsert) を Laravel 側で対象アカウントに紐づけるために必要.
    """

    job_id: str
    status: str  # "success" | "failure"
    account_id: int | None = None
    result: dict[str, Any] | None = None
    error: str | None = None
    completed_at: str = field(default_factory=lambda: _now_iso())

    def to_json(self) -> str:
        return json.dumps(asdict(self), ensure_ascii=False)


def _now_iso() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def queue_name(name: str) -> str:
    if name.startswith(QUEUE_PREFIX):
        return name
    return f"{QUEUE_PREFIX}{name}"


def push_result(client: redis.Redis, result: JobResult) -> None:
    """結果キューに書き戻す."""
    client.lpush(RESULT_QUEUE, result.to_json())
    logger.info("job_result_pushed", extra={"job_id": result.job_id, "status": result.status})


def brpop_job(
    client: redis.Redis,
    queue_names: list[str],
    timeout: int = 30,
) -> JobPayload | None:
    """指定したキュー群を BRPOP し、ジョブが届いたらパースして返す."""
    full_names = [queue_name(name) for name in queue_names]
    response = client.brpop(full_names, timeout=timeout)
    if response is None:
        return None

    _, raw = response
    if isinstance(raw, bytes):
        raw = raw.decode("utf-8")
    try:
        return JobPayload.from_json(raw)
    except (json.JSONDecodeError, KeyError) as exc:
        logger.warning("job_payload_parse_failed", extra={"raw": raw, "error": str(exc)})
        return None
