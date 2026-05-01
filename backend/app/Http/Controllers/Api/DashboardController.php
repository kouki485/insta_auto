<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DmLog;
use App\Models\Prospect;
use App\Models\SafetyEvent;
use App\Support\CurrentAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    /**
     * 設計書 §5.3 の summary レスポンス。Phase 5 でキャッシュ化する.
     */
    public function summary(Request $request): JsonResponse
    {
        $params = $request->validate([
            'account_id' => ['sometimes', 'integer'],
        ]);

        $account = CurrentAccount::resolve($params['account_id'] ?? null);

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

        // タイムゾーン依存の集計は Carbon で日付ループを回し、PHP 側で aggregate する.
        $weeklyTrend = [];
        $cursor = $sevenDaysAgo->copy();
        while ($cursor->lessThanOrEqualTo($today)) {
            $next = $cursor->copy()->addDay();
            $sent = DmLog::query()
                ->where('account_id', $account->id)
                ->where('status', DmLog::STATUS_SENT)
                ->whereBetween('sent_at', [$cursor, $next])
                ->count();
            $replies = Prospect::query()
                ->where('account_id', $account->id)
                ->whereBetween('replied_at', [$cursor, $next])
                ->count();
            $weeklyTrend[] = [
                'date' => $cursor->toDateString(),
                'sent' => $sent,
                'replies' => $replies,
            ];
            $cursor = $next;
        }

        $recentSafetyEvents = SafetyEvent::query()
            ->where('account_id', $account->id)
            ->where('occurred_at', '>=', now()->subDay())
            ->orderByDesc('occurred_at')
            ->limit(20)
            ->get(['id', 'event_type', 'severity', 'occurred_at', 'details']);

        return response()->json([
            'data' => [
                'account_id' => $account->id,
                'store_name' => $account->store_name,
                'health_score' => 100, // Phase 4 で AccountHealthService に置き換え
                'today' => [
                    'dm_sent' => $dmSentToday,
                    'dm_limit' => $account->daily_dm_limit,
                    'dm_replies' => $dmRepliedToday,
                ],
                'prospects_pool' => [
                    'new' => Prospect::where('account_id', $account->id)
                        ->where('status', Prospect::STATUS_NEW)->count(),
                    'queued' => Prospect::where('account_id', $account->id)
                        ->where('status', Prospect::STATUS_QUEUED)->count(),
                    'dm_sent_total' => Prospect::where('account_id', $account->id)
                        ->where('status', Prospect::STATUS_DM_SENT)->count(),
                    'replied_total' => Prospect::where('account_id', $account->id)
                        ->where('status', Prospect::STATUS_REPLIED)->count(),
                ],
                'weekly_trend' => $weeklyTrend,
                'recent_safety_events' => $recentSafetyEvents,
            ],
        ]);
    }
}
