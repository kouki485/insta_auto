<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DmLog;
use App\Models\Prospect;
use App\Models\SafetyEvent;
use Illuminate\Console\Command;

/**
 * 設計書 §9.2 データ保持ポリシーに従う自動削除.
 *
 * - prospects.status='new'      → 30 日経過で削除 (found_at 基準)
 * - prospects.status='skipped'  → 30 日経過で削除 (found_at 基準、肥大化防止)
 * - prospects.status='dm_sent'  → 365 日経過で削除 (dm_sent_at 基準)
 * - dm_logs                     → 365 日経過で削除 (created_at 基準)
 * - safety_events               → 90 日経過で削除 (occurred_at 基準)
 *
 * blacklisted / replied / queued は監査・運用扱いのため自動削除しない.
 */
class PruneOldRecordsCommand extends Command
{
    protected $signature = 'instaauto:prune-old-records {--dry-run : 削除せず件数のみ出力}';

    protected $description = 'データ保持ポリシーに従い古いレコードを自動削除する.';

    public const PROSPECT_NEW_DAYS = 30;

    public const PROSPECT_DM_SENT_DAYS = 365;

    public const DM_LOG_DAYS = 365;

    public const SAFETY_EVENT_DAYS = 90;

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $report = [];

        $cutoffNew = now()->subDays(self::PROSPECT_NEW_DAYS);
        $cutoffDmSent = now()->subDays(self::PROSPECT_DM_SENT_DAYS);
        $cutoffDmLog = now()->subDays(self::DM_LOG_DAYS);
        $cutoffSafety = now()->subDays(self::SAFETY_EVENT_DAYS);

        $report['prospects_new'] = $this->prune(
            Prospect::query()
                ->where('status', Prospect::STATUS_NEW)
                ->where('found_at', '<', $cutoffNew),
            $dry,
        );
        $report['prospects_skipped'] = $this->prune(
            Prospect::query()
                ->where('status', Prospect::STATUS_SKIPPED)
                ->where('found_at', '<', $cutoffNew),
            $dry,
        );
        $report['prospects_dm_sent'] = $this->prune(
            Prospect::query()
                ->where('status', Prospect::STATUS_DM_SENT)
                ->where('dm_sent_at', '<', $cutoffDmSent),
            $dry,
        );
        $report['dm_logs'] = $this->prune(
            DmLog::query()->where('created_at', '<', $cutoffDmLog),
            $dry,
        );
        $report['safety_events'] = $this->prune(
            SafetyEvent::query()->where('occurred_at', '<', $cutoffSafety),
            $dry,
        );

        $this->info(json_encode([
            'dry_run' => $dry,
            'deleted' => $report,
        ], JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     */
    private function prune($query, bool $dry): int
    {
        if ($dry) {
            return (int) $query->count();
        }

        return (int) $query->delete();
    }
}
