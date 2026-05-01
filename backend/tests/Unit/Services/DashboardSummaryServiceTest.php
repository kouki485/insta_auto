<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Account;
use App\Models\DmLog;
use App\Models\Prospect;
use App\Models\SafetyEvent;
use App\Services\AccountHealthService;
use App\Services\DashboardSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DashboardSummaryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_includes_health_score_from_health_service(): void
    {
        $account = $this->makeAccount();
        SafetyEvent::query()->create([
            'account_id' => $account->id,
            'event_type' => SafetyEvent::TYPE_RATE_LIMITED,
            'severity' => SafetyEvent::SEVERITY_WARNING,
            'details' => [],
            'occurred_at' => now()->subMinutes(10),
        ]);

        $service = new DashboardSummaryService(new AccountHealthService());
        $summary = $service->buildFor($account);

        $this->assertNotNull($summary);
        $this->assertSame(90, $summary['health_score']); // 100 - 10 (rate_limited)
        $this->assertSame('none', $summary['health_action']);
    }

    public function test_summary_uses_cache_until_forget_called(): void
    {
        $account = $this->makeAccount();
        $service = new DashboardSummaryService(new AccountHealthService());

        $summary1 = $service->buildFor($account);
        $this->assertSame(0, $summary1['prospects_pool']['new']);

        // Cache::remember を使うのでこの追加データは反映されないはず.
        Prospect::query()->create([
            'account_id' => $account->id,
            'ig_user_id' => 'cache-test',
            'ig_username' => 'cache_user',
            'tourist_score' => 70,
            'status' => Prospect::STATUS_NEW,
        ]);
        $cached = $service->buildFor($account);
        $this->assertSame($summary1, $cached);

        $service->forget($account->id);
        $fresh = $service->buildFor($account);
        $this->assertSame(1, $fresh['prospects_pool']['new']);
    }

    public function test_today_dm_sent_counts_only_today(): void
    {
        $account = $this->makeAccount();
        DmLog::query()->create([
            'account_id' => $account->id,
            'prospect_id' => Prospect::query()->create([
                'account_id' => $account->id,
                'ig_user_id' => 'p1',
                'ig_username' => 'p1',
                'status' => Prospect::STATUS_DM_SENT,
            ])->id,
            'language' => 'en',
            'message_sent' => 'today',
            'status' => DmLog::STATUS_SENT,
            'sent_at' => now()->setTimezone($account->timezone),
        ]);
        DmLog::query()->create([
            'account_id' => $account->id,
            'prospect_id' => Prospect::query()->create([
                'account_id' => $account->id,
                'ig_user_id' => 'p2',
                'ig_username' => 'p2',
                'status' => Prospect::STATUS_DM_SENT,
            ])->id,
            'language' => 'en',
            'message_sent' => 'two days ago',
            'status' => DmLog::STATUS_SENT,
            'sent_at' => now()->subDays(2),
        ]);

        Cache::flush();
        $service = new DashboardSummaryService(new AccountHealthService());
        $summary = $service->buildFor($account);

        $this->assertSame(1, $summary['today']['dm_sent']);
    }

    private function makeAccount(): Account
    {
        return Account::query()->create([
            'store_name' => 'うなら',
            'ig_username' => 'dash_'.uniqid(),
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
}
