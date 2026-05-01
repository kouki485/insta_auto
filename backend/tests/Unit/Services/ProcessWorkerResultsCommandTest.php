<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Account;
use App\Models\DmLog;
use App\Models\DmTemplate;
use App\Models\HashtagWatchlist;
use App\Models\PostSchedule;
use App\Models\Prospect;
use App\Models\SafetyEvent;
use App\Services\SlackNotifier;
use App\Services\Worker\WorkerQueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Mockery;
use Tests\TestCase;

class ProcessWorkerResultsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // テストでは空 webhook を注入し Slack 通知を実際には送らない.
        $this->app->instance(SlackNotifier::class, new SlackNotifier($this->app->make(HttpFactory::class), ''));
    }

    public function test_dm_log_success_marks_log_sent_and_prospect_dm_sent(): void
    {
        [$account, $prospect, $log] = $this->seedDmLog('job-1');

        $service = Mockery::mock(WorkerQueueService::class);
        $service->shouldReceive('popResult')
            ->andReturn([
                'job_id' => 'job-1',
                'status' => 'success',
                'result' => ['ig_message_id' => 'ig-100'],
                'error' => null,
            ], null);
        $this->app->instance(WorkerQueueService::class, $service);

        $this->artisan('unara:process-results')->assertOk();

        $log->refresh();
        $this->assertSame(DmLog::STATUS_SENT, $log->status);
        $this->assertSame('ig-100', $log->ig_message_id);
        $this->assertNotNull($log->sent_at);

        $this->assertSame(Prospect::STATUS_DM_SENT, $prospect->fresh()->status);
        $this->assertNotNull($prospect->fresh()->dm_sent_at);
    }

    public function test_dm_log_failure_marks_log_failed(): void
    {
        [, , $log] = $this->seedDmLog('job-2');

        $service = Mockery::mock(WorkerQueueService::class);
        $service->shouldReceive('popResult')
            ->andReturn([
                'job_id' => 'job-2',
                'status' => 'failure',
                'error' => 'rate_limited',
            ], null);
        $this->app->instance(WorkerQueueService::class, $service);

        $this->artisan('unara:process-results')->assertOk();

        $log->refresh();
        $this->assertSame(DmLog::STATUS_FAILED, $log->status);
        $this->assertSame('rate_limited', $log->error_message);
    }

    public function test_post_schedule_failure_logs_safety_event_warning(): void
    {
        $account = $this->makeAccount();
        $post = PostSchedule::query()->create([
            'account_id' => $account->id,
            'type' => PostSchedule::TYPE_FEED,
            'image_path' => 'images/test.jpg',
            'scheduled_at' => now(),
            'status' => PostSchedule::STATUS_POSTING,
            'worker_job_id' => 'job-fail-1',
        ]);

        $service = Mockery::mock(WorkerQueueService::class);
        $service->shouldReceive('popResult')
            ->andReturn([
                'job_id' => 'job-fail-1',
                'status' => 'failure',
                'error' => 'PleaseWaitFewMinutes: too fast',
            ], null);
        $this->app->instance(WorkerQueueService::class, $service);

        $this->artisan('unara:process-results')->assertOk();

        $this->assertSame(PostSchedule::STATUS_FAILED, $post->fresh()->status);

        $event = SafetyEvent::query()->where('account_id', $account->id)->first();
        $this->assertNotNull($event);
        $this->assertSame(SafetyEvent::TYPE_ACTION_BLOCKED, $event->event_type);
        $this->assertSame(SafetyEvent::SEVERITY_WARNING, $event->severity);
        $this->assertSame($post->id, $event->details['post_schedule_id']);
    }

    public function test_post_schedule_success_marks_post_posted(): void
    {
        $account = $this->makeAccount();
        $post = PostSchedule::query()->create([
            'account_id' => $account->id,
            'type' => PostSchedule::TYPE_FEED,
            'image_path' => 'images/test.jpg',
            'caption' => 'hi',
            'scheduled_at' => now(),
            'status' => PostSchedule::STATUS_POSTING,
            'worker_job_id' => 'job-3',
        ]);

        $service = Mockery::mock(WorkerQueueService::class);
        $service->shouldReceive('popResult')
            ->andReturn([
                'job_id' => 'job-3',
                'status' => 'success',
                'result' => ['ig_media_id' => 'ig-media-1'],
                'error' => null,
            ], null);
        $this->app->instance(WorkerQueueService::class, $service);

        $this->artisan('unara:process-results')->assertOk();

        $post->refresh();
        $this->assertSame(PostSchedule::STATUS_POSTED, $post->status);
        $this->assertSame('ig-media-1', $post->ig_media_id);
        $this->assertNotNull($post->posted_at);
    }

    public function test_critical_safety_event_auto_pauses_account(): void
    {
        $account = $this->makeAccount();

        $service = Mockery::mock(WorkerQueueService::class);
        $service->shouldReceive('popResult')
            ->andReturn([
                'job_id' => 'job-x',
                'status' => 'failure',
                'account_id' => $account->id,
                'error' => 'ChallengeRequired: please confirm',
                'result' => [
                    'safety' => [
                        'auto_pause_requested' => true,
                        'events' => [[
                            'event_type' => SafetyEvent::TYPE_CHALLENGE_REQUIRED,
                            'severity' => SafetyEvent::SEVERITY_CRITICAL,
                            'details' => ['context' => 'direct_send'],
                        ]],
                    ],
                ],
            ], null);
        $this->app->instance(WorkerQueueService::class, $service);

        $this->artisan('unara:process-results')->assertOk();

        $this->assertSame(Account::STATUS_PAUSED, $account->fresh()->status);
        $this->assertDatabaseHas('safety_events', [
            'account_id' => $account->id,
            'event_type' => SafetyEvent::TYPE_CHALLENGE_REQUIRED,
            'severity' => SafetyEvent::SEVERITY_CRITICAL,
        ]);
        $this->assertDatabaseHas('safety_events', [
            'account_id' => $account->id,
            'event_type' => SafetyEvent::TYPE_AUTO_PAUSED,
        ]);
    }

    public function test_warning_safety_event_does_not_auto_pause(): void
    {
        $account = $this->makeAccount();

        $service = Mockery::mock(WorkerQueueService::class);
        $service->shouldReceive('popResult')
            ->andReturn([
                'job_id' => 'job-warn',
                'status' => 'failure',
                'account_id' => $account->id,
                'error' => 'PleaseWaitFewMinutes',
                'result' => [
                    'safety' => [
                        'auto_pause_requested' => false,
                        'events' => [[
                            'event_type' => SafetyEvent::TYPE_RATE_LIMITED,
                            'severity' => SafetyEvent::SEVERITY_WARNING,
                            'details' => ['context' => 'direct_send'],
                        ]],
                    ],
                ],
            ], null);
        $this->app->instance(WorkerQueueService::class, $service);

        $this->artisan('unara:process-results')->assertOk();

        $this->assertSame(Account::STATUS_ACTIVE, $account->fresh()->status);
        $this->assertDatabaseHas('safety_events', [
            'account_id' => $account->id,
            'event_type' => SafetyEvent::TYPE_RATE_LIMITED,
            'severity' => SafetyEvent::SEVERITY_WARNING,
        ]);
    }

    public function test_scrape_result_upserts_prospects_and_updates_hashtag_timestamp(): void
    {
        $account = $this->makeAccount();
        $tag = HashtagWatchlist::query()->create([
            'account_id' => $account->id,
            'hashtag' => 'asakusa',
            'priority' => 10,
            'active' => true,
        ]);

        $service = Mockery::mock(WorkerQueueService::class);
        $service->shouldReceive('popResult')
            ->andReturn([
                'job_id' => 'scrape-1',
                'status' => 'success',
                'account_id' => $account->id,
                'result' => [
                    'hashtag' => 'asakusa',
                    'hashtag_id' => $tag->id,
                    'candidates' => [
                        [
                            'ig_user_id' => 'u_alpha',
                            'ig_username' => 'alpha_traveler',
                            'full_name' => 'Alpha User',
                            'bio' => 'travel blogger',
                            'follower_count' => 12000,
                            'tourist_score' => 75,
                            'source_hashtag' => 'asakusa',
                            'source_post_url' => 'https://example.com/p/x',
                        ],
                        [
                            'ig_user_id' => 'u_beta',
                            'ig_username' => 'beta_tourist',
                            'follower_count' => 8000,
                            'tourist_score' => 65,
                        ],
                    ],
                ],
                'error' => null,
            ], null);
        $this->app->instance(WorkerQueueService::class, $service);

        $this->artisan('unara:process-results')->assertOk();

        $this->assertDatabaseHas('prospects', [
            'account_id' => $account->id,
            'ig_user_id' => 'u_alpha',
            'tourist_score' => 75,
            'is_tourist' => true,
            'source_hashtag' => 'asakusa',
        ]);
        $this->assertDatabaseHas('prospects', [
            'account_id' => $account->id,
            'ig_user_id' => 'u_beta',
            'tourist_score' => 65,
        ]);
        $this->assertNotNull($tag->fresh()->last_scraped_at);
    }

    public function test_scrape_result_does_not_overwrite_existing_status(): void
    {
        $account = $this->makeAccount();
        Prospect::query()->create([
            'account_id' => $account->id,
            'ig_user_id' => 'u_dm_sent',
            'ig_username' => 'tourist_old',
            'tourist_score' => 70,
            'status' => Prospect::STATUS_DM_SENT,
            'dm_sent_at' => now(),
        ]);

        $service = Mockery::mock(WorkerQueueService::class);
        $service->shouldReceive('popResult')
            ->andReturn([
                'job_id' => 'scrape-keep',
                'status' => 'success',
                'account_id' => $account->id,
                'result' => [
                    'hashtag' => 'asakusa',
                    'candidates' => [[
                        'ig_user_id' => 'u_dm_sent',
                        'ig_username' => 'tourist_renamed',
                        'follower_count' => 20000,
                        'tourist_score' => 90,
                    ]],
                ],
                'error' => null,
            ], null);
        $this->app->instance(WorkerQueueService::class, $service);

        $this->artisan('unara:process-results')->assertOk();

        $row = Prospect::query()->where('ig_user_id', 'u_dm_sent')->firstOrFail();
        // status は dm_sent のまま、新規 DM は送られない.
        $this->assertSame(Prospect::STATUS_DM_SENT, $row->status);
        // メタデータ (username, score) は更新される.
        $this->assertSame('tourist_renamed', $row->ig_username);
        $this->assertSame(90, $row->tourist_score);
    }

    public function test_scrape_result_re_upsert_keeps_unique_per_account(): void
    {
        $account = $this->makeAccount();

        $service = Mockery::mock(WorkerQueueService::class);
        $service->shouldReceive('popResult')
            ->andReturn(
                [
                    'job_id' => 'scrape-2',
                    'status' => 'success',
                    'account_id' => $account->id,
                    'result' => [
                        'hashtag' => 'asakusa',
                        'candidates' => [[
                            'ig_user_id' => 'u_dup',
                            'ig_username' => 'dup_user',
                            'follower_count' => 9000,
                            'tourist_score' => 70,
                        ]],
                    ],
                    'error' => null,
                ],
                [
                    'job_id' => 'scrape-3',
                    'status' => 'success',
                    'account_id' => $account->id,
                    'result' => [
                        'hashtag' => 'sensoji',
                        'candidates' => [[
                            'ig_user_id' => 'u_dup',
                            'ig_username' => 'dup_user_renamed',
                            'follower_count' => 11000,
                            'tourist_score' => 80,
                        ]],
                    ],
                    'error' => null,
                ],
                null,
            );
        $this->app->instance(WorkerQueueService::class, $service);

        $this->artisan('unara:process-results')->assertOk();

        $this->assertSame(1, Prospect::query()->where('account_id', $account->id)->count());
        $row = Prospect::query()->where('account_id', $account->id)->firstOrFail();
        $this->assertSame('dup_user_renamed', $row->ig_username);
        $this->assertSame(80, $row->tourist_score);
        $this->assertSame('sensoji', $row->source_hashtag);
    }

    /**
     * @return array{0: Account, 1: Prospect, 2: DmLog}
     */
    private function seedDmLog(string $jobId): array
    {
        $account = $this->makeAccount();
        $prospect = Prospect::query()->create([
            'account_id' => $account->id,
            'ig_user_id' => '99999',
            'ig_username' => 'tourist_a',
            'tourist_score' => 80,
            'status' => Prospect::STATUS_QUEUED,
        ]);
        $template = DmTemplate::query()->create([
            'account_id' => $account->id,
            'language' => 'en',
            'template' => 'Hi {username}',
        ]);
        $log = DmLog::query()->create([
            'account_id' => $account->id,
            'prospect_id' => $prospect->id,
            'template_id' => $template->id,
            'language' => 'en',
            'message_sent' => 'Hi tourist_a',
            'status' => DmLog::STATUS_QUEUED,
            'worker_job_id' => $jobId,
        ]);

        return [$account, $prospect, $log];
    }

    private function makeAccount(): Account
    {
        return Account::query()->create([
            'store_name' => 'うなら',
            'ig_username' => 'unara_test_'.uniqid(),
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
