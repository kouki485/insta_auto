"""ハッシュタグ起点の観光客候補スクレイパー (設計書 §3.1).

Phase 3:
- hashtag_medias_recent で直近の投稿を最大 50 件取得
- 投稿者を重複排除し user_info を取得
- tourist_scorer でスコアを算出
- 閾値以上の候補のみを返す

レートリミッタは worker.src.rate_limiter に集約.
DB upsert は Laravel 側 ProcessWorkerResults が結果ペイロードを受けて行う.
"""

from __future__ import annotations

import logging
import time
from dataclasses import asdict, dataclass
from datetime import datetime, timedelta, timezone
from typing import Callable, Iterable

from src.instagram_client import InstagramClient
from src.rate_limiter import HourlyRateLimiter
from src.tourist_scorer import (
    PostInfo,
    UserInfo,
    calculate_tourist_score,
    is_tourist,
)

logger = logging.getLogger(__name__)

DEFAULT_MEDIA_AMOUNT = 50
DEFAULT_LOOKBACK_HOURS = 48
DEFAULT_MIN_DELAY_SEC = 30
DEFAULT_MAX_DELAY_SEC = 120

HASHTAG_LIMIT_PER_HOUR = 10
USER_LIMIT_PER_HOUR = 50


@dataclass(frozen=True)
class ProspectCandidate:
    ig_user_id: str
    ig_username: str
    full_name: str | None
    bio: str | None
    follower_count: int
    following_count: int
    post_count: int
    detected_lang: str | None
    source_hashtag: str
    source_post_url: str | None
    tourist_score: int
    score_reasons: list[str]


def _current_hour_bucket() -> int:
    now = datetime.now(timezone.utc)
    return int(now.timestamp() // 3600)


class ProspectScraper:
    def __init__(
        self,
        client: InstagramClient,
        rate_limiter: HourlyRateLimiter,
        *,
        sleep_fn: Callable[[float], None] = time.sleep,
        random_fn: Callable[[float, float], float] | None = None,
    ) -> None:
        self._client = client
        self._rate_limiter = rate_limiter
        self._sleep = sleep_fn
        if random_fn is None:
            import random

            self._random = random.uniform
        else:
            self._random = random_fn

    def scrape(
        self,
        account_id: int,
        hashtag: str,
        *,
        media_amount: int = DEFAULT_MEDIA_AMOUNT,
        lookback_hours: int = DEFAULT_LOOKBACK_HOURS,
    ) -> list[ProspectCandidate]:
        bucket = _current_hour_bucket()
        if not self._rate_limiter.acquire(
            "hashtag_search", account_id, HASHTAG_LIMIT_PER_HOUR, hour_bucket=bucket
        ):
            logger.info("hashtag_rate_limited", extra={"hashtag": hashtag})
            return []

        medias = self._fetch_recent_medias(hashtag, media_amount, lookback_hours)
        seen_user_ids: set[str] = set()
        candidates: list[ProspectCandidate] = []

        for media in medias:
            user_id = self._extract_user_id(media)
            if not user_id or user_id in seen_user_ids:
                continue
            seen_user_ids.add(user_id)

            if not self._rate_limiter.acquire(
                "user_info", account_id, USER_LIMIT_PER_HOUR, hour_bucket=bucket
            ):
                logger.info("user_rate_limited", extra={"hashtag": hashtag})
                break

            self._jitter()
            user_payload, recent_posts = self._fetch_user_payload(user_id)
            if user_payload is None:
                continue

            breakdown = calculate_tourist_score(user_payload, recent_posts)
            if not is_tourist(breakdown.score):
                continue

            ig_username = self._extract_username(media) or ""
            candidates.append(
                ProspectCandidate(
                    ig_user_id=user_id,
                    ig_username=ig_username,
                    full_name=user_payload.full_name or None,
                    bio=user_payload.bio or None,
                    follower_count=user_payload.follower_count,
                    following_count=user_payload.following_count,
                    post_count=user_payload.post_count,
                    detected_lang=None,
                    source_hashtag=hashtag,
                    source_post_url=self._extract_post_url(media),
                    tourist_score=breakdown.score,
                    score_reasons=list(breakdown.reasons),
                )
            )

        return candidates

    # ---- I/O helpers --------------------------------------------------

    def _fetch_recent_medias(
        self, hashtag: str, amount: int, lookback_hours: int
    ) -> list[object]:
        raw = self._client.raw
        try:
            medias = raw.hashtag_medias_recent(hashtag, amount=amount)  # type: ignore[attr-defined]
        except Exception as exc:  # noqa: BLE001 - Instagrapi 例外は呼び出し側で分類する
            logger.warning("hashtag_fetch_failed", extra={"hashtag": hashtag, "error": str(exc)})
            raise

        cutoff = datetime.now(timezone.utc) - timedelta(hours=lookback_hours)
        recent: list[object] = []
        for media in medias:
            taken_at = self._extract_taken_at(media)
            if taken_at and taken_at < cutoff:
                continue
            recent.append(media)
        return recent

    def _fetch_user_payload(self, user_id: str) -> tuple[UserInfo | None, list[PostInfo]]:
        raw = self._client.raw
        try:
            info = raw.user_info(user_id)  # type: ignore[attr-defined]
        except Exception as exc:  # noqa: BLE001
            logger.warning("user_info_fetch_failed", extra={"user_id": user_id, "error": str(exc)})
            return None, []

        try:
            user_medias = raw.user_medias(user_id, amount=12)  # type: ignore[attr-defined]
        except Exception as exc:  # noqa: BLE001
            logger.warning("user_medias_fetch_failed", extra={"user_id": user_id, "error": str(exc)})
            user_medias = []

        user = UserInfo(
            follower_count=int(getattr(info, "follower_count", 0) or 0),
            bio=str(getattr(info, "biography", "") or ""),
            full_name=str(getattr(info, "full_name", "") or ""),
            following_count=int(getattr(info, "following_count", 0) or 0),
            post_count=int(getattr(info, "media_count", 0) or 0),
        )
        posts: list[PostInfo] = []
        for media in user_medias:
            posts.append(
                PostInfo(
                    caption=str(getattr(media, "caption_text", "") or ""),
                    location_country=self._extract_location_country(media),
                )
            )
        return user, posts

    # ---- field extractors --------------------------------------------

    @staticmethod
    def _extract_user_id(media: object) -> str | None:
        user = getattr(media, "user", None)
        if user is None:
            return None
        for attr in ("pk", "id", "user_id"):
            value = getattr(user, attr, None)
            if value:
                return str(value)
        if isinstance(user, dict):
            for key in ("pk", "id", "user_id"):
                if user.get(key):
                    return str(user[key])
        return None

    @staticmethod
    def _extract_username(media: object) -> str | None:
        user = getattr(media, "user", None)
        if user is None:
            return None
        username = getattr(user, "username", None)
        return str(username) if username else None

    @staticmethod
    def _extract_taken_at(media: object) -> datetime | None:
        value = getattr(media, "taken_at", None)
        if value is None:
            return None
        if isinstance(value, datetime):
            return value if value.tzinfo else value.replace(tzinfo=timezone.utc)
        if isinstance(value, (int, float)):
            return datetime.fromtimestamp(float(value), tz=timezone.utc)
        return None

    @staticmethod
    def _extract_post_url(media: object) -> str | None:
        code = getattr(media, "code", None)
        return f"https://www.instagram.com/p/{code}/" if code else None

    @staticmethod
    def _extract_location_country(media: object) -> str | None:
        location = getattr(media, "location", None)
        if location is None:
            return None
        for attr in ("country", "country_code"):
            value = getattr(location, attr, None)
            if value:
                return str(value)
        if isinstance(location, dict):
            for key in ("country", "country_code"):
                if location.get(key):
                    return str(location[key])
        return None

    def _jitter(self) -> None:
        delay = float(self._random(DEFAULT_MIN_DELAY_SEC, DEFAULT_MAX_DELAY_SEC))
        self._sleep(delay)


def candidates_to_payload(candidates: Iterable[ProspectCandidate]) -> list[dict]:
    return [asdict(c) for c in candidates]
