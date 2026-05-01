"""DM 連発検知を回避するためのノイズ動作 (設計書 §4.1.6).

50% 確率でフィード閲覧 / いいね / ストーリー閲覧 / ユーザー検索のいずれかを実行.
副作用は best-effort で例外は呑む(ログのみ)。SafetyGuard は呼び出し側で必要なら使う.
"""

from __future__ import annotations

import logging
import random
from typing import Callable

from src.instagram_client import InstagramClient

logger = logging.getLogger(__name__)

NOISE_PROBABILITY = 0.5
JAPANESE_FOOD_ACCOUNTS = (
    "tabelog",
    "japanesefood",
    "asakusafoodtour",
    "tokyoeats",
)


def perform(
    client: InstagramClient,
    *,
    random_fn: Callable[[], float] | None = None,
    choice_fn: Callable[[list], object] | None = None,
) -> str | None:
    """ノイズ動作を 50% で実行する。実行した動作名を返す(スキップ時は None)."""
    rnd = random_fn or random.random
    if rnd() >= NOISE_PROBABILITY:
        return None

    actions: list[tuple[str, Callable[[], None]]] = [
        ("feed_timeline", lambda: client.raw.feed_timeline(amount=random.randint(3, 10))),  # type: ignore[attr-defined]
        ("user_info_lookup", lambda: client.raw.user_info_by_username(  # type: ignore[attr-defined]
            random.choice(JAPANESE_FOOD_ACCOUNTS)
        )),
        ("user_stories", lambda: client.raw.user_stories(  # type: ignore[attr-defined]
            client.raw.user_id_from_username(random.choice(JAPANESE_FOOD_ACCOUNTS))  # type: ignore[attr-defined]
        )),
        # delay_only は他アクションが利用不可な場合のフォールバック (テストでも使用).
        ("delay_only", lambda: client.delay()),
    ]
    name, action = (choice_fn or random.choice)(actions)  # type: ignore[arg-type]

    try:
        action()
    except Exception as exc:  # noqa: BLE001 - ノイズ失敗は致命的ではない
        logger.warning("noise_action_failed", extra={"action": name, "error": str(exc)})
        return None

    client.delay()
    return name
