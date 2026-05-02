<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Account;
use App\Models\SafetyEvent;
use App\Services\AccountHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountHealthServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_max_score_when_no_events(): void
    {
        $account = $this->makeAccount();
        $service = new AccountHealthService();

        $result = $service->evaluate($account);

        $this->assertSame(100, $result['score']);
        $this->assertSame(AccountHealthService::ACTION_NONE, $result['action']);
    }

    public function test_subtracts_per_event_severity(): void
    {
        $account = $this->makeAccount();
        // rate_limited x2 (-20), action_blocked x1 (-30) = 50
        $this->logEvent($account, SafetyEvent::TYPE_RATE_LIMITED);
        $this->logEvent($account, SafetyEvent::TYPE_RATE_LIMITED);
        $this->logEvent($account, SafetyEvent::TYPE_ACTION_BLOCKED);

        $service = new AccountHealthService();
        $result = $service->evaluate($account);

        $this->assertSame(50, $result['score']);
        $this->assertSame(AccountHealthService::ACTION_NONE, $result['action']);
    }

    public function test_halve_action_when_score_below_50(): void
    {
        $account = $this->makeAccount();
        // challenge_required (-50) → score=50, さらに rate_limited (-10) で 40
        $this->logEvent($account, SafetyEvent::TYPE_CHALLENGE_REQUIRED);
        $this->logEvent($account, SafetyEvent::TYPE_RATE_LIMITED);

        $service = new AccountHealthService();
        $result = $service->evaluate($account);

        $this->assertSame(40, $result['score']);
        $this->assertSame(AccountHealthService::ACTION_HALVE_DM_LIMIT, $result['action']);
    }

    public function test_auto_pause_action_when_score_below_30(): void
    {
        $account = $this->makeAccount();
        // challenge_required (-50) + feedback_required (-40) = 10
        $this->logEvent($account, SafetyEvent::TYPE_CHALLENGE_REQUIRED);
        $this->logEvent($account, SafetyEvent::TYPE_FEEDBACK_REQUIRED);

        $service = new AccountHealthService();
        $result = $service->evaluate($account);

        $this->assertLessThan(30, $result['score']);
        $this->assertSame(AccountHealthService::ACTION_AUTO_PAUSE, $result['action']);
    }

    public function test_only_recent_24h_events_counted(): void
    {
        $account = $this->makeAccount();
        // 25 時間前は除外される
        SafetyEvent::query()->create([
            'account_id' => $account->id,
            'event_type' => SafetyEvent::TYPE_CHALLENGE_REQUIRED,
            'severity' => SafetyEvent::SEVERITY_CRITICAL,
            'details' => [],
            'occurred_at' => now()->subHours(25),
        ]);

        $service = new AccountHealthService();
        $result = $service->evaluate($account);

        $this->assertSame(100, $result['score']);
    }

    private function makeAccount(): Account
    {
        return Account::query()->create([
            'store_name' => 'Demo Store',
            'ig_username' => 'health_'.uniqid(),
            'ig_session_path' => '/storage/sessions/1.json',
            'proxy_url' => 'http://u:p@example.com',
            'ig_password' => 'secret',
            'daily_dm_limit' => 5,
            'daily_follow_limit' => 5,
            'daily_like_limit' => 30,
            'status' => Account::STATUS_ACTIVE,
            'timezone' => 'Asia/Tokyo',
        ]);
    }

    private function logEvent(Account $account, string $eventType): void
    {
        SafetyEvent::query()->create([
            'account_id' => $account->id,
            'event_type' => $eventType,
            'severity' => SafetyEvent::SEVERITY_WARNING,
            'details' => [],
            'occurred_at' => now()->subMinutes(10),
        ]);
    }
}
