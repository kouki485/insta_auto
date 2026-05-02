<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Worker\WorkerQueue;
use App\Services\Worker\WorkerQueueService;
use Illuminate\Support\Facades\Redis;
use Mockery\MockInterface;
use Tests\TestCase;

class WorkerQueueServiceTest extends TestCase
{
    public function test_dispatch_returns_uuid_and_lpushes_payload(): void
    {
        $captured = [];
        Redis::shouldReceive('connection')
            ->andReturnUsing(function () use (&$captured) {
                return new class($captured) {
                    public function __construct(private array &$captured) {}

                    public function lpush(string $queue, array $items): int
                    {
                        $this->captured[] = ['queue' => $queue, 'items' => $items];

                        return 1;
                    }
                };
            });

        $service = new WorkerQueueService();
        $jobId = $service->dispatch(WorkerQueue::DM, ['echo' => 'hi'], accountId: 7);

        $this->assertNotEmpty($jobId);
        $this->assertSame(WorkerQueue::key(WorkerQueue::DM), $captured[0]['queue']);
        $payload = json_decode($captured[0]['items'][0], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($jobId, $payload['job_id']);
        $this->assertSame(7, $payload['account_id']);
        $this->assertSame('dm', $payload['type']);
        $this->assertSame(['echo' => 'hi'], $payload['data']);
        $this->assertSame(0, $payload['retry_count']);
    }

    public function test_pop_result_returns_decoded_array(): void
    {
        Redis::shouldReceive('connection')
            ->andReturn(new class {
                public function rpop(string $queue): string|false
                {
                    return json_encode([
                        'job_id' => 'abc',
                        'status' => 'success',
                        'result' => ['ig_message_id' => 'm1'],
                        'error' => null,
                        'completed_at' => '2026-05-01T10:00:00Z',
                    ]);
                }
            });

        $service = new WorkerQueueService();
        $payload = $service->popResult();

        $this->assertNotNull($payload);
        $this->assertSame('abc', $payload['job_id']);
        $this->assertSame('success', $payload['status']);
        $this->assertSame('m1', $payload['result']['ig_message_id']);
    }

    public function test_pop_result_returns_null_when_queue_empty(): void
    {
        Redis::shouldReceive('connection')
            ->andReturn(new class {
                public function rpop(string $queue): string|false
                {
                    return false;
                }
            });

        $service = new WorkerQueueService();
        $this->assertNull($service->popResult());
    }

    public function test_pop_result_returns_null_on_invalid_json(): void
    {
        Redis::shouldReceive('connection')
            ->andReturn(new class {
                public function rpop(string $queue): string|false
                {
                    return 'not-json';
                }
            });

        $service = new WorkerQueueService();
        $this->assertNull($service->popResult());
    }

    public function test_worker_queue_key_uses_prefix(): void
    {
        $this->assertSame('instaauto:queue:dm', WorkerQueue::key('dm'));
        $this->assertSame('instaauto:queue:dm', WorkerQueue::key('instaauto:queue:dm'));
    }
}
