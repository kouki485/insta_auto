<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Account;
use App\Models\DmLog;
use App\Models\PostSchedule;
use App\Models\Prospect;
use App\Models\SafetyEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * ダッシュボード KPI 集計 (設計書 §3.4.2 / §5.3).
 * 60 秒の Cache::remember で反復ヒットを削減する.
 */
class DashboardSummaryService
{
    public const CACHE_TTL_SECONDS = 60;

    public function __construct(private readonly AccountHealthService $health) {}

    /**
     * @return array<string, mixed>|null
     */
    public function buildFor(Account $account): ?array
    {
        return Cache::remember(
            $this->cacheKey($account->id),
            self::CACHE_TTL_SECONDS,
            fn () => $this->compute($account),
        );
    }

    public function forget(int $accountId): void
    {
        Cache::forget($this->cacheKey($accountId));
    }

    private function cacheKey(int $accountId): string
    {
        return "dashboard:summary:account:{$accountId}";
    }

    /**
     * @return array<string, mixed>
     */
    private function compute(Account $account): array
    {
        $tz = $account->timezone;
        $today = Carbon::today($tz);
        $tomorrow = $today->copy()->addDay();
        $sevenDaysAgo = $today->copy()->subDays(7);

        $dmSentToday = DmLog::query()
            ->where('account_id', $account->id)
            ->where('status', DmLog::STATUS_SENT)
            ->whereBetween('sent_at', [$today, $tomorrow])
            ->count();

        $dmRepliedToday = Prospect::query()
            ->where('account_id', $account->id)
            ->whereBetween('replied_at', [$today, $tomorrow])
            ->count();

        // 過去 8 日分の集計を 2 クエリに集約 (N+1 を避ける).
        $sentByDay = $this->bucketize(
            DmLog::query()
                ->where('account_id', $account->id)
                ->where('status', DmLog::STATUS_SENT)
                ->whereBetween('sent_at', [$sevenDaysAgo, $tomorrow])
                ->get(['sent_at'])
                ->pluck('sent_at')
                ->all(),
            $tz,
        );
        $repliedByDay = $this->bucketize(
            Prospect::query()
                ->where('account_id', $account->id)
                ->whereBetween('replied_at', [$sevenDaysAgo, $tomorrow])
                ->whereNotNull('replied_at')
                ->get(['replied_at'])
                ->pluck('replied_at')
                ->all(),
            $tz,
        );

        $weeklyTrend = [];
        $cursor = $sevenDaysAgo->copy();
        while ($cursor->lessThanOrEqualTo($today)) {
            $key = $cursor->toDateString();
            $weeklyTrend[] = [
                'date' => $key,
                'sent' => $sentByDay[$key] ?? 0,
                'replies' => $repliedByDay[$key] ?? 0,
            ];
            $cursor = $cursor->copy()->addDay();
        }

        $recentSafetyEvents = SafetyEvent::query()
            ->where('account_id', $account->id)
            ->where('occurred_at', '>=', now()->subDay())
            ->orderByDesc('occurred_at')
            ->limit(20)
            ->get(['id', 'event_type', 'severity', 'occurred_at', 'details'])
            ->toArray();

        $healthEvaluation = $this->health->evaluate($account);

        // 設計書 §5.3 サンプルレスポンスに合わせストーリー実績/予定を返す.
        $storiesPostedToday = PostSchedule::query()
            ->where('account_id', $account->id)
            ->where('type', PostSchedule::TYPE_STORY)
            ->where('status', PostSchedule::STATUS_POSTED)
            ->whereBetween('posted_at', [$today, $tomorrow])
            ->count();
        $storiesPlannedToday = PostSchedule::query()
            ->where('account_id', $account->id)
            ->where('type', PostSchedule::TYPE_STORY)
            ->whereIn('status', [PostSchedule::STATUS_SCHEDULED, PostSchedule::STATUS_POSTING])
            ->whereBetween('scheduled_at', [$today, $tomorrow])
            ->count() + $storiesPostedToday;

        return [
            'account_id' => $account->id,
            'store_name' => $account->store_name,
            'health_score' => $healthEvaluation['score'],
            'health_action' => $healthEvaluation['action'],
            'today' => [
                'dm_sent' => $dmSentToday,
                'dm_limit' => $account->daily_dm_limit,
                'dm_replies' => $dmRepliedToday,
                'stories_posted' => $storiesPostedToday,
                'stories_planned' => $storiesPlannedToday,
            ],
            'prospects_pool' => [
                'new' => Prospect::query()->where('account_id', $account->id)
                    ->where('status', Prospect::STATUS_NEW)->count(),
                'queued' => Prospect::query()->where('account_id', $account->id)
                    ->where('status', Prospect::STATUS_QUEUED)->count(),
                'dm_sent_total' => Prospect::query()->where('account_id', $account->id)
                    ->where('status', Prospect::STATUS_DM_SENT)->count(),
                'replied_total' => Prospect::query()->where('account_id', $account->id)
                    ->where('status', Prospect::STATUS_REPLIED)->count(),
            ],
            'weekly_trend' => $weeklyTrend,
            'recent_safety_events' => $recentSafetyEvents,
        ];
    }

    /**
     * 入力日時群をアカウントタイムゾーンの「日付 (YYYY-MM-DD)」をキーにバケット集計する.
     *
     * @param  array<int, mixed>  $values
     * @return array<string, int>
     */
    private function bucketize(array $values, string $tz): array
    {
        $bucket = [];
        foreach ($values as $value) {
            if ($value === null) {
                continue;
            }
            $carbon = $value instanceof Carbon ? $value->copy() : Carbon::parse((string) $value);
            $key = $carbon->setTimezone($tz)->toDateString();
            $bucket[$key] = ($bucket[$key] ?? 0) + 1;
        }

        return $bucket;
    }
}
