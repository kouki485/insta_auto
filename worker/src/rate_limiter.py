"""Redis Sorted Set ベースの真のスライディングウィンドウレートリミッタ.

設計書 §3.1.3:
- ハッシュタグ検索: 直近 60 分で 10 タグまで
- プロフィール取得: 直近 60 分で 50 ユーザーまで

固定バケット方式ではバケット境界でバーストが発生し BAN リスクが高まるため、
ZSET (score=timestamp) で過去 60 分以内のイベント数を厳密に管理する.
"""

from __future__ import annotations

import logging
import time
from typing import Protocol

logger = logging.getLogger(__name__)

WINDOW_SECONDS = 3600


class _RedisLike(Protocol):
    def pipeline(self): ...

    def zremrangebyrank(self, key: str, start: int, end: int) -> int: ...


class HourlyRateLimiter:
    def __init__(self, client: _RedisLike, prefix: str = "instaauto:rl:") -> None:
        self._client = client
        self._prefix = prefix

    def _key(self, scope: str, account_id: int) -> str:
        return f"{self._prefix}{scope}:{account_id}"

    def acquire(
        self,
        scope: str,
        account_id: int,
        limit: int,
        *,
        hour_bucket: int | None = None,  # 後方互換: 旧 API 引数 (未使用)
        now_ts: float | None = None,
    ) -> bool:
        """過去 60 分のイベント数が limit 以下になるよう真のスライディングウィンドウで判定する."""
        now = now_ts if now_ts is not None else time.time()
        key = self._key(scope, account_id)
        member = f"{now}:{scope}:{account_id}"

        pipe = self._client.pipeline()
        pipe.zremrangebyscore(key, 0, now - WINDOW_SECONDS)
        pipe.zadd(key, {member: now})
        pipe.zcard(key)
        pipe.expire(key, WINDOW_SECONDS)
        results = pipe.execute()
        count = int(results[2])

        if count > limit:
            # 超過分を削除して後続呼び出しに影響させない.
            self._client.zremrangebyrank(key, -1, -1)
            logger.warning(
                "rate_limit_exceeded",
                extra={"scope": scope, "account_id": account_id, "count": count, "limit": limit},
            )
            return False
        return True
