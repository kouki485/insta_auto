"""SafetyGuard のテスト. Slack 通知は捕捉して呼び出しを確認する."""

from __future__ import annotations

from src.safety_guard import SafetyGuard


class _FakeChallenge(Exception):
    pass


_FakeChallenge.__name__ = "ChallengeRequired"
_FakeChallenge.__module__ = "instagrapi.exceptions"


class _FakeRateLimit(Exception):
    pass


_FakeRateLimit.__name__ = "PleaseWaitFewMinutes"
_FakeRateLimit.__module__ = "instagrapi.exceptions"


def test_records_critical_event_and_sets_auto_pause() -> None:
    guard = SafetyGuard(account_id=42)
    guard.record_exception(_FakeChallenge("confirm needed"), context="direct_send")

    verdict = guard.verdict
    assert verdict.auto_pause_requested is True
    assert verdict.events[0].event_type == "challenge_required"
    assert verdict.events[0].severity == "critical"
    payload = verdict.to_dict()
    assert payload["auto_pause_requested"] is True


def test_warning_event_does_not_request_auto_pause() -> None:
    guard = SafetyGuard(account_id=1)
    guard.record_exception(_FakeRateLimit("slow down"), context="direct_send")
    assert guard.verdict.auto_pause_requested is False
    assert guard.verdict.events[0].severity == "warning"


def test_critical_event_attempts_slack_notification(monkeypatch) -> None:
    sent: list[tuple[str, str]] = []

    monkeypatch.setattr(
        "src.safety_guard.notify_slack",
        lambda url, text: sent.append((url, text)),
    )

    guard = SafetyGuard(account_id=99, slack_webhook_url="https://hooks.example.com/x")
    guard.record_exception(_FakeChallenge("x"), context="dm")

    assert guard.verdict.events[0].event_type == "challenge_required"
    assert len(sent) == 1
    assert sent[0][0] == "https://hooks.example.com/x"
    assert "ChallengeRequired" in sent[0][1]


def test_warning_event_does_not_notify_slack(monkeypatch) -> None:
    sent: list[tuple[str, str]] = []
    monkeypatch.setattr(
        "src.safety_guard.notify_slack",
        lambda url, text: sent.append((url, text)),
    )
    guard = SafetyGuard(account_id=1, slack_webhook_url="https://hooks.example.com/y")
    guard.record_exception(_FakeRateLimit("slow"), context="dm")
    assert sent == []
