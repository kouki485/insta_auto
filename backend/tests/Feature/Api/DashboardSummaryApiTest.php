<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\DmLog;
use App\Models\DmTemplate;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardSummaryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_returns_today_counts_and_pool_breakdown(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $account = $this->makeAccount();
        $template = DmTemplate::query()->create([
            'account_id' => $account->id,
            'language' => 'en',
            'template' => 'Hi {username}',
        ]);

        $prospectNew = $this->makeProspect($account, 'tourist_a', Prospect::STATUS_NEW);
        $prospectQueued = $this->makeProspect($account, 'tourist_b', Prospect::STATUS_QUEUED);
        $prospectDmSent = $this->makeProspect($account, 'tourist_c', Prospect::STATUS_DM_SENT);
        $prospectReplied = $this->makeProspect($account, 'tourist_d', Prospect::STATUS_REPLIED, repliedToday: true);

        DmLog::query()->create([
            'account_id' => $account->id,
            'prospect_id' => $prospectDmSent->id,
            'template_id' => $template->id,
            'language' => 'en',
            'message_sent' => 'Hi c',
            'status' => DmLog::STATUS_SENT,
            'sent_at' => now()->setTimezone($account->timezone),
        ]);

        $response = $this->getJson('/api/dashboard/summary')->assertOk();

        $response->assertJsonPath('data.today.dm_sent', 1)
            ->assertJsonPath('data.today.dm_replies', 1)
            ->assertJsonPath('data.today.dm_limit', $account->daily_dm_limit)
            ->assertJsonPath('data.prospects_pool.new', 1)
            ->assertJsonPath('data.prospects_pool.queued', 1)
            ->assertJsonPath('data.prospects_pool.dm_sent_total', 1)
            ->assertJsonPath('data.prospects_pool.replied_total', 1)
            ->assertJsonPath('data.health_score', 100)
            ->assertJsonPath('data.health_action', 'none');

        $this->assertCount(8, $response->json('data.weekly_trend'));
    }

    public function test_fresh_param_busts_cache_and_returns_latest_state(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $account = $this->makeAccount();

        $this->getJson('/api/dashboard/summary')->assertJsonPath('data.prospects_pool.new', 0);

        Prospect::query()->create([
            'account_id' => $account->id,
            'ig_user_id' => 'fresh-1',
            'ig_username' => 'fresh_one',
            'tourist_score' => 70,
            'status' => Prospect::STATUS_NEW,
        ]);
        // キャッシュが効いている間は反映されない.
        $this->getJson('/api/dashboard/summary')->assertJsonPath('data.prospects_pool.new', 0);

        // ?fresh=1 でキャッシュ破棄 + 再計算.
        $this->getJson('/api/dashboard/summary?fresh=1')
            ->assertJsonPath('data.prospects_pool.new', 1);
    }

    public function test_summary_does_not_leak_proxy_url(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $account = $this->makeAccount();

        $payload = $this->getJson('/api/dashboard/summary')->assertOk()->json('data');

        $this->assertArrayNotHasKey('proxy_url', $payload);
        $this->assertArrayNotHasKey('ig_password', $payload);
    }

    private function makeAccount(): Account
    {
        return Account::query()->create([
            'store_name' => 'うなら',
            'ig_username' => 'unara_dash_'.uniqid(),
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

    private function makeProspect(
        Account $account,
        string $username,
        string $status,
        bool $repliedToday = false,
    ): Prospect {
        return Prospect::query()->create([
            'account_id' => $account->id,
            'ig_user_id' => $username.'-'.uniqid(),
            'ig_username' => $username,
            'tourist_score' => 80,
            'status' => $status,
            'replied_at' => $repliedToday ? now()->setTimezone($account->timezone) : null,
        ]);
    }
}
