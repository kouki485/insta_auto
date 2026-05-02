<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProspectsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_prospects_ordered_by_score_desc(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $account = $this->makeAccount();

        Prospect::query()->create($this->prospectAttrs($account, 'low', 50));
        Prospect::query()->create($this->prospectAttrs($account, 'high', 90));

        $payload = $this->getJson('/api/prospects')->assertOk()->json('data');

        $this->assertSame('high', $payload[0]['ig_username']);
        $this->assertSame('low', $payload[1]['ig_username']);
    }

    public function test_index_filters_by_min_score(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $account = $this->makeAccount();

        Prospect::query()->create($this->prospectAttrs($account, 'low', 30));
        Prospect::query()->create($this->prospectAttrs($account, 'high', 70));

        $payload = $this->getJson('/api/prospects?min_score=60')->assertOk()->json('data');

        $this->assertCount(1, $payload);
        $this->assertSame('high', $payload[0]['ig_username']);
    }

    public function test_update_changes_status(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $account = $this->makeAccount();
        $prospect = Prospect::query()->create($this->prospectAttrs($account, 'tourist_a', 80));

        $this->patchJson("/api/prospects/{$prospect->id}", ['status' => 'queued'])
            ->assertOk()
            ->assertJsonPath('data.status', 'queued');
    }

    public function test_update_rejects_invalid_status(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $account = $this->makeAccount();
        $prospect = Prospect::query()->create($this->prospectAttrs($account, 'tourist_a', 80));

        $this->patchJson("/api/prospects/{$prospect->id}", ['status' => 'invalid'])
            ->assertStatus(422);
    }

    public function test_other_account_prospect_is_inaccessible(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $primary = $this->makeAccount('primary');
        $other = $this->makeAccount('other');

        // 「現在のアカウント」は最も若い id (primary) になる.
        $foreignProspect = Prospect::query()->create($this->prospectAttrs($other, 'foreign', 90));

        $this->patchJson("/api/prospects/{$foreignProspect->id}", ['status' => 'queued'])
            ->assertStatus(404);

        $this->deleteJson("/api/prospects/{$foreignProspect->id}")
            ->assertStatus(404);
    }

    private function makeAccount(string $tag = 'demo'): Account
    {
        return Account::query()->create([
            'store_name' => "店舗{$tag}",
            'ig_username' => $tag.'_'.uniqid(),
            'ig_session_path' => "/storage/sessions/{$tag}.json",
            'proxy_url' => 'http://u:p@example.com',
            'ig_password' => 'secret',
            'daily_dm_limit' => 5,
            'daily_follow_limit' => 5,
            'daily_like_limit' => 30,
            'status' => Account::STATUS_ACTIVE,
            'timezone' => 'Asia/Tokyo',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function prospectAttrs(Account $account, string $username, int $score): array
    {
        return [
            'account_id' => $account->id,
            'ig_user_id' => $username.'-'.uniqid(),
            'ig_username' => $username,
            'tourist_score' => $score,
            'status' => Prospect::STATUS_NEW,
        ];
    }
}
