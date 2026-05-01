<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Account;
use App\Models\DmLog;
use App\Models\DmTemplate;
use App\Models\PostSchedule;
use App\Models\Prospect;
use App\Models\SafetyEvent;
use App\Services\Worker\WorkerQueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ProcessWorkerResultsCommandTest extends TestCase
{
    use RefreshDatabase;

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
