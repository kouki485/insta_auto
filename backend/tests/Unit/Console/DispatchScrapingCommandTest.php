<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use App\Models\Account;
use App\Models\HashtagWatchlist;
use App\Services\Worker\WorkerQueue;
use App\Services\Worker\WorkerQueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class DispatchScrapingCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatches_active_hashtags_in_priority_order(): void
    {
        $account = $this->makeAccount();
        $low = HashtagWatchlist::query()->create([
            'account_id' => $account->id,
            'hashtag' => 'lowtag',
            'priority' => 3,
            'active' => true,
        ]);
        $high = HashtagWatchlist::query()->create([
            'account_id' => $account->id,
            'hashtag' => 'hightag',
            'priority' => 9,
            'active' => true,
        ]);
        $inactive = HashtagWatchlist::query()->create([
            'account_id' => $account->id,
            'hashtag' => 'paused',
            'priority' => 10,
            'active' => false,
        ]);

        $captured = [];
        $service = Mockery::mock(WorkerQueueService::class);
        $service->shouldReceive('dispatch')
            ->andReturnUsing(function (string $queue, array $data, int $accountId) use (&$captured) {
                $captured[] = $data['hashtag'];

                return 'job-'.count($captured);
            });
        $this->app->instance(WorkerQueueService::class, $service);

        $this->artisan('instaauto:dispatch-scraping')->assertOk();

        $this->assertSame(['hightag', 'lowtag'], $captured);
        // last_scraped_at は ProcessWorkerResults の結果受信時に更新するため、
        // dispatch 直後はまだ null のまま.
        $this->assertNull($high->fresh()->last_scraped_at);
        $this->assertNull($low->fresh()->last_scraped_at);
        $this->assertNull($inactive->fresh()->last_scraped_at);
    }

    public function test_uses_scrape_queue_name(): void
    {
        $account = $this->makeAccount();
        HashtagWatchlist::query()->create([
            'account_id' => $account->id,
            'hashtag' => 'asakusa',
            'priority' => 10,
            'active' => true,
        ]);

        $service = Mockery::mock(WorkerQueueService::class);
        $service->shouldReceive('dispatch')
            ->once()
            ->with(WorkerQueue::SCRAPE, Mockery::any(), $account->id)
            ->andReturn('job-id');
        $this->app->instance(WorkerQueueService::class, $service);

        $this->artisan('instaauto:dispatch-scraping')->assertOk();
    }

    public function test_skips_paused_accounts(): void
    {
        $account = $this->makeAccount(['status' => Account::STATUS_PAUSED]);
        HashtagWatchlist::query()->create([
            'account_id' => $account->id,
            'hashtag' => 'asakusa',
            'priority' => 10,
            'active' => true,
        ]);

        $service = Mockery::mock(WorkerQueueService::class);
        $service->shouldNotReceive('dispatch');
        $this->app->instance(WorkerQueueService::class, $service);

        $this->artisan('instaauto:dispatch-scraping')->assertOk();
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
