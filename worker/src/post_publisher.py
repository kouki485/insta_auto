"""フィード投稿 / ストーリーズ投稿の実行モジュール.

設計書 §3.3 と Phase 2-D の通り Instagrapi の photo_upload / photo_upload_to_story を呼ぶ.
返り値の `ig_media_id` を JobResult.result に積む.
"""

from __future__ import annotations

import logging
from dataclasses import dataclass
from pathlib import Path

from src.instagram_client import InstagramClient

logger = logging.getLogger(__name__)


@dataclass(frozen=True)
class PublishResult:
    ig_media_id: str


class PostPublisher:
    def __init__(self, client: InstagramClient) -> None:
        self._client = client

    def publish_feed(self, image_path: str, caption: str | None) -> PublishResult:
        path = self._verify_path(image_path)
        media = self._client.raw.photo_upload(str(path), caption or "")
        media_id = self._extract_media_id(media)
        self._client.delay()
        logger.info(
            "ig_feed_uploaded",
            extra={"path": str(path), "media_id": media_id},
        )
        return PublishResult(ig_media_id=media_id)

    def publish_story(self, image_path: str) -> PublishResult:
        path = self._verify_path(image_path)
        media = self._client.raw.photo_upload_to_story(str(path))
        media_id = self._extract_media_id(media)
        self._client.delay()
        logger.info(
            "ig_story_uploaded",
            extra={"path": str(path), "media_id": media_id},
        )
        return PublishResult(ig_media_id=media_id)

    @staticmethod
    def _verify_path(image_path: str) -> Path:
        path = Path(image_path)
        if not path.is_file():
            raise FileNotFoundError(f"image not found: {path}")
        return path

    @staticmethod
    def _extract_media_id(media: object) -> str:
        # instagrapi v2 は dataclass に近い属性アクセス、v1 は dict 型を返すケースがある.
        for attr in ("id", "pk", "media_id"):
            value = getattr(media, attr, None)
            if value:
                return str(value)
        if isinstance(media, dict):
            for key in ("id", "pk", "media_id"):
                if media.get(key):
                    return str(media[key])
        raise RuntimeError("unable to extract media id from instagrapi response")
