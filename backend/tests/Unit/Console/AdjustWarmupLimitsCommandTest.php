<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use App\Models\Account;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AdjustWarmupLimitsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_initializes_warmup_started_at_when_null(): void
    {
        $account = $this->makeAccount(['warmup_started_at' => null]);

        Carbon::setTestNow(Carbon::parse('2026-05-01 00:00:00'));
        $this->artisan('instaauto:adjust-warmup')->assertOk();

        $this->assertNotNull($account->fresh()->warmup_started_at);
    }

    public function test_week_1_keeps_dm_limit_5(): void
    {
        $account = $this->makeAccount([
            'warmup_started_at' => Carbon::parse('2026-04-30 00:00:00'),
            'daily_dm_limit' => 5,
        ]);
        Carbon::setTestNow(Carbon::parse('2026-05-01 00:00:00'));

        $this->artisan('instaauto:adjust-warmup')->assertOk();

        $this->assertSame(5, $account->fresh()->daily_dm_limit);
    }

    public function test_week_2_raises_to_10(): void
    {
        $account = $this->makeAccount([
            'warmup_started_at' => Carbon::parse('2026-04-20 00:00:00'),
            'daily_dm_limit' => 5,
        ]);
        Carbon::setTestNow(Carbon::parse('2026-05-01 00:00:00'));

        $this->artisan('instaauto:adjust-warmup')->assertOk();

        $this->assertSame(10, $account->fresh()->daily_dm_limit);
    }

    public function test_week_4_or_later_raises_to_20(): void
    {
        $account = $this->makeAccount([
            'warmup_started_at' => Carbon::parse('2026-03-01 00:00:00'),
            'daily_dm_limit' => 5,
        ]);
        Carbon::setTestNow(Carbon::parse('2026-05-01 00:00:00'));

        $this->artisan('instaauto:adjust-warmup')->assertOk();

        $this->assertSame(20, $account->fresh()->daily_dm_limit);
    }

    public function test_paused_accounts_are_skipped(): void
    {
        $account = $this->makeAccount([
            'status' => Account::STATUS_PAUSED,
            'warmup_started_at' => Carbon::parse('2026-03-01 00:00:00'),
            'daily_dm_limit' => 5,
        ]);
        Carbon::setTestNow(Carbon::parse('2026-05-01 00:00:00'));

        $this->artisan('instaauto:adjust-warmup')->assertOk();

        $this->assertSame(5, $account->fresh()->daily_dm_limit);
    }

    private function makeAccount(array $overrides = []): Account
    {
        return Account::query()->create(array_merge([
            'store_name' => 'Demo Store',
            'ig_username' => 'demo_'.uniqid(),
            'ig_session_path' => '/storage/sessions/1.json',
            'proxy_url' => 'http://u:p@example.com',
            'ig_password' => 'secret',
            'daily_dm_limit' => 5,
            'daily_follow_limit' => 5,
            'daily_like_limit' => 30,
            'status' => Account::STATUS_ACTIVE,
            'timezone' => 'Asia/Tokyo',
        ], $overrides));
    }
}
