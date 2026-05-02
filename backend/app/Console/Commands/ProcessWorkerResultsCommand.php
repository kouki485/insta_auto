<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\DmLog;
use App\Models\HashtagWatchlist;
use App\Models\PostSchedule;
use App\Models\Prospect;
use App\Models\SafetyEvent;
use App\Services\SlackNotifier;
use App\Services\Worker\WorkerQueueService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Python Worker が `instaauto:queue:result` に書き戻した結果を取り込む.
 * Phase 1 では DmLog / PostSchedule の status 更新のみ。
 * Phase 4 で safety_events への critical イベント連動を追加する。
 */
class ProcessWorkerResultsCommand extends Command
{
    protected $signature = 'instaauto:process-results {--max=200 : 1 回の起動で処理する最大件数}';

    protected $description = 'Python Worker からの結果キューを消費して DB に反映する.';

    public function __construct(private readonly SlackNotifier $slack)
    {
        parent::__construct();
    }

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

        $accountId = (int) ($payload['account_id'] ?? 0);

        DB::transaction(function () use ($jobId, $status, $error, $result, $payload, $accountId): void {
            // 設計書 §4.1.5 / §4.3: Worker が結果に safety を含めていれば永続化 + auto_pause 連動.
            $this->ingestSafetyEvents($accountId, $result);

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

            // 設計書 §3.1: scrape 結果は worker_job_id を保持していないので
            // result.candidates を直接 prospects に upsert する.
            if ($status === 'success' && isset($result['candidates']) && is_array($result['candidates'])) {
                if ($accountId > 0) {
                    $this->applyScrapeResult($accountId, $result);
                }
            }
        });
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function ingestSafetyEvents(int $accountId, array $result): void
    {
        if ($accountId <= 0) {
            return;
        }
        $safety = $result['safety'] ?? null;
        if (! is_array($safety)) {
            return;
        }
        $events = $safety['events'] ?? [];
        if (! is_array($events)) {
            return;
        }

        $criticalDetails = [];
        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }
            $type = (string) ($event['event_type'] ?? '');
            $severity = (string) ($event['severity'] ?? '');
            if ($type === '' || $severity === '') {
                continue;
            }
            SafetyEvent::query()->create([
                'account_id' => $accountId,
                'event_type' => $type,
                'severity' => $severity,
                'details' => is_array($event['details'] ?? null) ? $event['details'] : [],
                'occurred_at' => now(),
            ]);
            if ($severity === SafetyEvent::SEVERITY_CRITICAL) {
                $criticalDetails[] = [
                    'event_type' => $type,
                    'details' => $event['details'] ?? null,
                ];
            }
        }

        if (! empty($safety['auto_pause_requested']) || count($criticalDetails) > 0) {
            $this->autoPause($accountId, $criticalDetails);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $criticalDetails
     */
    private function autoPause(int $accountId, array $criticalDetails): void
    {
        $account = Account::query()->find($accountId);
        if ($account === null || $account->status === Account::STATUS_PAUSED) {
            return;
        }
        $account->forceFill(['status' => Account::STATUS_PAUSED])->save();
        SafetyEvent::query()->create([
            'account_id' => $accountId,
            'event_type' => SafetyEvent::TYPE_AUTO_PAUSED,
            'severity' => SafetyEvent::SEVERITY_CRITICAL,
            'details' => ['reason' => 'critical_event_from_worker', 'events' => $criticalDetails],
            'occurred_at' => now(),
        ]);
        $this->slack->notify(
            sprintf(
                ":rotating_light: account_id=%d auto-paused. Reason: %s",
                $accountId,
                json_encode($criticalDetails, JSON_UNESCAPED_UNICODE),
            ),
        );
        Log::warning('account_auto_paused_from_worker', [
            'account_id' => $accountId,
            'critical_events' => $criticalDetails,
        ]);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function applyScrapeResult(int $accountId, array $result): void
    {
        $hashtagId = $result['hashtag_id'] ?? null;
        $hashtag = (string) ($result['hashtag'] ?? '');
        $candidates = $result['candidates'] ?? [];
        if (! is_array($candidates)) {
            return;
        }

        $upserted = 0;
        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }
            $igUserId = (string) ($candidate['ig_user_id'] ?? '');
            $igUsername = (string) ($candidate['ig_username'] ?? '');
            if ($igUserId === '' || $igUsername === '') {
                continue;
            }

            $score = (int) ($candidate['tourist_score'] ?? 0);

            // 既存レコードの status は保持する。dm_sent / replied / queued / blacklisted を
            // 'new' に上書きすると DM 二重送信やブラックリスト解除を招く.
            $prospect = Prospect::query()->firstOrCreate(
                ['account_id' => $accountId, 'ig_user_id' => $igUserId],
                [
                    'ig_username' => $igUsername,
                    'status' => Prospect::STATUS_NEW,
                    'found_at' => now(),
                ],
            );

            $prospect->fill([
                'ig_username' => $igUsername,
                'full_name' => $candidate['full_name'] ?? $prospect->full_name,
                'bio' => $candidate['bio'] ?? $prospect->bio,
                'follower_count' => (int) ($candidate['follower_count'] ?? $prospect->follower_count),
                'following_count' => (int) ($candidate['following_count'] ?? $prospect->following_count),
                'post_count' => (int) ($candidate['post_count'] ?? $prospect->post_count),
                'detected_lang' => $candidate['detected_lang'] ?? $prospect->detected_lang,
                'source_hashtag' => $hashtag !== ''
                    ? $hashtag
                    : ($candidate['source_hashtag'] ?? $prospect->source_hashtag),
                'source_post_url' => $candidate['source_post_url'] ?? $prospect->source_post_url,
                'is_tourist' => $score >= Prospect::TOURIST_SCORE_THRESHOLD,
                'tourist_score' => $score,
            ])->save();
            $upserted++;
        }

        if ($hashtagId !== null) {
            HashtagWatchlist::query()
                ->where('id', $hashtagId)
                ->where('account_id', $accountId)
                ->update(['last_scraped_at' => now()]);
        }

        Log::info('scrape_result_applied', [
            'account_id' => $accountId,
            'hashtag' => $hashtag,
            'upserted' => $upserted,
        ]);
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
