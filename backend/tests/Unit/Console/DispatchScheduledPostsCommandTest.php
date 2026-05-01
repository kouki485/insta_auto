<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use App\Models\Account;
use App\Models\PostSchedule;
use App\Services\Worker\WorkerQueue;
use App\Services\Worker\WorkerQueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class DispatchScheduledPostsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatches_due_feed_posts_and_marks_them_posting(): void
    {
        $account = $this->makeAccount();
        $post = PostSchedule::query()->create([
            'account_id' => $account->id,
            'type' => PostSchedule::TYPE_FEED,
            'image_path' => 'images/test.jpg',
            'caption' => 'Hi',
            'scheduled_at' => now()->subMinute(),
            'status' => PostSchedule::STATUS_SCHEDULED,
        ]);

        $service = Mockery::mock(WorkerQueueService::class);
        $service->shouldReceive('dispatch')
            ->once()
            ->with(WorkerQueue::POST_FEED, Mockery::on(function (array $data) use ($post) {
                return $data['post_id'] === $post->id
                    && $data['caption'] === 'Hi'
                    && str_ends_with($data['image_path'], 'images/test.jpg');
            }), $account->id)
            ->andReturn('job-uuid');
        $this->app->instance(WorkerQueueService::class, $service);

        $this->artisan('unara:dispatch-scheduled-posts')->assertOk();

        $post->refresh();
        $this->assertSame(PostSchedule::STATUS_POSTING, $post->status);
        $this->assertSame('job-uuid', $post->worker_job_id);
    }

    public function test_does_not_dispatch_future_posts(): void
    {
        $account = $this->makeAccount();
        PostSchedule::query()->create([
            'account_id' => $account->id,
            'type' => PostSchedule::TYPE_FEED,
            'image_path' => 'images/x.jpg',
            'scheduled_at' => now()->addHour(),
            'status' => PostSchedule::STATUS_SCHEDULED,
        ]);

        $service = Mockery::mock(WorkerQueueService::class);
        $service->shouldNotReceive('dispatch');
        $this->app->instance(WorkerQueueService::class, $service);

        $this->artisan('unara:dispatch-scheduled-posts')->assertOk();
    }

    public function test_skips_dispatch_when_account_is_paused(): void
    {
        $account = $this->makeAccount(['status' => Account::STATUS_PAUSED]);
        $post = PostSchedule::query()->create([
            'account_id' => $account->id,
            'type' => PostSchedule::TYPE_STORY,
            'image_path' => 'images/x.jpg',
            'scheduled_at' => now()->subMinute(),
            'status' => PostSchedule::STATUS_SCHEDULED,
        ]);

        $service = Mockery::mock(WorkerQueueService::class);
        $service->shouldNotReceive('dispatch');
        $this->app->instance(WorkerQueueService::class, $service);

        $this->artisan('unara:dispatch-scheduled-posts')->assertOk();

        $this->assertSame(PostSchedule::STATUS_SCHEDULED, $post->fresh()->status);
    }

    public function test_uses_post_story_queue_for_story_posts(): void
    {
        $account = $this->makeAccount();
        $post = PostSchedule::query()->create([
            'account_id' => $account->id,
            'type' => PostSchedule::TYPE_STORY,
            'image_path' => 'images/story.jpg',
            'scheduled_at' => now()->subMinute(),
            'status' => PostSchedule::STATUS_SCHEDULED,
        ]);

        $service = Mockery::mock(WorkerQueueService::class);
        $service->shouldReceive('dispatch')
            ->once()
            ->with(WorkerQueue::POST_STORY, Mockery::any(), $account->id)
            ->andReturn('story-job');
        $this->app->instance(WorkerQueueService::class, $service);

        $this->artisan('unara:dispatch-scheduled-posts')->assertOk();
        $this->assertSame(PostSchedule::STATUS_POSTING, $post->fresh()->status);
    }

    private function makeAccount(array $overrides = []): Account
    {
        return Account::query()->create(array_merge([
            'store_name' => 'うなら',
            'ig_username' => 'unara_disp_'.uniqid(),
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
