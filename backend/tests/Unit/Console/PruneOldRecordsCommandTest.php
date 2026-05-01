<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use App\Models\Account;
use App\Models\DmLog;
use App\Models\Prospect;
use App\Models\SafetyEvent;
use App\Console\Commands\PruneOldRecordsCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PruneOldRecordsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_new_prospect_is_deleted_after_30_days(): void
    {
        $account = $this->makeAccount();
        $old = Prospect::query()->create([
            'account_id' => $account->id,
            'ig_user_id' => 'old',
            'ig_username' => 'old_user',
            'status' => Prospect::STATUS_NEW,
            'tourist_score' => 80,
            'found_at' => now()->subDays(PruneOldRecordsCommand::PROSPECT_NEW_DAYS + 1),
        ]);
        $fresh = Prospect::query()->create([
            'account_id' => $account->id,
            'ig_user_id' => 'fresh',
            'ig_username' => 'fresh_user',
            'status' => Prospect::STATUS_NEW,
            'tourist_score' => 80,
            'found_at' => now()->subDays(PruneOldRecordsCommand::PROSPECT_NEW_DAYS - 1),
        ]);

        $this->artisan('unara:prune-old-records')->assertOk();

        $this->assertDatabaseMissing('prospects', ['id' => $old->id]);
        $this->assertDatabaseHas('prospects', ['id' => $fresh->id]);
    }

    public function test_dm_logs_older_than_one_year_are_deleted(): void
    {
        $account = $this->makeAccount();
        $prospect = Prospect::query()->create([
            'account_id' => $account->id,
            'ig_user_id' => 'p1',
            'ig_username' => 'p1',
            'status' => Prospect::STATUS_DM_SENT,
            'tourist_score' => 80,
        ]);
        $oldTime = now()->subDays(PruneOldRecordsCommand::DM_LOG_DAYS + 1);
        $old = DmLog::query()->create([
            'account_id' => $account->id,
            'prospect_id' => $prospect->id,
            'language' => 'en',
            'message_sent' => 'old',
            'status' => DmLog::STATUS_SENT,
            'sent_at' => $oldTime,
        ]);
        // created_at は fillable 外なので生 SQL で巻き戻す.
        DmLog::query()->where('id', $old->id)->update(['created_at' => $oldTime]);

        $recent = DmLog::query()->create([
            'account_id' => $account->id,
            'prospect_id' => $prospect->id,
            'language' => 'en',
            'message_sent' => 'recent',
            'status' => DmLog::STATUS_SENT,
            'sent_at' => now()->subDays(10),
        ]);

        $this->artisan('unara:prune-old-records')->assertOk();

        $this->assertDatabaseMissing('dm_logs', ['id' => $old->id]);
        $this->assertDatabaseHas('dm_logs', ['id' => $recent->id]);
    }

    public function test_safety_events_older_than_90_days_are_deleted(): void
    {
        $account = $this->makeAccount();
        SafetyEvent::query()->create([
            'account_id' => $account->id,
            'event_type' => SafetyEvent::TYPE_RATE_LIMITED,
            'severity' => SafetyEvent::SEVERITY_WARNING,
            'details' => [],
            'occurred_at' => now()->subDays(PruneOldRecordsCommand::SAFETY_EVENT_DAYS + 1),
        ]);
        SafetyEvent::query()->create([
            'account_id' => $account->id,
            'event_type' => SafetyEvent::TYPE_RATE_LIMITED,
            'severity' => SafetyEvent::SEVERITY_WARNING,
            'details' => [],
            'occurred_at' => now()->subDays(10),
        ]);

        $this->artisan('unara:prune-old-records')->assertOk();

        $this->assertSame(1, SafetyEvent::query()->count());
    }

    public function test_dm_sent_prospect_pruned_after_one_year_via_dm_sent_at(): void
    {
        $account = $this->makeAccount();
        $old = Prospect::query()->create([
            'account_id' => $account->id,
            'ig_user_id' => 'old-sent',
            'ig_username' => 'old_sent',
            'status' => Prospect::STATUS_DM_SENT,
            'tourist_score' => 80,
            'dm_sent_at' => now()->subDays(PruneOldRecordsCommand::PROSPECT_DM_SENT_DAYS + 1),
        ]);
        $recent = Prospect::query()->create([
            'account_id' => $account->id,
            'ig_user_id' => 'recent-sent',
            'ig_username' => 'recent_sent',
            'status' => Prospect::STATUS_DM_SENT,
            'tourist_score' => 80,
            'dm_sent_at' => now()->subDays(PruneOldRecordsCommand::PROSPECT_DM_SENT_DAYS - 1),
        ]);

        $this->artisan('unara:prune-old-records')->assertOk();

        $this->assertDatabaseMissing('prospects', ['id' => $old->id]);
        $this->assertDatabaseHas('prospects', ['id' => $recent->id]);
    }

    public function test_skipped_prospects_are_pruned_after_30_days(): void
    {
        $account = $this->makeAccount();
        $skip = Prospect::query()->create([
            'account_id' => $account->id,
            'ig_user_id' => 'skip',
            'ig_username' => 'skip',
            'status' => Prospect::STATUS_SKIPPED,
            'tourist_score' => 60,
            'found_at' => now()->subDays(PruneOldRecordsCommand::PROSPECT_NEW_DAYS + 1),
        ]);

        $this->artisan('unara:prune-old-records')->assertOk();

        $this->assertDatabaseMissing('prospects', ['id' => $skip->id]);
    }

    public function test_dry_run_does_not_delete(): void
    {
        $account = $this->makeAccount();
        Prospect::query()->create([
            'account_id' => $account->id,
            'ig_user_id' => 'old',
            'ig_username' => 'old',
            'status' => Prospect::STATUS_NEW,
            'found_at' => now()->subDays(PruneOldRecordsCommand::PROSPECT_NEW_DAYS + 1),
        ]);

        $this->artisan('unara:prune-old-records', ['--dry-run' => true])->assertOk();

        $this->assertSame(1, Prospect::query()->count());
    }

    private function makeAccount(): Account
    {
        return Account::query()->create([
            'store_name' => 'うなら',
            'ig_username' => 'prune_'.uniqid(),
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
