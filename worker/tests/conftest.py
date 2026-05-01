"""pytest 共通フィクスチャ."""

from __future__ import annotations

import fakeredis
import pytest


@pytest.fixture
def fake_redis() -> fakeredis.FakeRedis:
    """fakeredis をテスト用 Redis として返す."""
    return fakeredis.FakeRedis()
