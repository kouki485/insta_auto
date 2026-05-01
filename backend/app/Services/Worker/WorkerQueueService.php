<?php

declare(strict_types=1);

namespace App\Services\Worker;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * Laravel(Producer) → Python Worker(Consumer) のキュー送出サービス.
 * 設計書 §1.3 の JSON プロトコルに従う.
 */
class WorkerQueueService
{
    public function __construct(private readonly string $connection = 'default') {}

    /**
     * @param  array<string, mixed>  $data
     *
     * 設計書 §1.3 の type フィールドは Worker のディスパッチキー(キュー名と同じ)を入れる.
     * 例: dm / scrape / post_feed / post_story.
     * Python Worker 側は payload.type ではなくキュー名で分岐する設計のため
     * 命名は WorkerQueue 定数に集約する。
     */
    public function dispatch(string $queue, array $data, int $accountId): string
    {
        $jobId = (string) Str::uuid();

        $payload = [
            'job_id' => $jobId,
            'account_id' => $accountId,
            'type' => $queue,
            'data' => $data,
            'created_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'retry_count' => 0,
        ];

        Redis::connection($this->connection)
            ->lpush(WorkerQueue::key($queue), [json_encode($payload, JSON_UNESCAPED_UNICODE)]);

        Log::info('worker_job_dispatched', [
            'job_id' => $jobId,
            'queue' => $queue,
            'account_id' => $accountId,
        ]);

        return $jobId;
    }

    /**
     * 結果キューから 1 件 RPOP する。timeout=0 で即時返却(空ならば null)。
     *
     * @return array<string, mixed>|null
     */
    public function popResult(): ?array
    {
        $raw = Redis::connection($this->connection)
            ->rpop(WorkerQueue::key(WorkerQueue::RESULT));

        if ($raw === null || $raw === false) {
            return null;
        }

        try {
            $decoded = json_decode((string) $raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            Log::warning('worker_result_invalid_json', [
                'raw' => $raw,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
