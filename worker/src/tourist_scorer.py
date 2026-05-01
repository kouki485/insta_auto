"""観光客スコアリング (設計書 §3.1.2).

入力データ構造は instagrapi の UserShort / Media に依存するが、テスト容易性のため
事前に PlainPython dict / dataclass に正規化した値を渡す前提.
"""

from __future__ import annotations

import logging
import re
from dataclasses import dataclass, field
from typing import Iterable, Sequence

from langdetect import DetectorFactory, LangDetectException, detect

DetectorFactory.seed = 0  # 再現性のため

logger = logging.getLogger(__name__)


TOURIST_SCORE_THRESHOLD = 60
MIN_FOLLOWER = 3000
HIGH_FOLLOWER = 10_000

# 設計書 §3.1.2 と一致させた最小集合 + 一般的な多言語拡張.
# 「旅行」は中国語/日本語両方で使用される。Hangul の '여행' は韓国語の "travel".
TRAVEL_KEYWORDS = (
    "trip",
    "travel",
    "vacation",
    "tokyo",
    "japan",
    "旅行",
    "여행",
    "voyage",
)


@dataclass(frozen=True)
class UserInfo:
    follower_count: int
    bio: str
    full_name: str
    following_count: int = 0
    post_count: int = 0


@dataclass(frozen=True)
class PostInfo:
    caption: str = ""
    location_country: str | None = None
    detected_lang: str | None = None


@dataclass(frozen=True)
class ScoreBreakdown:
    score: int
    reasons: list[str] = field(default_factory=list)


def calculate_tourist_score(
    user: UserInfo,
    recent_posts: Sequence[PostInfo],
) -> ScoreBreakdown:
    """設計書 §3.1.2 のロジックを忠実に実装する."""
    reasons: list[str] = []

    # フォロワー数(必須条件: 3000 以上)
    if user.follower_count < MIN_FOLLOWER:
        return ScoreBreakdown(score=0, reasons=["follower_below_3000"])

    score = 0
    if user.follower_count >= HIGH_FOLLOWER:
        score += 20
        reasons.append("follower_10k_plus(+20)")
    else:
        score += 10
        reasons.append("follower_3k_plus(+10)")

    # プロフィール文の言語(日本語以外で +30)
    bio_lang = _safe_detect_language(user.bio)
    if bio_lang and bio_lang != "ja":
        score += 30
        reasons.append(f"bio_non_ja({bio_lang})(+30)")

    # フルネームの言語(日本語以外で +15)
    if user.full_name and not _is_japanese_name(user.full_name):
        score += 15
        reasons.append("name_non_ja(+15)")

    # 直近投稿の位置情報多様性
    countries = _unique_countries(recent_posts)
    if len(countries) >= 2:
        score += 20
        reasons.append(f"countries={len(countries)}(+20)")
    elif len(countries) == 1 and "Japan" in countries:
        score += 10
        reasons.append("japan_only_location(+10)")

    # 直近投稿に旅行関連タグ
    if _has_travel_keywords(recent_posts):
        score += 15
        reasons.append("travel_keywords(+15)")

    # ペナルティ: 直近投稿の日本語比率が高い
    if _japanese_post_ratio(recent_posts) > 0.5:
        score -= 30
        reasons.append("japanese_post_ratio>0.5(-30)")

    score = max(0, min(100, score))
    return ScoreBreakdown(score=score, reasons=reasons)


def is_tourist(score: int) -> bool:
    return score >= TOURIST_SCORE_THRESHOLD


# ---- helpers --------------------------------------------------------------

_HIRAGANA_KATAKANA_RE = re.compile(r"[぀-ゟ゠-ヿ]")
_CJK_RE = re.compile(r"[一-鿿]")


def _safe_detect_language(text: str | None) -> str | None:
    """langdetect.detect の結果を安全に返す. 短すぎる/空文字なら None."""
    if not text:
        return None
    candidate = text.strip()
    if len(candidate) < 4:
        return None
    try:
        return detect(candidate)
    except LangDetectException:
        return None


def _is_japanese_name(name: str) -> bool:
    """ひらがな/カタカナを含むか、ASCII が全くなく漢字のみで構成されている場合に True.

    判定が難しいケース(英文字+漢字混在、短い英語名など)は False(=外国人と推定)に倒す.
    """
    if _HIRAGANA_KATAKANA_RE.search(name):
        return True
    cjk_count = len(_CJK_RE.findall(name))
    ascii_letters = sum(1 for ch in name if ch.isascii() and ch.isalpha())
    if cjk_count > 0 and ascii_letters == 0:
        return True
    return False


def _unique_countries(posts: Iterable[PostInfo]) -> set[str]:
    return {p.location_country for p in posts if p.location_country}


def _has_travel_keywords(posts: Iterable[PostInfo]) -> bool:
    for post in posts:
        text = (post.caption or "").lower()
        for keyword in TRAVEL_KEYWORDS:
            if keyword.lower() in text:
                return True
    return False


def _japanese_post_ratio(posts: Sequence[PostInfo]) -> float:
    if not posts:
        return 0.0
    detectable = 0
    japanese = 0
    for post in posts:
        lang = post.detected_lang or _safe_detect_language(post.caption)
        if lang is None:
            continue
        detectable += 1
        if lang == "ja":
            japanese += 1
    if detectable == 0:
        return 0.0
    return japanese / detectable
