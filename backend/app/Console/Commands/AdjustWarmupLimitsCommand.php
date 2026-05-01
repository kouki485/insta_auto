<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Account;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * ウォームアップ自動引き上げ (設計書 §4.2).
 *
 * - Week 1 → daily_dm_limit=5
 * - Week 2 → 10
 * - Week 3 → 15
 * - Week 4+ → 20
 *
 * status='active' のアカウントのみが対象。warmup_started_at を起点とし、
 * 未設定の場合は本コマンド初回実行時に now() で初期化する.
 *
 * 注意: 設計書 §4.4「再開後 1 週間は daily_dm_limit を 5 に戻す」を満たすには、
 * pause → active に手動復帰する際に AccountController::resume などで
 * warmup_started_at を now() にリセットすること。リセットを忘れると
 * 本コマンドが経過週数に従って即座に上限を復元してしまう.
 */
class AdjustWarmupLimitsCommand extends Command
{
    protected $signature = 'unara:adjust-warmup';

    protected $description = '経過週数に応じて daily_dm_limit を 5/10/15/20 に更新する.';

    public const SCHEDULE_BY_WEEK = [
        1 => ['dm' => 5, 'follow' => 5, 'like' => 30, 'story' => 3],
        2 => ['dm' => 10, 'follow' => 10, 'like' => 50, 'story' => 5],
        3 => ['dm' => 15, 'follow' => 20, 'like' => 80, 'story' => 8],
        4 => ['dm' => 20, 'follow' => 30, 'like' => 100, 'story' => 10],
    ];

    public function handle(): int
    {
        $accounts = Account::query()
            ->where('status', Account::STATUS_ACTIVE)
            ->get();

        foreach ($accounts as $account) {
            if ($account->warmup_started_at === null) {
                $account->forceFill(['warmup_started_at' => now()])->save();
            }

            $weeks = $this->weeksSince($account->warmup_started_at);
            $bucket = self::SCHEDULE_BY_WEEK[min(4, max(1, $weeks))];

            $changed = false;
            foreach (
                [
                    'daily_dm_limit' => $bucket['dm'],
                    'daily_follow_limit' => $bucket['follow'],
                    'daily_like_limit' => $bucket['like'],
                ] as $field => $newValue
            ) {
                if ((int) $account->{$field} !== $newValue) {
                    $account->{$field} = $newValue;
                    $changed = true;
                }
            }
            if ($changed) {
                $account->save();
                Log::info('warmup_adjusted', [
                    'account_id' => $account->id,
                    'weeks' => $weeks,
                    'daily_dm_limit' => $account->daily_dm_limit,
                ]);
            }
        }

        return self::SUCCESS;
    }

    private function weeksSince(Carbon $start): int
    {
        $diffDays = max(0, (int) $start->diffInDays(now()));

        return (int) floor($diffDays / 7) + 1;
    }
}
