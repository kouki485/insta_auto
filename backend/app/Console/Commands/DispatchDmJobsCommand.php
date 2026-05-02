<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\DmLog;
use App\Models\Prospect;
use App\Models\SafetyEvent;
use App\Services\AccountHealthService;
use App\Services\DmGeneratorService;
use App\Services\SlackNotifier;
use App\Services\Worker\WorkerQueue;
use App\Services\Worker\WorkerQueueService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * DM 自動送信スケジューラ (設計書 §3.2).
 *
 * - 30 分毎に起動 (schedule:run cron 経由)
 * - 平日 9:00〜21:00 のみ送信
 * - 1 回の起動で最大 1 件 (送信間隔 3〜15 分は Worker 側 human_delay で確保)
 * - 当日送信数 < daily_dm_limit、ヘルススコアが基準値以上の場合のみ
 */
class DispatchDmJobsCommand extends Command
{
    protected $signature = 'instaauto:dispatch-dm';

    protected $description = 'tourist_score>=60 の候補から 1 件 DM ジョブを Worker キューへ投入する.';

    public function __construct(
        private readonly AccountHealthService $health,
        private readonly DmGeneratorService $generator,
        private readonly SlackNotifier $slack,
    ) {
        parent::__construct();
    }

    public function handle(WorkerQueueService $queue): int
    {
        $accounts = Account::query()->where('status', Account::STATUS_ACTIVE)->get();
        $dispatched = 0;

        foreach ($accounts as $account) {
            $now = now($account->timezone);
            if (! $this->isActiveWindow($now)) {
                continue;
            }

            $evaluation = $this->health->evaluate($account);
            if ($evaluation['action'] === AccountHealthService::ACTION_AUTO_PAUSE) {
                $this->autoPause($account, $evaluation);

                continue;
            }
            if ($evaluation['action'] === AccountHealthService::ACTION_HALVE_DM_LIMIT) {
                $halved = (int) max(1, floor($account->daily_dm_limit / 2));
                if ($account->daily_dm_limit !== $halved) {
                    $account->forceFill(['daily_dm_limit' => $halved])->save();
                }
            }

            if ($this->todaySentCount($account, $now) >= $account->daily_dm_limit) {
                continue;
            }

            $prospect = Prospect::query()
                ->where('account_id', $account->id)
                ->where('status', Prospect::STATUS_NEW)
                ->where('tourist_score', '>=', Prospect::TOURIST_SCORE_THRESHOLD)
                ->orderByDesc('tourist_score')
                ->orderBy('found_at')
                ->first();
            if ($prospect === null) {
                continue;
            }

            [$message, $template, $language] = $this->generator->generate($account, $prospect);

            $log = DmLog::query()->create([
                'account_id' => $account->id,
                'prospect_id' => $prospect->id,
                'template_id' => $template?->id,
                'language' => $language,
                'message_sent' => $message,
                'status' => DmLog::STATUS_QUEUED,
            ]);

            try {
                $jobId = $queue->dispatch(WorkerQueue::DM, [
                    'prospect_id' => $prospect->id,
                    'ig_user_id' => $prospect->ig_user_id,
                    'message' => $message,
                    'language' => $language,
                ], $account->id);

                $log->forceFill(['worker_job_id' => $jobId])->save();
                $prospect->forceFill(['status' => Prospect::STATUS_QUEUED])->save();
                $dispatched++;
            } catch (\Throwable $e) {
                Log::error('dm_dispatch_failed', [
                    'prospect_id' => $prospect->id,
                    'error' => $e->getMessage(),
                ]);
                $log->forceFill([
                    'status' => DmLog::STATUS_FAILED,
                    'error_message' => $e->getMessage(),
                ])->save();
                // dispatch 失敗した候補は次回再試行に回り続けないよう skipped にする.
                $prospect->forceFill(['status' => Prospect::STATUS_SKIPPED])->save();
            }
        }

        $this->info("dispatched {$dispatched} dm job(s)");

        return self::SUCCESS;
    }

    private function isActiveWindow(Carbon $now): bool
    {
        // ACTIVE_HOURS = 9..20 (21 直前まで), ACTIVE_DAYS = 月-金
        $hour = (int) $now->format('G');
        $weekday = (int) $now->dayOfWeekIso; // 1 (Mon) .. 7 (Sun)

        return $hour >= 9 && $hour < 21 && $weekday >= 1 && $weekday <= 5;
    }

    private function todaySentCount(Account $account, Carbon $now): int
    {
        $start = $now->copy()->startOfDay();
        $end = $start->copy()->addDay();

        return DmLog::query()
            ->where('account_id', $account->id)
            ->whereIn('status', [DmLog::STATUS_QUEUED, DmLog::STATUS_SENT])
            ->whereBetween('created_at', [$start, $end])
            ->count();
    }

    /**
     * @param  array{score: int, action: string, penalties: array<string, int>}  $evaluation
     */
    private function autoPause(Account $account, array $evaluation): void
    {
        $account->forceFill(['status' => Account::STATUS_PAUSED])->save();

        SafetyEvent::query()->create([
            'account_id' => $account->id,
            'event_type' => SafetyEvent::TYPE_AUTO_PAUSED,
            'severity' => SafetyEvent::SEVERITY_CRITICAL,
            'details' => [
                'reason' => 'health_score_below_threshold',
                'health_score' => $evaluation['score'],
                'penalties' => $evaluation['penalties'],
            ],
            'occurred_at' => now(),
        ]);
        Log::warning('account_auto_paused_by_health', [
            'account_id' => $account->id,
            'health_score' => $evaluation['score'],
        ]);
        $this->slack->notify(sprintf(
            ":rotating_light: account_id=%d auto-paused (health_score=%d). penalties=%s",
            $account->id,
            $evaluation['score'],
            json_encode($evaluation['penalties'], JSON_UNESCAPED_UNICODE),
        ));
    }
}
