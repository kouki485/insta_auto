<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use App\Console\Commands\DailyReportCommand;
use App\Models\Account;
use App\Models\DmLog;
use App\Models\Prospect;
use App\Models\SafetyEvent;
use App\Services\AccountHealthService;
use App\Services\SlackNotifier;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DailyReportCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_build_report_returns_yesterday_summary(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-02 06:00:00', 'Asia/Tokyo')->utc());

        $account = $this->makeAccount();
        $prospect = Prospect::query()->create([
            'account_id' => $account->id,
            'ig_user_id' => 'p1',
            'ig_username' => 'p1',
            'status' => Prospect::STATUS_DM_SENT,
            'tourist_score' => 80,
            'replied_at' => Carbon::parse('2026-05-01 13:00:00', 'Asia/Tokyo'),
        ]);
        DmLog::query()->create([
            'account_id' => $account->id,
            'prospect_id' => $prospect->id,
            'language' => 'en',
            'message_sent' => 'msg',
            'status' => DmLog::STATUS_SENT,
            'sent_at' => Carbon::parse('2026-05-01 11:00:00', 'Asia/Tokyo'),
        ]);
        SafetyEvent::query()->create([
            'account_id' => $account->id,
            'event_type' => SafetyEvent::TYPE_RATE_LIMITED,
            'severity' => SafetyEvent::SEVERITY_WARNING,
            'details' => [],
            'occurred_at' => Carbon::parse('2026-05-01 12:00:00', 'Asia/Tokyo'),
        ]);

        $command = new DailyReportCommand(
            new SlackNotifier($this->app->make(HttpFactory::class), ''),
            new AccountHealthService(),
        );
        $report = $command->buildReport($account);

        $this->assertStringContainsString('2026-05-01', $report);
        $this->assertStringContainsString('DM sent: 1', $report);
        $this->assertStringContainsString('replies: 1', $report);
        $this->assertStringContainsString('warning=1', $report);
        $this->assertStringContainsString('critical=0', $report);
    }

    public function test_handle_calls_slack_for_each_account(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-02 06:00:00', 'Asia/Tokyo')->utc());

        $this->makeAccount();
        $this->makeAccount();

        $sent = [];
        $notifier = new class($this->app->make(HttpFactory::class), '', $sent) extends SlackNotifier {
            /** @var array<int, string> */
            public array $captured = [];

            public function __construct(HttpFactory $http, string $url, array &$ref)
            {
                parent::__construct($http, $url);
                $this->captured = &$ref;
            }

            public function notify(string $text): void
            {
                $this->captured[] = $text;
            }
        };
        $this->app->instance(SlackNotifier::class, $notifier);

        $this->artisan('unara:daily-report')->assertOk();

        $this->assertCount(2, $sent);
        $this->assertStringContainsString('daily report', $sent[0]);
    }

    private function makeAccount(): Account
    {
        return Account::query()->create([
            'store_name' => 'うなら',
            'ig_username' => 'daily_'.uniqid(),
            'ig_session_path' => '/storage/sessions/1.json',
            'proxy_url' => 'http://u:p@example.com',
            'ig_password' => 'secret',
            'daily_dm_limit' => 5,
            'daily_follow_limit' => 5,
            'daily_like_limit' => 30,
            'status' => Account::STATUS_ACTIVE,
            'timezone' => 'Asia/Tokyo',
        ]);
    }
}
