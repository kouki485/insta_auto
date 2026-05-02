"""post_publisher のテスト. Instagrapi は完全モック."""

from __future__ import annotations

from pathlib import Path
from unittest.mock import MagicMock

import pytest

from src.instagram_client import AccountContext, InstagramClient
from src.post_publisher import PostPublisher
from src.safety import HumanDelay


class _StubClient:
    """instagrapi.Client のモック."""

    def __init__(self) -> None:
        self.proxy_url: str | None = None
        self.loaded_settings: str | None = None
        self.dumped_settings: str | None = None
        self.last_login: tuple[str, str] | None = None
        self.uploaded_feed: tuple[str, str] | None = None
        self.uploaded_story: str | None = None

    def set_proxy(self, url: str) -> None:
        self.proxy_url = url

    def load_settings(self, path: str) -> None:
        self.loaded_settings = path

    def login(self, username: str, password: str) -> bool:
        self.last_login = (username, password)
        return True

    def dump_settings(self, path: str) -> None:
        self.dumped_settings = path
        # instagrapi 本体はファイルを書き込むためテスト stub も同様にする.
        Path(path).parent.mkdir(parents=True, exist_ok=True)
        Path(path).write_text("{}", encoding="utf-8")

    def photo_upload(self, path: str, caption: str):
        self.uploaded_feed = (path, caption)
        return MagicMock(id="ig-feed-1")

    def photo_upload_to_story(self, path: str):
        self.uploaded_story = path
        return MagicMock(id="ig-story-1")


@pytest.fixture
def fixture_image(tmp_path: Path) -> Path:
    image = tmp_path / "image.jpg"
    image.write_bytes(b"fake")
    return image


@pytest.fixture
def context(tmp_path: Path) -> AccountContext:
    return AccountContext(
        account_id=1,
        username="demo_account",
        password="pw",
        proxy_url="http://u:p@brd.example.com",
        session_path=str(tmp_path / "1.json"),
    )


def _no_sleep(*args, **kwargs) -> float:
    return 0.0


def test_publish_feed_uploads_with_caption(fixture_image: Path, context: AccountContext) -> None:
    stub = _StubClient()
    delay = HumanDelay(min_sec=0, max_sec=0)
    client = InstagramClient(context, client_factory=lambda: stub, delay=delay)
    publisher = PostPublisher(client)

    result = publisher.publish_feed(str(fixture_image), "Hello Asakusa")

    assert result.ig_media_id == "ig-feed-1"
    assert stub.uploaded_feed == (str(fixture_image), "Hello Asakusa")


def test_publish_story_uploads_image(fixture_image: Path, context: AccountContext) -> None:
    stub = _StubClient()
    delay = HumanDelay(min_sec=0, max_sec=0)
    client = InstagramClient(context, client_factory=lambda: stub, delay=delay)
    publisher = PostPublisher(client)

    result = publisher.publish_story(str(fixture_image))

    assert result.ig_media_id == "ig-story-1"
    assert stub.uploaded_story == str(fixture_image)


def test_publish_feed_raises_when_image_missing(context: AccountContext) -> None:
    stub = _StubClient()
    delay = HumanDelay(min_sec=0, max_sec=0)
    client = InstagramClient(context, client_factory=lambda: stub, delay=delay)
    publisher = PostPublisher(client)

    with pytest.raises(FileNotFoundError):
        publisher.publish_feed("/nonexistent/path.jpg", None)


def test_login_always_dumps_settings_to_keep_cookies_fresh(
    fixture_image: Path, context: AccountContext
) -> None:
    stub = _StubClient()
    delay = HumanDelay(min_sec=0, max_sec=0)
    client = InstagramClient(context, client_factory=lambda: stub, delay=delay)

    # 1 回目: セッションファイルなし → load されず、dump で新規作成される.
    client.login()
    assert stub.loaded_settings is None
    assert stub.dumped_settings == context.session_path

    # 2 回目: 既存ファイルあり → load + login + dump (cookie 更新).
    stub2 = _StubClient()
    client2 = InstagramClient(context, client_factory=lambda: stub2, delay=delay)
    client2.login()
    assert stub2.loaded_settings == context.session_path
    assert stub2.dumped_settings == context.session_path


def test_proxy_is_set_on_construction(context: AccountContext) -> None:
    stub = _StubClient()
    delay = HumanDelay(min_sec=0, max_sec=0)
    InstagramClient(context, client_factory=lambda: stub, delay=delay)
    assert stub.proxy_url == context.proxy_url


def test_missing_proxy_raises_to_prevent_direct_connection(tmp_path: Path) -> None:
    """設計書 §4.1.1 を満たすため proxy 未設定はインスタンス生成時点で拒否される."""
    context = AccountContext(
        account_id=99,
        username="x",
        password="y",
        proxy_url="",
        session_path=str(tmp_path / "99.json"),
    )
    delay = HumanDelay(min_sec=0, max_sec=0)
    with pytest.raises(ValueError, match="proxy_url is required"):
        InstagramClient(context, client_factory=lambda: _StubClient(), delay=delay)
