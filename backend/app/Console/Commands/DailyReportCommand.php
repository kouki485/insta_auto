<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\DmLog;
use App\Models\Prospect;
use App\Models\SafetyEvent;
use App\Services\AccountHealthService;
use App\Services\SlackNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * 前日の運用サマリーを Slack に流す日次レポート (設計書 §7.3 / Phase 6).
 */
class DailyReportCommand extends Command
{
    protected $signature = 'instaauto:daily-report';

    protected $description = '昨日の DM/返信/safety_events サマリーを Slack に投稿する.';

    public function __construct(
        private readonly SlackNotifier $slack,
        private readonly AccountHealthService $health,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $accounts = Account::query()->get();
        $errors = 0;
        foreach ($accounts as $account) {
            try {
                $report = $this->buildReport($account);
                $this->slack->notify($report);
                $this->info($report);
            } catch (\Throwable $e) {
                $errors++;
                $this->error("daily report failed for account_id={$account->id}: {$e->getMessage()}");
            }
        }

        return $errors === 0 ? self::SUCCESS : self::FAILURE;
    }

    public function buildReport(Account $account): string
    {
        $tz = $account->timezone;
        $yesterdayStart = Carbon::yesterday($tz);
        $yesterdayEnd = $yesterdayStart->copy()->addDay();

        $sent = DmLog::query()
            ->where('account_id', $account->id)
            ->where('status', DmLog::STATUS_SENT)
            ->whereBetween('sent_at', [$yesterdayStart, $yesterdayEnd])
            ->count();

        $replied = Prospect::query()
            ->where('account_id', $account->id)
            ->whereBetween('replied_at', [$yesterdayStart, $yesterdayEnd])
            ->count();

        $criticalEvents = SafetyEvent::query()
            ->where('account_id', $account->id)
            ->where('severity', SafetyEvent::SEVERITY_CRITICAL)
            ->whereBetween('occurred_at', [$yesterdayStart, $yesterdayEnd])
            ->count();

        $warningEvents = SafetyEvent::query()
            ->where('account_id', $account->id)
            ->where('severity', SafetyEvent::SEVERITY_WARNING)
            ->whereBetween('occurred_at', [$yesterdayStart, $yesterdayEnd])
            ->count();

        $health = $this->health->evaluate($account);

        return sprintf(
            "[instaauto/%s] %s daily report\n"
            ."- DM sent: %d (limit %d)\n"
            ."- replies: %d\n"
            ."- safety events: critical=%d / warning=%d\n"
            ."- health score: %d (%s)",
            $account->ig_username,
            $yesterdayStart->toDateString(),
            $sent,
            $account->daily_dm_limit,
            $replied,
            $criticalEvents,
            $warningEvents,
            $health['score'],
            $health['action'],
        );
    }
}
