<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DmLog;
use App\Models\PostSchedule;
use App\Models\Prospect;
use App\Models\SafetyEvent;
use App\Services\Worker\WorkerQueueService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Python Worker が `unara:queue:result` に書き戻した結果を取り込む.
 * Phase 1 では DmLog / PostSchedule の status 更新のみ。
 * Phase 4 で safety_events への critical イベント連動を追加する。
 */
class ProcessWorkerResultsCommand extends Command
{
    protected $signature = 'unara:process-results {--max=200 : 1 回の起動で処理する最大件数}';

    protected $description = 'Python Worker からの結果キューを消費して DB に反映する.';

    public function handle(WorkerQueueService $service): int
    {
        $max = (int) $this->option('max');
        $processed = 0;

        while ($processed < $max) {
            $payload = $service->popResult();
            if ($payload === null) {
                break;
            }

            $this->apply($payload);
            $processed++;
        }

        $this->info("processed {$processed} result(s)");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function apply(array $payload): void
    {
        $jobId = (string) ($payload['job_id'] ?? '');
        $status = (string) ($payload['status'] ?? 'failure');
        $rawError = $payload['error'] ?? null;
        $error = match (true) {
            is_string($rawError) => $rawError,
            is_array($rawError) || is_object($rawError) => json_encode($rawError, JSON_UNESCAPED_UNICODE),
            default => null,
        };
        $result = is_array($payload['result'] ?? null) ? $payload['result'] : [];

        if ($jobId === '') {
            Log::warning('worker_result_missing_job_id', $payload);

            return;
        }

        DB::transaction(function () use ($jobId, $status, $error, $result): void {
            $dmLog = DmLog::query()->where('worker_job_id', $jobId)->first();
            if ($dmLog !== null) {
                $this->applyDmLogResult($dmLog, $status, $error, $result);

                return;
            }

            $post = PostSchedule::query()->where('worker_job_id', $jobId)->first();
            if ($post !== null) {
                $this->applyPostResult($post, $status, $error, $result);

                return;
            }
        });
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function applyDmLogResult(DmLog $log, string $status, ?string $error, array $result): void
    {
        if ($status === 'success') {
            $log->forceFill([
                'status' => DmLog::STATUS_SENT,
                'ig_message_id' => $result['ig_message_id'] ?? null,
                'sent_at' => now(),
                'error_message' => null,
            ])->save();

            Prospect::query()->where('id', $log->prospect_id)->update([
                'status' => Prospect::STATUS_DM_SENT,
                'dm_sent_at' => now(),
            ]);
        } else {
            $log->forceFill([
                'status' => DmLog::STATUS_FAILED,
                'error_message' => $error,
            ])->save();
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function applyPostResult(PostSchedule $post, string $status, ?string $error, array $result): void
    {
        if ($status === 'success') {
            $post->update([
                'status' => PostSchedule::STATUS_POSTED,
                'ig_media_id' => $result['ig_media_id'] ?? null,
                'posted_at' => now(),
                'error_message' => null,
            ]);

            return;
        }

        $post->update([
            'status' => PostSchedule::STATUS_FAILED,
            'error_message' => $error,
        ]);

        // 設計書 §7.3 ロギング規約: 投稿失敗は safety_events.warning として記録する.
        // Phase 4 で error 文字列から event_type を分類し critical/auto_pause を実装する.
        SafetyEvent::query()->create([
            'account_id' => $post->account_id,
            'event_type' => SafetyEvent::TYPE_ACTION_BLOCKED,
            'severity' => SafetyEvent::SEVERITY_WARNING,
            'details' => [
                'context' => 'post_publish_failed',
                'post_schedule_id' => $post->id,
                'post_type' => $post->type,
                'error' => $error,
            ],
            'occurred_at' => now(),
        ]);
    }
}
