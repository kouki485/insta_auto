<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_accounts(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $account = $this->makeAccount();

        $this->getJson('/api/accounts')
            ->assertOk()
            ->assertJsonPath('data.0.id', $account->id)
            ->assertJsonPath('data.0.ig_username', 'demo_test');
    }

    public function test_proxy_url_is_not_returned_in_response(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->makeAccount();

        $response = $this->getJson('/api/accounts')->json('data.0');

        $this->assertArrayNotHasKey('proxy_url', $response);
        $this->assertArrayNotHasKey('ig_password', $response);
        $this->assertArrayNotHasKey('ig_password_encrypted', $response);
    }

    public function test_pause_changes_status(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $account = $this->makeAccount(['status' => Account::STATUS_ACTIVE]);

        $this->postJson("/api/accounts/{$account->id}/pause")
            ->assertOk()
            ->assertJsonPath('data.status', Account::STATUS_PAUSED);

        $this->assertSame(Account::STATUS_PAUSED, $account->fresh()->status);
    }

    public function test_resume_resets_dm_limit_to_warmup(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $account = $this->makeAccount([
            'status' => Account::STATUS_PAUSED,
            'daily_dm_limit' => 20,
        ]);

        $this->postJson("/api/accounts/{$account->id}/resume")
            ->assertOk()
            ->assertJsonPath('data.daily_dm_limit', 5);

        $this->assertSame(5, $account->fresh()->daily_dm_limit);
        $this->assertSame(Account::STATUS_ACTIVE, $account->fresh()->status);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/accounts')->assertStatus(401);
    }

    private function makeAccount(array $overrides = []): Account
    {
        return Account::query()->create(array_merge([
            'store_name' => 'Demo Store',
            'ig_username' => 'demo_test',
            'ig_session_path' => '/storage/sessions/1.json',
            'proxy_url' => 'http://user:pass@brd.example.com:22225',
            'ig_password' => 'secret',
            'daily_dm_limit' => 5,
            'daily_follow_limit' => 5,
            'daily_like_limit' => 30,
            'status' => Account::STATUS_ACTIVE,
            'timezone' => 'Asia/Tokyo',
        ], $overrides));
    }
}
