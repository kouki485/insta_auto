"""キュープロトコル(JobPayload / JobResult / brpop_job)のテスト."""

from __future__ import annotations

import json

import pytest

from src.queue_protocol import (
    QUEUE_PREFIX,
    RESULT_QUEUE,
    JobPayload,
    JobResult,
    brpop_job,
    push_result,
    queue_name,
)


def test_queue_name_adds_prefix_when_missing() -> None:
    assert queue_name("dm") == f"{QUEUE_PREFIX}dm"


def test_queue_name_preserves_full_name() -> None:
    assert queue_name(f"{QUEUE_PREFIX}dm") == f"{QUEUE_PREFIX}dm"


def test_job_payload_parses_minimum_fields() -> None:
    raw = json.dumps(
        {
            "job_id": "abc",
            "account_id": 1,
            "type": "send_dm",
            "data": {"prospect_id": 42, "message": "hi"},
            "created_at": "2026-05-01T10:00:00Z",
        }
    )
    payload = JobPayload.from_json(raw)

    assert payload.job_id == "abc"
    assert payload.account_id == 1
    assert payload.type == "send_dm"
    assert payload.data == {"prospect_id": 42, "message": "hi"}
    assert payload.retry_count == 0


def test_job_result_serializes_with_completed_at(fake_redis) -> None:
    result = JobResult(job_id="abc", status="success", result={"ig_message_id": "x"})
    payload = json.loads(result.to_json())

    assert payload["job_id"] == "abc"
    assert payload["status"] == "success"
    assert payload["result"] == {"ig_message_id": "x"}
    assert payload["error"] is None
    assert payload["completed_at"].endswith("Z")


def test_push_result_lpushes_into_result_queue(fake_redis) -> None:
    result = JobResult(job_id="zzz", status="failure", error="boom")
    push_result(fake_redis, result)

    raw = fake_redis.rpop(RESULT_QUEUE)
    assert raw is not None
    parsed = json.loads(raw)
    assert parsed["job_id"] == "zzz"
    assert parsed["status"] == "failure"
    assert parsed["error"] == "boom"


def test_brpop_job_returns_payload_when_pushed_via_lpush(fake_redis) -> None:
    raw = json.dumps(
        {
            "job_id": "j1",
            "account_id": 1,
            "type": "send_dm",
            "data": {"x": 1},
            "created_at": "2026-05-01T10:00:00Z",
        }
    )
    fake_redis.lpush(f"{QUEUE_PREFIX}dm", raw)

    payload = brpop_job(fake_redis, ["dm"], timeout=1)
    assert payload is not None
    assert payload.job_id == "j1"


def test_brpop_job_returns_none_when_payload_invalid(fake_redis) -> None:
    fake_redis.lpush(f"{QUEUE_PREFIX}dm", "not-json")
    assert brpop_job(fake_redis, ["dm"], timeout=1) is None


def test_brpop_job_returns_none_on_timeout(fake_redis) -> None:
    assert brpop_job(fake_redis, ["dm"], timeout=1) is None
