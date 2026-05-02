"""main.handle のディスパッチテスト. Instagrapi は完全モック."""

from __future__ import annotations

from pathlib import Path
from unittest.mock import MagicMock

import pytest

import main
from src.config import WorkerConfig
from src.instagram_client import AccountContext, InstagramClient
from src.queue_protocol import JobPayload
from src.safety import HumanDelay


class _StubClient:
    def __init__(self) -> None:
        self.proxy_url: str | None = None
        self.uploaded_feed: tuple[str, str] | None = None
        self.uploaded_story: str | None = None

    def set_proxy(self, url: str) -> None:
        self.proxy_url = url

    def load_settings(self, path: str) -> None: ...

    def login(self, username: str, password: str) -> bool:
        return True

    def dump_settings(self, path: str) -> None:
        Path(path).parent.mkdir(parents=True, exist_ok=True)
        Path(path).write_text("{}", encoding="utf-8")

    def photo_upload(self, path: str, caption: str):
        self.uploaded_feed = (path, caption)
        return MagicMock(id="ig-feed-42")

    def photo_upload_to_story(self, path: str):
        self.uploaded_story = path
        return MagicMock(id="ig-story-42")


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
        instagram_username="demo_account",
        instagram_password="pw",
        proxy_url="http://u:p@brd.example.com",
        session_dir=str(tmp_path / "sessions"),
        sentry_dsn="",
        slack_webhook_url="",
        log_level="INFO",
        worker_queue_timeout=1,
    )


def _ig_factory_no_delay(stub: _StubClient):
    delay = HumanDelay(min_sec=0, max_sec=0)
    return lambda ctx: InstagramClient(ctx, client_factory=lambda: stub, delay=delay)


def test_handle_post_feed_returns_media_id(tmp_path: Path, config: WorkerConfig) -> None:
    image = tmp_path / "feed.jpg"
    image.write_bytes(b"x")

    job = JobPayload(
        job_id="j-1",
        account_id=1,
        type=main.POST_FEED_QUEUE,
        data={"image_path": str(image), "caption": "Hi"},
        created_at="2026-05-01T10:00:00Z",
    )
    stub = _StubClient()
    result = main.handle(job, config=config, ig_factory=_ig_factory_no_delay(stub))

    assert result.status == "success"
    assert result.result == {"ig_media_id": "ig-feed-42"}
    assert stub.uploaded_feed == (str(image), "Hi")


def test_handle_post_story_returns_media_id(tmp_path: Path, config: WorkerConfig) -> None:
    image = tmp_path / "story.jpg"
    image.write_bytes(b"x")

    job = JobPayload(
        job_id="j-2",
        account_id=1,
        type=main.POST_STORY_QUEUE,
        data={"image_path": str(image)},
        created_at="2026-05-01T10:00:00Z",
    )
    stub = _StubClient()
    result = main.handle(job, config=config, ig_factory=_ig_factory_no_delay(stub))

    assert result.status == "success"
    assert result.result == {"ig_media_id": "ig-story-42"}


def test_handle_returns_failure_when_image_missing(config: WorkerConfig) -> None:
    job = JobPayload(
        job_id="j-3",
        account_id=1,
        type=main.POST_FEED_QUEUE,
        data={"image_path": "/no/such/file.jpg"},
        created_at="2026-05-01T10:00:00Z",
    )
    result = main.handle(job, config=config, ig_factory=_ig_factory_no_delay(_StubClient()))

    assert result.status == "failure"
    assert "FileNotFoundError" in (result.error or "")


def test_handle_unknown_type_returns_echo(config: WorkerConfig) -> None:
    job = JobPayload(
        job_id="j-4",
        account_id=1,
        type="unknown_queue",
        data={"x": 1},
        created_at="2026-05-01T10:00:00Z",
    )
    result = main.handle(job, config=config)
    assert result.status == "success"
    assert result.result is not None and "echo" in result.result
