<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use App\Models\Account;
use App\Models\DmLog;
use App\Models\DmTemplate;
use App\Models\Prospect;
use App\Models\SafetyEvent;
use App\Services\SlackNotifier;
use App\Services\Worker\WorkerQueue;
use App\Services\Worker\WorkerQueueService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

class DispatchDmJobsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-05-04 12:00:00', 'Asia/Tokyo')->utc());
        CarbonImmutable::setTestNow(Carbon::getTestNow());

        // テストでは Slack 通知を無効化する (空の webhook URL を注入).
        $this->app->instance(SlackNotifier::class, new SlackNotifier($this->app->make(HttpFactory::class), ''));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_dispatches_one_dm_for_top_score_prospect(): void
    {
        $account = $this->makeAccount();
        DmTemplate::query()->create([
            'account_id' => $account->id,
            'language' => 'en',
            'template' => 'Hi {username}!',
            'active' => true,
        ]);
        $high = Prospect::query()->create($this->prospectAttrs($account, 'high', 90));
        $low = Prospect::query()->create($this->prospectAttrs($account, 'low', 65));

        $service = Mockery::mock(WorkerQueueService::class);
        $service->shouldReceive('dispatch')
            ->once()
            ->with(WorkerQueue::DM, Mockery::on(function (array $data) use ($high) {
                return $data['prospect_id'] === $high->id
                    && $data['ig_user_id'] === $high->ig_user_id
                    && $data['message'] === 'Hi high!';
            }), $account->id)
            ->andReturn('dm-job-1');
        $this->app->instance(WorkerQueueService::class, $service);

        $this->artisan('unara:dispatch-dm')->assertOk();

        $this->assertSame(Prospect::STATUS_QUEUED, $high->fresh()->status);
        $this->assertSame(Prospect::STATUS_NEW, $low->fresh()->status);
        $this->assertDatabaseHas('dm_logs', [
            'account_id' => $account->id,
            'prospect_id' => $high->id,
            'worker_job_id' => 'dm-job-1',
            'status' => DmLog::STATUS_QUEUED,
        ]);
    }

    public function test_skips_when_daily_limit_reached(): void
    {
        $account = $this->makeAccount(['daily_dm_limit' => 1]);
        DmTemplate::query()->create([
            'account_id' => $account->id,
            'language' => 'en',
            'template' => 'Hi {username}',
        ]);
        Prospect::query()->create($this->prospectAttrs($account, 'tourist', 90));
        DmLog::query()->create([
            'account_id' => $account->id,
            'prospect_id' => Prospect::query()->first()->id,
            'language' => 'en',
            'message_sent' => 'sent already',
            'status' => DmLog::STATUS_SENT,
            'created_at' => now(),
        ]);

        $service = Mockery::mock(WorkerQueueService::class);
        $service->shouldNotReceive('dispatch');
        $this->app->instance(WorkerQueueService::class, $service);

        $this->artisan('unara:dispatch-dm')->assertOk();
    }

    public function test_skips_outside_active_hours(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-04 23:00:00', 'Asia/Tokyo')->utc());
        $account = $this->makeAccount();
        DmTemplate::query()->create(['account_id' => $account->id, 'language' => 'en', 'template' => 'Hi']);
        Prospect::query()->create($this->prospectAttrs($account, 'tourist', 90));

        $service = Mockery::mock(WorkerQueueService::class);
        $service->shouldNotReceive('dispatch');
        $this->app->instance(WorkerQueueService::class, $service);

        $this->artisan('unara:dispatch-dm')->assertOk();
    }

    public function test_skips_on_weekend(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-02 12:00:00', 'Asia/Tokyo')->utc()); // 土曜
        $account = $this->makeAccount();
        DmTemplate::query()->create(['account_id' => $account->id, 'language' => 'en', 'template' => 'Hi']);
        Prospect::query()->create($this->prospectAttrs($account, 'tourist', 90));

        $service = Mockery::mock(WorkerQueueService::class);
        $service->shouldNotReceive('dispatch');
        $this->app->instance(WorkerQueueService::class, $service);

        $this->artisan('unara:dispatch-dm')->assertOk();
    }

    public function test_auto_pauses_when_health_score_below_30(): void
    {
        $account = $this->makeAccount();
        DmTemplate::query()->create(['account_id' => $account->id, 'language' => 'en', 'template' => 'Hi']);
        Prospect::query()->create($this->prospectAttrs($account, 'tourist', 90));
        SafetyEvent::query()->create([
            'account_id' => $account->id,
            'event_type' => SafetyEvent::TYPE_CHALLENGE_REQUIRED,
            'severity' => SafetyEvent::SEVERITY_CRITICAL,
            'details' => [],
            'occurred_at' => now()->subHour(),
        ]);
        SafetyEvent::query()->create([
            'account_id' => $account->id,
            'event_type' => SafetyEvent::TYPE_FEEDBACK_REQUIRED,
            'severity' => SafetyEvent::SEVERITY_CRITICAL,
            'details' => [],
            'occurred_at' => now()->subHour(),
        ]);

        $service = Mockery::mock(WorkerQueueService::class);
        $service->shouldNotReceive('dispatch');
        $this->app->instance(WorkerQueueService::class, $service);

        $this->artisan('unara:dispatch-dm')->assertOk();

        $this->assertSame(Account::STATUS_PAUSED, $account->fresh()->status);
        $this->assertDatabaseHas('safety_events', [
            'account_id' => $account->id,
            'event_type' => SafetyEvent::TYPE_AUTO_PAUSED,
            'severity' => SafetyEvent::SEVERITY_CRITICAL,
        ]);
    }

    private function makeAccount(array $overrides = []): Account
    {
        return Account::query()->create(array_merge([
            'store_name' => 'うなら',
            'ig_username' => 'unara_'.uniqid(),
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

    /**
     * @return array<string, mixed>
     */
    private function prospectAttrs(Account $account, string $username, int $score): array
    {
        return [
            'account_id' => $account->id,
            'ig_user_id' => $username.'-'.uniqid(),
            'ig_username' => $username,
            'detected_lang' => 'en',
            'tourist_score' => $score,
            'status' => Prospect::STATUS_NEW,
        ];
    }
}
