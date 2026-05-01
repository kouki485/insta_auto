"""HourlyRateLimiter のスライディングウィンドウテスト."""

from __future__ import annotations

import fakeredis

from src.rate_limiter import HourlyRateLimiter


def test_acquire_returns_true_until_limit_reached() -> None:
    client = fakeredis.FakeRedis()
    limiter = HourlyRateLimiter(client)
    base_ts = 1_700_000_000.0
    for i in range(3):
        assert limiter.acquire("scope", account_id=1, limit=3, now_ts=base_ts + i)
    assert not limiter.acquire("scope", account_id=1, limit=3, now_ts=base_ts + 4)


def test_old_events_drop_off_after_window_passes() -> None:
    """1 時間以上経過したエントリは zremrangebyscore で除外される."""
    client = fakeredis.FakeRedis()
    limiter = HourlyRateLimiter(client)
    base = 1_700_000_000.0
    assert limiter.acquire("scope", account_id=1, limit=1, now_ts=base)
    assert not limiter.acquire("scope", account_id=1, limit=1, now_ts=base + 60)
    # 1 時間 + 1 秒経過するとウィンドウから外れて再取得可能.
    assert limiter.acquire("scope", account_id=1, limit=1, now_ts=base + 3601)


def test_different_accounts_isolated() -> None:
    client = fakeredis.FakeRedis()
    limiter = HourlyRateLimiter(client)
    ts = 1_700_000_000.0
    assert limiter.acquire("scope", account_id=1, limit=1, now_ts=ts)
    assert limiter.acquire("scope", account_id=2, limit=1, now_ts=ts)
    assert not limiter.acquire("scope", account_id=1, limit=1, now_ts=ts + 1)


def test_different_scopes_isolated() -> None:
    client = fakeredis.FakeRedis()
    limiter = HourlyRateLimiter(client)
    ts = 1_700_000_000.0
    assert limiter.acquire("hashtag_search", account_id=1, limit=1, now_ts=ts)
    assert limiter.acquire("user_info", account_id=1, limit=1, now_ts=ts)
    assert not limiter.acquire("hashtag_search", account_id=1, limit=1, now_ts=ts + 1)


def test_no_burst_at_hour_boundary() -> None:
    """旧バケット方式だと境界で 2x 消費可能だったが、新実装は防ぐ."""
    client = fakeredis.FakeRedis()
    limiter = HourlyRateLimiter(client)
    boundary = 1_700_000_000.0
    for offset in (-300.0, -200.0, -100.0):
        assert limiter.acquire("scope", account_id=1, limit=3, now_ts=boundary + offset)
    # 直後はまだウィンドウ内なので追加できない.
    assert not limiter.acquire("scope", account_id=1, limit=3, now_ts=boundary + 60)
