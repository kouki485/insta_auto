"""観光客スコアリングのテスト. langdetect の確率挙動はシード固定済み."""

from __future__ import annotations

from src.tourist_scorer import (
    TOURIST_SCORE_THRESHOLD,
    PostInfo,
    UserInfo,
    calculate_tourist_score,
    is_tourist,
)


def _eng_bio() -> str:
    return "Travel blogger from London. Currently in Tokyo for 2 weeks."


def _ja_bio() -> str:
    return "東京在住のフードブロガーです。よろしくお願いします。"


def test_below_min_follower_returns_zero() -> None:
    user = UserInfo(follower_count=2999, bio=_eng_bio(), full_name="John Smith")
    breakdown = calculate_tourist_score(user, [])
    assert breakdown.score == 0
    assert "follower_below_3000" in breakdown.reasons


def test_high_follower_english_bio_with_travel_keywords_passes_threshold() -> None:
    user = UserInfo(follower_count=15_000, bio=_eng_bio(), full_name="John Smith")
    posts = [
        PostInfo(
            caption="Loving Tokyo's street food! #travel #japan",
            location_country="Japan",
            detected_lang="en",
        ),
        PostInfo(
            caption="Paris was lovely last month",
            location_country="France",
            detected_lang="en",
        ),
    ]
    breakdown = calculate_tourist_score(user, posts)
    assert breakdown.score >= TOURIST_SCORE_THRESHOLD
    assert is_tourist(breakdown.score)


def test_japanese_post_ratio_penalty_drops_score() -> None:
    user = UserInfo(follower_count=12_000, bio=_eng_bio(), full_name="John Smith")
    posts = [
        PostInfo(caption="今日は浅草に行きました", detected_lang="ja"),
        PostInfo(caption="美味しい蕎麦でした", detected_lang="ja"),
        PostInfo(caption="銀座でランチ", detected_lang="ja"),
    ]
    breakdown = calculate_tourist_score(user, posts)
    assert "japanese_post_ratio>0.5(-30)" in breakdown.reasons
    # 30 ペナルティが適用されてもまだ言語/フォロワーで一定のスコアは残る


def test_japanese_full_name_does_not_award_name_bonus() -> None:
    user = UserInfo(follower_count=12_000, bio=_ja_bio(), full_name="田中太郎")
    posts = [
        PostInfo(caption="lovely tokyo trip", detected_lang="en", location_country="Japan"),
    ]
    breakdown = calculate_tourist_score(user, posts)
    # name_non_ja の reason は出ない
    assert all("name_non_ja" not in r for r in breakdown.reasons)


def test_score_never_exceeds_100() -> None:
    user = UserInfo(follower_count=200_000, bio=_eng_bio(), full_name="Marie Curie")
    posts = [
        PostInfo(caption="vacation in Tokyo!", detected_lang="en", location_country="Japan"),
        PostInfo(caption="paris was nice", detected_lang="en", location_country="France"),
        PostInfo(caption="hi from rome", detected_lang="en", location_country="Italy"),
    ]
    breakdown = calculate_tourist_score(user, posts)
    assert breakdown.score <= 100


def test_japan_only_location_awards_partial_credit() -> None:
    user = UserInfo(follower_count=5_000, bio=_eng_bio(), full_name="Lee Hyori")
    posts = [
        PostInfo(caption="tokyo food", detected_lang="en", location_country="Japan"),
    ]
    breakdown = calculate_tourist_score(user, posts)
    assert any("japan_only_location(+10)" == r for r in breakdown.reasons)
