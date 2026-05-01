"""Worker エントリーポイント.

Phase 2 (post_feed / post_story) と Phase 3 (scrape) のジョブを処理する.
- セッション永続化 + プロキシ固定 + human_delay は InstagramClient 内で必ず実行
- 例外は safety.classify_instagrapi_exception で event_type に変換し、
  失敗時は JobResult.error に文字列を詰めて Laravel 側 ProcessWorkerResults へ返す

Phase 4 で DM ハンドラを追加する.
"""

from __future__ import annotations

import logging
import sys
from pathlib import Path

import redis
import sentry_sdk

from src.config import WorkerConfig
from src.instagram_client import AccountContext, InstagramClient
from src.post_publisher import PostPublisher
from src.prospect_scraper import ProspectScraper, candidates_to_payload
from src.queue_protocol import (
    JobPayload,
    JobResult,
    brpop_job,
    push_result,
)
from src.rate_limiter import HourlyRateLimiter
from src.safety import classify_instagrapi_exception

DM_QUEUE = "dm"
SCRAPE_QUEUE = "scrape"
POST_FEED_QUEUE = "post_feed"
POST_STORY_QUEUE = "post_story"
WATCHED_QUEUES = [DM_QUEUE, SCRAPE_QUEUE, POST_FEED_QUEUE, POST_STORY_QUEUE]


def main() -> int:
    config = WorkerConfig.from_env()
    _setup_logging(config.log_level)
    if config.sentry_dsn:
        sentry_sdk.init(dsn=config.sentry_dsn, traces_sample_rate=0.1)

    logging.info("worker_starting", extra={"queues": WATCHED_QUEUES})

    client = redis.Redis(
        host=config.redis_host,
        port=config.redis_port,
        db=config.redis_db,
        decode_responses=False,
    )

    try:
        _run_loop(client, config)
    except KeyboardInterrupt:
        logging.info("worker_stopped_by_signal")
        return 0
    return 0


def _run_loop(client: redis.Redis, config: WorkerConfig) -> None:
    while True:
        job = brpop_job(client, WATCHED_QUEUES, timeout=config.worker_queue_timeout)
        if job is None:
            continue

        logging.info("job_received", extra={"job_id": job.job_id, "type": job.type})
        result = handle(job, config=config, redis_client=client)
        push_result(client, result)


def handle(
    job: JobPayload,
    *,
    config: WorkerConfig,
    ig_factory=None,
    redis_client: redis.Redis | None = None,
) -> JobResult:
    """ジョブをディスパッチして JobResult を返す。テスト容易性のため独立関数として公開."""
    try:
        if job.type in (POST_FEED_QUEUE, POST_STORY_QUEUE):
            return _handle_post(job, config=config, ig_factory=ig_factory)
        if job.type == SCRAPE_QUEUE:
            if redis_client is None:
                raise RuntimeError("scrape job requires redis_client")
            return _handle_scrape(
                job, config=config, ig_factory=ig_factory, redis_client=redis_client
            )
        # Phase 4 で dm を実装する.
        return JobResult(
            job_id=job.job_id,
            status="success",
            account_id=job.account_id,
            result={"echo": {"type": job.type, "data": job.data}},
        )
    except Exception as exc:  # noqa: BLE001 - 例外は分類してから返す
        event_type, severity = classify_instagrapi_exception(exc)
        logging.error(
            "job_failed",
            extra={
                "job_id": job.job_id,
                "exception": type(exc).__name__,
                "event_type": event_type,
                "severity": severity,
            },
        )
        return JobResult(
            job_id=job.job_id,
            status="failure",
            account_id=job.account_id,
            error=f"{type(exc).__name__}: {exc}",
        )


def _handle_post(job: JobPayload, *, config: WorkerConfig, ig_factory) -> JobResult:
    image_path = str(job.data.get("image_path") or "")
    caption = job.data.get("caption")
    if not image_path:
        raise ValueError("image_path missing in job payload")
    if not Path(image_path).is_file():
        raise FileNotFoundError(f"image_path not found: {image_path}")

    context = AccountContext(
        account_id=job.account_id,
        username=config.instagram_username,
        password=config.instagram_password,
        proxy_url=config.proxy_url,
        session_path=str(Path(config.session_dir) / f"{job.account_id}.json"),
    )
    if ig_factory is None:
        ig_client = InstagramClient(context)
    else:
        ig_client = ig_factory(context)

    ig_client.login()
    publisher = PostPublisher(ig_client)

    if job.type == POST_FEED_QUEUE:
        outcome = publisher.publish_feed(image_path, caption)
    else:
        outcome = publisher.publish_story(image_path)

    return JobResult(
        job_id=job.job_id,
        status="success",
        account_id=job.account_id,
        result={"ig_media_id": outcome.ig_media_id},
    )


def _handle_scrape(
    job: JobPayload,
    *,
    config: WorkerConfig,
    ig_factory,
    redis_client: redis.Redis,
) -> JobResult:
    hashtag = str(job.data.get("hashtag") or "").strip()
    if not hashtag:
        raise ValueError("hashtag missing in job payload")

    context = AccountContext(
        account_id=job.account_id,
        username=config.instagram_username,
        password=config.instagram_password,
        proxy_url=config.proxy_url,
        session_path=str(Path(config.session_dir) / f"{job.account_id}.json"),
    )
    ig_client = ig_factory(context) if ig_factory is not None else InstagramClient(context)
    ig_client.login()

    rate_limiter = HourlyRateLimiter(redis_client)
    scraper = ProspectScraper(ig_client, rate_limiter)
    candidates = scraper.scrape(job.account_id, hashtag)

    return JobResult(
        job_id=job.job_id,
        status="success",
        account_id=job.account_id,
        result={
            "hashtag": hashtag,
            "hashtag_id": job.data.get("hashtag_id"),
            "candidates": candidates_to_payload(candidates),
        },
    )


def _setup_logging(level: str) -> None:
    logging.basicConfig(
        level=getattr(logging, level.upper(), logging.INFO),
        format="%(asctime)s %(levelname)s %(name)s %(message)s",
    )


if __name__ == "__main__":
    sys.exit(main())
