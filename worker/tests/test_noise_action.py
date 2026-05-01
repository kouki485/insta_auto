"""ノイズ動作のテスト."""

from __future__ import annotations

from pathlib import Path
from unittest.mock import MagicMock

from src.instagram_client import AccountContext, InstagramClient
from src.noise_action import perform
from src.safety import HumanDelay


class _StubClient:
    def __init__(self) -> None:
        self.proxy_url: str | None = None
        self.feed_called = False
        self.user_lookup = None

    def set_proxy(self, url: str) -> None:
        self.proxy_url = url

    def load_settings(self, path: str) -> None: ...

    def login(self, username: str, password: str) -> bool:
        return True

    def dump_settings(self, path: str) -> None:
        Path(path).parent.mkdir(parents=True, exist_ok=True)
        Path(path).write_text("{}", encoding="utf-8")

    def feed_timeline(self, amount: int):
        self.feed_called = True
        return []

    def user_info_by_username(self, username: str):
        self.user_lookup = username
        return MagicMock(username=username)


def _client(tmp_path: Path) -> tuple[_StubClient, InstagramClient]:
    stub = _StubClient()
    ctx = AccountContext(
        account_id=1,
        username="x",
        password="y",
        proxy_url="http://u:p@brd.example.com",
        session_path=str(tmp_path / "1.json"),
    )
    return stub, InstagramClient(
        ctx, client_factory=lambda: stub, delay=HumanDelay(min_sec=0, max_sec=0)
    )


def test_perform_skips_when_random_above_threshold(tmp_path: Path) -> None:
    _, client = _client(tmp_path)
    result = perform(client, random_fn=lambda: 0.99, choice_fn=lambda actions: actions[0])
    assert result is None


def test_perform_executes_action_when_random_below_threshold(tmp_path: Path) -> None:
    _, client = _client(tmp_path)
    selected: list[str] = []

    def choose(actions):
        selected.append(actions[0][0])
        return actions[0]

    result = perform(client, random_fn=lambda: 0.0, choice_fn=choose)
    assert result is not None
    assert selected == [result]


def test_perform_swallows_action_exception(tmp_path: Path) -> None:
    _, client = _client(tmp_path)

    def boom(actions):
        return ("explode", lambda: (_ for _ in ()).throw(RuntimeError("network")))

    result = perform(client, random_fn=lambda: 0.0, choice_fn=boom)
    assert result is None
