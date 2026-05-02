"""LOCAL_MODE が有効な場合の InstagramClient / WorkerConfig の動作確認."""

from __future__ import annotations

import os
from pathlib import Path

import pytest

from src.config import WorkerConfig
from src.instagram_client import AccountContext, InstagramClient
from src.local_stub_client import LocalStubClient


def test_worker_config_local_mode_default_false(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.delenv("LOCAL_MODE", raising=False)
    config = WorkerConfig.from_env()
    assert config.local_mode is False


@pytest.mark.parametrize("value", ["true", "1", "yes", "TRUE"])
def test_worker_config_local_mode_true(monkeypatch: pytest.MonkeyPatch, value: str) -> None:
    monkeypatch.setenv("LOCAL_MODE", value)
    config = WorkerConfig.from_env()
    assert config.local_mode is True


def test_instagram_client_uses_stub_when_local_mode(tmp_path: Path) -> None:
    context = AccountContext(
        account_id=1,
        username="demo",
        password="stub",
        proxy_url="",
        session_path=str(tmp_path / "1.json"),
        local_mode=True,
    )
    ig = InstagramClient(context)
    assert isinstance(ig.raw, LocalStubClient)


def test_instagram_client_requires_proxy_in_production_mode(tmp_path: Path) -> None:
    context = AccountContext(
        account_id=1,
        username="demo",
        password="stub",
        proxy_url="",
        session_path=str(tmp_path / "1.json"),
        local_mode=False,
    )

    with pytest.raises(ValueError, match="proxy_url is required"):
        InstagramClient(context, client_factory=lambda: _BareStub())


class _BareStub:
    def set_proxy(self, url: str) -> None:  # pragma: no cover - 防衛的
        pass


def test_local_stub_client_login_and_dump(tmp_path: Path) -> None:
    stub = LocalStubClient()
    assert stub.login("demo", "stub") is True

    target = tmp_path / "session.json"
    stub.dump_settings(str(target))
    assert target.exists()
