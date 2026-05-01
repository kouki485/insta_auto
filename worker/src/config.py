"""環境変数を一元管理する設定モジュール."""

from __future__ import annotations

import os
from dataclasses import dataclass

from dotenv import load_dotenv

load_dotenv()


@dataclass(frozen=True)
class WorkerConfig:
    db_host: str
    db_port: int
    db_user: str
    db_password: str
    db_name: str

    redis_host: str
    redis_port: int
    redis_db: int

    instagram_username: str
    instagram_password: str
    proxy_url: str
    session_dir: str

    sentry_dsn: str
    slack_webhook_url: str

    log_level: str
    worker_queue_timeout: int

    @classmethod
    def from_env(cls) -> "WorkerConfig":
        return cls(
            db_host=os.getenv("DB_HOST", "mysql"),
            db_port=int(os.getenv("DB_PORT", "3306")),
            db_user=os.getenv("DB_USER", "unara"),
            db_password=os.getenv("DB_PASSWORD", ""),
            db_name=os.getenv("DB_NAME", "unara"),
            redis_host=os.getenv("REDIS_HOST", "redis"),
            redis_port=int(os.getenv("REDIS_PORT", "6379")),
            redis_db=int(os.getenv("REDIS_DB", "0")),
            instagram_username=os.getenv("INSTAGRAM_USERNAME", ""),
            instagram_password=os.getenv("INSTAGRAM_PASSWORD", ""),
            proxy_url=os.getenv("PROXY_URL", ""),
            session_dir=os.getenv("SESSION_DIR", "/storage/sessions"),
            sentry_dsn=os.getenv("SENTRY_DSN", ""),
            slack_webhook_url=os.getenv("SLACK_WEBHOOK_URL", ""),
            log_level=os.getenv("LOG_LEVEL", "INFO"),
            worker_queue_timeout=int(os.getenv("WORKER_QUEUE_TIMEOUT", "30")),
        )
