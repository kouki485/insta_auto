"""prospect_scraper のテスト. Instagrapi は完全モック."""

from __future__ import annotations

from datetime import datetime, timedelta, timezone
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import MagicMock

import fakeredis
import pytest

from src.instagram_client import AccountContext, InstagramClient
from src.prospect_scraper import ProspectScraper, candidates_to_payload
from src.rate_limiter import HourlyRateLimiter
from src.safety import HumanDelay


class _StubClient:
    def __init__(self) -> None:
        self.proxy_url: str | None = None
        self.medias_response: list = []
        self.user_info_map: dict[str, object] = {}
        self.user_medias_map: dict[str, list] = {}

    def set_proxy(self, url: str) -> None:
        self.proxy_url = url

    def load_settings(self, path: str) -> None: ...

    def login(self, username: str, password: str) -> bool:
        return True

    def dump_settings(self, path: str) -> None:
        Path(path).parent.mkdir(parents=True, exist_ok=True)
        Path(path).write_text("{}", encoding="utf-8")

    def hashtag_medias_recent(self, hashtag: str, amount: int):
        return self.medias_response[:amount]

    def user_info(self, user_id: str):
        return self.user_info_map[user_id]

    def user_medias(self, user_id: str, amount: int = 12):
        return self.user_medias_map.get(user_id, [])


def _make_media(user_id: str, username: str, *, taken_at=None, country="Japan", code="abc"):
    return SimpleNamespace(
        user=SimpleNamespace(pk=user_id, username=username),
        taken_at=taken_at or datetime.now(timezone.utc),
        location=SimpleNamespace(country=country),
        code=code,
        caption_text="loving tokyo",
    )


@pytest.fixture
def context(tmp_path: Path) -> AccountContext:
    return AccountContext(
        account_id=1,
        username="unara",
        password="x",
        proxy_url="http://u:p@brd.example.com",
        session_path=str(tmp_path / "1.json"),
    )


@pytest.fixture
def stub() -> _StubClient:
    return _StubClient()


@pytest.fixture
def ig_client(context: AccountContext, stub: _StubClient) -> InstagramClient:
    return InstagramClient(
        context, client_factory=lambda: stub, delay=HumanDelay(min_sec=0, max_sec=0)
    )


@pytest.fixture
def rate_limiter() -> HourlyRateLimiter:
    return HourlyRateLimiter(fakeredis.FakeRedis())


def _no_sleep(_sec: float) -> None:
    pass


def _no_random(min_sec: float, max_sec: float) -> float:
    return 0.0


def test_scrape_returns_high_score_candidates(
    ig_client: InstagramClient, stub: _StubClient, rate_limiter: HourlyRateLimiter
) -> None:
    stub.medias_response = [
        _make_media("u1", "tourist_one"),
    ]
    stub.user_info_map = {
        "u1": SimpleNamespace(
            follower_count=15_000,
            biography="Travel blogger from London. Loves Tokyo trips.",
            full_name="John Smith",
        ),
    }
    stub.user_medias_map = {
        "u1": [
            SimpleNamespace(caption_text="vacation in tokyo!", location=SimpleNamespace(country="Japan")),
            SimpleNamespace(caption_text="paris was nice", location=SimpleNamespace(country="France")),
        ],
    }

    scraper = ProspectScraper(
        ig_client, rate_limiter, sleep_fn=_no_sleep, random_fn=_no_random
    )
    candidates = scraper.scrape(account_id=1, hashtag="asakusa")

    assert len(candidates) == 1
    assert candidates[0].ig_user_id == "u1"
    assert candidates[0].ig_username == "tourist_one"
    assert candidates[0].source_hashtag == "asakusa"
    assert candidates[0].tourist_score >= 60


def test_scrape_excludes_users_below_threshold(
    ig_client: InstagramClient, stub: _StubClient, rate_limiter: HourlyRateLimiter
) -> None:
    stub.medias_response = [_make_media("u_local", "local_chef")]
    stub.user_info_map = {
        "u_local": SimpleNamespace(
            follower_count=4_000,
            biography="東京の料理人です。よろしくお願いします。",
            full_name="田中太郎",
        ),
    }
    stub.user_medias_map = {
        "u_local": [
            SimpleNamespace(caption_text="今日は寿司です", location=SimpleNamespace(country="Japan")),
            SimpleNamespace(caption_text="銀座でランチ", location=SimpleNamespace(country="Japan")),
        ],
    }
    scraper = ProspectScraper(
        ig_client, rate_limiter, sleep_fn=_no_sleep, random_fn=_no_random
    )
    candidates = scraper.scrape(account_id=1, hashtag="asakusa")
    assert candidates == []


def test_scrape_filters_out_old_media(
    ig_client: InstagramClient, stub: _StubClient, rate_limiter: HourlyRateLimiter
) -> None:
    old = _make_media(
        "u_old",
        "old_user",
        taken_at=datetime.now(timezone.utc) - timedelta(days=10),
    )
    stub.medias_response = [old]
    stub.user_info_map = {
        "u_old": SimpleNamespace(follower_count=20_000, biography="hi", full_name="X"),
    }

    scraper = ProspectScraper(
        ig_client, rate_limiter, sleep_fn=_no_sleep, random_fn=_no_random
    )
    candidates = scraper.scrape(account_id=1, hashtag="asakusa")
    assert candidates == []


def test_hashtag_rate_limit_returns_empty_after_threshold(
    ig_client: InstagramClient, stub: _StubClient, rate_limiter: HourlyRateLimiter
) -> None:
    scraper = ProspectScraper(
        ig_client, rate_limiter, sleep_fn=_no_sleep, random_fn=_no_random
    )
    # 10 タグまでは取得できる
    for i in range(10):
        scraper.scrape(account_id=1, hashtag=f"tag-{i}")
    # 11 タグ目はレート制限
    stub.medias_response = [_make_media("u1", "anyone")]
    stub.user_info_map = {
        "u1": SimpleNamespace(
            follower_count=15_000, biography="english bio for travel", full_name="John"
        ),
    }
    stub.user_medias_map = {"u1": []}

    candidates = scraper.scrape(account_id=1, hashtag="tag-overflow")
    assert candidates == []  # rate limit でブロック


def test_candidates_to_payload_serializable() -> None:
    from src.prospect_scraper import ProspectCandidate

    sample = ProspectCandidate(
        ig_user_id="u1",
        ig_username="tourist",
        full_name="John",
        bio="bio",
        follower_count=10_000,
        following_count=100,
        post_count=200,
        detected_lang="en",
        source_hashtag="asakusa",
        source_post_url="https://example.com/p/abc/",
        tourist_score=85,
        score_reasons=["x"],
    )
    payload = candidates_to_payload([sample])
    assert payload[0]["ig_user_id"] == "u1"
    assert payload[0]["tourist_score"] == 85
