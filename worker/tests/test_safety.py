"""safety.classify_instagrapi_exception のテスト."""

from __future__ import annotations

from src.safety import classify_instagrapi_exception


class _FakeIgException(Exception):
    """instagrapi モジュールに見える擬似例外."""

    pass


_FakeIgException.__module__ = "instagrapi.exceptions"


class _FakeChallenge(_FakeIgException):
    pass


_FakeChallenge.__name__ = "ChallengeRequired"
_FakeChallenge.__module__ = "instagrapi.exceptions"


class _FakeFeedback(_FakeIgException):
    pass


_FakeFeedback.__name__ = "FeedbackRequired"
_FakeFeedback.__module__ = "instagrapi.exceptions"


def test_known_instagrapi_exceptions_map_to_critical() -> None:
    assert classify_instagrapi_exception(_FakeChallenge()) == ("challenge_required", "critical")
    assert classify_instagrapi_exception(_FakeFeedback()) == ("feedback_required", "critical")


def test_unknown_instagrapi_exception_falls_back_to_action_blocked() -> None:
    class _Mystery(_FakeIgException):
        pass

    _Mystery.__module__ = "instagrapi.exceptions"
    assert classify_instagrapi_exception(_Mystery()) == ("action_blocked", "warning")


def test_non_instagrapi_exception_returns_internal_error() -> None:
    """FileNotFoundError などは internal_error/info として記録 (Phase 4 で auto_pause しない)."""
    assert classify_instagrapi_exception(FileNotFoundError("missing")) == ("internal_error", "info")
    assert classify_instagrapi_exception(ValueError("bad")) == ("internal_error", "info")
