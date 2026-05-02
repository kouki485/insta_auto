<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Account;
use App\Models\DmTemplate;
use App\Models\Prospect;
use App\Services\DmGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DmGeneratorServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_falls_back_to_template_when_anthropic_client_missing(): void
    {
        $account = $this->makeAccount();
        DmTemplate::query()->create([
            'account_id' => $account->id,
            'language' => 'en',
            'template' => 'Hi {username}! Welcome to {store_name}.',
            'active' => true,
        ]);
        $prospect = $this->makeProspect($account, 'tourist_a', 'en');

        $service = new DmGeneratorService(client: null, model: '', dailyLimit: 0);
        [$message, $template, $language] = $service->generate($account, $prospect);

        $this->assertSame('Hi tourist_a! Welcome to Demo Store.', $message);
        $this->assertNotNull($template);
        $this->assertSame('en', $language);
    }

    public function test_falls_back_to_english_template_when_target_language_missing(): void
    {
        $account = $this->makeAccount();
        DmTemplate::query()->create([
            'account_id' => $account->id,
            'language' => 'en',
            'template' => 'Hello {username}!',
            'active' => true,
        ]);
        // Korean プロスペクトに対するテンプレが無いケース.
        $prospect = $this->makeProspect($account, 'kr_user', 'ko');

        $service = new DmGeneratorService(client: null, model: '', dailyLimit: 0);
        [$message, $template, $language] = $service->generate($account, $prospect);

        $this->assertSame('Hello kr_user!', $message);
        $this->assertSame('en', $language);
        $this->assertSame('en', $template?->language);
    }

    public function test_generic_fallback_when_no_template_exists_at_all(): void
    {
        $account = $this->makeAccount();
        $prospect = $this->makeProspect($account, 'no_template_user', 'fr');

        $service = new DmGeneratorService(client: null, model: '', dailyLimit: 0);
        [$message, $template, $language] = $service->generate($account, $prospect);

        $this->assertStringContainsString('no_template_user', $message);
        $this->assertNull($template);
        $this->assertSame('en', $language);
    }

    private function makeAccount(): Account
    {
        return Account::query()->create([
            'store_name' => 'Demo Store',
            'ig_username' => 'demo_'.uniqid(),
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

    private function makeProspect(Account $account, string $username, string $lang): Prospect
    {
        return Prospect::query()->create([
            'account_id' => $account->id,
            'ig_user_id' => $username.uniqid(),
            'ig_username' => $username,
            'detected_lang' => $lang,
            'tourist_score' => 80,
            'status' => Prospect::STATUS_NEW,
        ]);
    }
}
