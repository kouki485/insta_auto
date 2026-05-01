"""DmSender + main.handle dm のテスト. Instagrapi は完全モック."""

from __future__ import annotations

from pathlib import Path
from unittest.mock import MagicMock

import pytest

import main
from src.config import WorkerConfig
from src.dm_sender import DmSender
from src.instagram_client import AccountContext, InstagramClient
from src.queue_protocol import JobPayload
from src.safety import HumanDelay
from src.safety_guard import SafetyGuard


class _ChallengeRequired(Exception):
    pass


_ChallengeRequired.__name__ = "ChallengeRequired"
_ChallengeRequired.__module__ = "instagrapi.exceptions"


class _StubClient:
    def __init__(self, *, raise_on_send: Exception | None = None) -> None:
        self.proxy_url: str | None = None
        self.last_dm: tuple[str, list[str]] | None = None
        self._raise = raise_on_send

    def set_proxy(self, url: str) -> None:
        self.proxy_url = url

    def load_settings(self, path: str) -> None: ...

    def login(self, username: str, password: str) -> bool:
        return True

    def dump_settings(self, path: str) -> None:
        Path(path).parent.mkdir(parents=True, exist_ok=True)
        Path(path).write_text("{}", encoding="utf-8")

    def direct_send(self, message: str, user_ids: list[str]):
        if self._raise:
            raise self._raise
        self.last_dm = (message, user_ids)
        return MagicMock(id="msg-123")

    def feed_timeline(self, amount: int):
        return []

    def user_info_by_username(self, username: str):
        return MagicMock(username=username)


@pytest.fixture
def context(tmp_path: Path) -> AccountContext:
    return AccountContext(
        account_id=1,
        username="unara",
        password="pw",
        proxy_url="http://u:p@brd.example.com",
        session_path=str(tmp_path / "1.json"),
    )


@pytest.fixture
def config(tmp_path: Path) -> WorkerConfig:
    return WorkerConfig(
        db_host="x",
        db_port=3306,
        db_user="x",
        db_password="x",
        db_name="x",
        redis_host="x",
        redis_port=6379,
        redis_db=0,
        instagram_username="unara",
        instagram_password="pw",
        proxy_url="http://u:p@brd.example.com",
        session_dir=str(tmp_path / "sessions"),
        sentry_dsn="",
        slack_webhook_url="",
        log_level="INFO",
        worker_queue_timeout=1,
    )


def _ig_factory(stub: _StubClient):
    delay = HumanDelay(min_sec=0, max_sec=0)
    return lambda ctx: InstagramClient(ctx, client_factory=lambda: stub, delay=delay)


def test_dm_sender_returns_success_with_message_id(context: AccountContext) -> None:
    stub = _StubClient()
    delay = HumanDelay(min_sec=0, max_sec=0)
    client = InstagramClient(context, client_factory=lambda: stub, delay=delay)
    guard = SafetyGuard(account_id=1)
    sender = DmSender(client, guard)

    outcome = sender.send("user-1", "Hi tourist!")

    assert outcome.success is True
    assert outcome.ig_message_id == "msg-123"
    assert stub.last_dm == ("Hi tourist!", ["user-1"])


def test_dm_sender_records_safety_event_on_challenge(context: AccountContext) -> None:
    stub = _StubClient(raise_on_send=_ChallengeRequired("verify"))
    delay = HumanDelay(min_sec=0, max_sec=0)
    client = InstagramClient(context, client_factory=lambda: stub, delay=delay)
    guard = SafetyGuard(account_id=1)
    sender = DmSender(client, guard)

    outcome = sender.send("user-1", "Hello")

    assert outcome.success is False
    assert "ChallengeRequired" in (outcome.error or "")
    assert guard.verdict.auto_pause_requested is True


def test_handle_dm_success_includes_safety_payload(
    config: WorkerConfig,
) -> None:
    stub = _StubClient()
    job = JobPayload(
        job_id="j-dm-1",
        account_id=1,
        type=main.DM_QUEUE,
        data={"ig_user_id": "user-1", "message": "Hi tourist"},
        created_at="2026-05-01T10:00:00Z",
    )

    result = main.handle(job, config=config, ig_factory=_ig_factory(stub))

    assert result.status == "success"
    assert result.account_id == 1
    assert result.result is not None
    assert result.result["ig_message_id"] == "msg-123"
    assert "safety" in result.result


def test_handle_dm_failure_returns_safety_payload(config: WorkerConfig) -> None:
    stub = _StubClient(raise_on_send=_ChallengeRequired("oops"))
    job = JobPayload(
        job_id="j-dm-2",
        account_id=1,
        type=main.DM_QUEUE,
        data={"ig_user_id": "user-1", "message": "Hi"},
        created_at="2026-05-01T10:00:00Z",
    )

    result = main.handle(job, config=config, ig_factory=_ig_factory(stub))

    assert result.status == "failure"
    assert result.error is not None
    assert "ChallengeRequired" in result.error
    assert result.result is not None
    assert result.result["safety"]["auto_pause_requested"] is True


def test_handle_dm_validates_payload(config: WorkerConfig) -> None:
    stub = _StubClient()
    job = JobPayload(
        job_id="j-dm-3",
        account_id=1,
        type=main.DM_QUEUE,
        data={"ig_user_id": "", "message": ""},
        created_at="2026-05-01T10:00:00Z",
    )
    result = main.handle(job, config=config, ig_factory=_ig_factory(stub))
    assert result.status == "failure"
    assert "ig_user_id missing" in (result.error or "")
