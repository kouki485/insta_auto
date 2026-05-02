<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\PostSchedule;
use App\Services\PostImageStorage;
use App\Services\Worker\WorkerQueue;
use App\Services\Worker\WorkerQueueService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 期限到達した投稿予約を Python Worker のキューに投入する.
 * 設計書 §3.3.1 / §3.3.2 と Phase 2-C のスケジューラ仕様に準拠.
 */
class DispatchScheduledPostsCommand extends Command
{
    protected $signature = 'instaauto:dispatch-scheduled-posts {--limit=20 : 1 回の起動で処理する最大件数}';

    protected $description = 'scheduled_at が到達した post_schedules を Worker キューに投入する.';

    public function handle(WorkerQueueService $queue, PostImageStorage $images): int
    {
        $limit = (int) $this->option('limit');

        $rows = PostSchedule::query()
            ->whereIn('status', [PostSchedule::STATUS_SCHEDULED])
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            return self::SUCCESS;
        }

        $dispatched = 0;
        foreach ($rows as $post) {
            /** @var Account|null $account */
            $account = Account::query()->find($post->account_id);
            if ($account === null) {
                $post->update([
                    'status' => PostSchedule::STATUS_FAILED,
                    'error_message' => 'account not found',
                ]);

                continue;
            }
            if (! $account->isActive()) {
                Log::warning('post_skipped_account_not_active', [
                    'post_id' => $post->id,
                    'account_status' => $account->status,
                ]);

                continue;
            }

            $queueName = $post->type === PostSchedule::TYPE_STORY
                ? WorkerQueue::POST_STORY
                : WorkerQueue::POST_FEED;

            // CAS 更新: 同レコードを別プロセスが先取りする競合を防ぐ.
            $claimed = PostSchedule::query()
                ->where('id', $post->id)
                ->where('status', PostSchedule::STATUS_SCHEDULED)
                ->update([
                    'status' => PostSchedule::STATUS_POSTING,
                    'updated_at' => now(),
                ]);

            if ($claimed === 0) {
                Log::info('post_already_claimed_by_other_worker', ['post_id' => $post->id]);

                continue;
            }

            try {
                $jobId = $queue->dispatch($queueName, [
                    'post_id' => $post->id,
                    'image_path' => $images->absolutePath($post->image_path),
                    'image_relative_path' => $post->image_path,
                    'caption' => $post->caption,
                ], $account->id);

                $post->forceFill([
                    'worker_job_id' => $jobId,
                    'error_message' => null,
                ])->save();
            } catch (\Throwable $e) {
                // dispatch 失敗時は status を scheduled に戻して再試行可能にする.
                Log::error('post_dispatch_failed', [
                    'post_id' => $post->id,
                    'error' => $e->getMessage(),
                ]);
                $post->forceFill([
                    'status' => PostSchedule::STATUS_SCHEDULED,
                    'error_message' => $e->getMessage(),
                ])->save();

                continue;
            }

            $dispatched++;
        }

        $this->info("dispatched {$dispatched} post job(s)");

        return self::SUCCESS;
    }
}
