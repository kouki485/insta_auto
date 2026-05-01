<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Account;
use App\Models\DmLog;
use App\Models\Prospect;
use App\Models\SafetyEvent;

/**
 * アカウントヘルススコア (設計書 §3.4.3).
 *
 * base = 100
 * - 直近 24h rate_limited: -10/件
 * - 直近 24h action_blocked: -30/件
 * - 直近 24h challenge_required: -50/件
 * - 直近 24h feedback_required: -40/件
 * - DM 返信率が前週比で 50% 以上低下: -20
 *
 * <50 で daily_dm_limit 半減
 * <30 で auto_pause + Slack
 */
class AccountHealthService
{
    public const ACTION_NONE = 'none';

    public const ACTION_HALVE_DM_LIMIT = 'halve_dm_limit';

    public const ACTION_AUTO_PAUSE = 'auto_pause';

    public const PENALTY_BY_EVENT = [
        SafetyEvent::TYPE_RATE_LIMITED => 10,
        SafetyEvent::TYPE_ACTION_BLOCKED => 30,
        SafetyEvent::TYPE_CHALLENGE_REQUIRED => 50,
        SafetyEvent::TYPE_FEEDBACK_REQUIRED => 40,
    ];

    /**
     * @return array{score: int, action: string, penalties: array<string, int>}
     */
    public function evaluate(Account $account): array
    {
        $now = now();
        $cutoff24 = $now->copy()->subDay();

        $eventCounts = SafetyEvent::query()
            ->where('account_id', $account->id)
            ->where('occurred_at', '>=', $cutoff24)
            ->selectRaw('event_type, COUNT(*) as cnt')
            ->groupBy('event_type')
            ->pluck('cnt', 'event_type')
            ->all();

        $score = 100;
        $penalties = [];
        foreach (self::PENALTY_BY_EVENT as $type => $perEvent) {
            $count = (int) ($eventCounts[$type] ?? 0);
            if ($count > 0) {
                $deduction = $perEvent * $count;
                $score -= $deduction;
                $penalties[$type] = $deduction;
            }
        }

        if ($this->replyRateDroppedSignificantly($account, $now)) {
            $score -= 20;
            $penalties['reply_rate_drop'] = 20;
        }

        $score = max(0, min(100, $score));

        return [
            'score' => $score,
            'action' => $this->actionFor($score),
            'penalties' => $penalties,
        ];
    }

    public function actionFor(int $score): string
    {
        if ($score < 30) {
            return self::ACTION_AUTO_PAUSE;
        }
        if ($score < 50) {
            return self::ACTION_HALVE_DM_LIMIT;
        }

        return self::ACTION_NONE;
    }

    private function replyRateDroppedSignificantly(Account $account, \Illuminate\Support\Carbon $now): bool
    {
        $thisWeekStart = $now->copy()->subDays(7);
        $prevWeekStart = $now->copy()->subDays(14);

        $thisWeekSent = DmLog::query()
            ->where('account_id', $account->id)
            ->where('status', DmLog::STATUS_SENT)
            ->whereBetween('sent_at', [$thisWeekStart, $now])
            ->count();
        if ($thisWeekSent === 0) {
            return false;
        }
        $thisWeekReplied = Prospect::query()
            ->where('account_id', $account->id)
            ->whereBetween('replied_at', [$thisWeekStart, $now])
            ->count();
        $thisRate = $thisWeekReplied / max(1, $thisWeekSent);

        $prevWeekSent = DmLog::query()
            ->where('account_id', $account->id)
            ->where('status', DmLog::STATUS_SENT)
            ->whereBetween('sent_at', [$prevWeekStart, $thisWeekStart])
            ->count();
        if ($prevWeekSent === 0) {
            return false;
        }
        $prevWeekReplied = Prospect::query()
            ->where('account_id', $account->id)
            ->whereBetween('replied_at', [$prevWeekStart, $thisWeekStart])
            ->count();
        $prevRate = $prevWeekReplied / max(1, $prevWeekSent);
        if ($prevRate <= 0.0) {
            return false;
        }

        return $thisRate <= $prevRate * 0.5;
    }
}
