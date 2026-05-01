<?php

declare(strict_types=1);

namespace App\Providers;

use Anthropic\Anthropic;
use App\Services\DmGeneratorService;
use App\Services\SlackNotifier;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;

class DmGeneratorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DmGeneratorService::class, function (Application $app): DmGeneratorService {
            $apiKey = (string) env('ANTHROPIC_API_KEY', '');
            $model = (string) env('CLAUDE_MODEL', 'claude-sonnet-4-6');
            $dailyLimit = (int) env('CLAUDE_API_DAILY_LIMIT', 200);

            $client = $apiKey !== '' ? Anthropic::factory()->withApiKey($apiKey)->make() : null;

            return new DmGeneratorService(
                client: $client,
                model: $model,
                dailyLimit: $dailyLimit,
            );
        });

        $this->app->singleton(SlackNotifier::class, function (Application $app): SlackNotifier {
            return new SlackNotifier(
                http: $app->make(HttpFactory::class),
                webhookUrl: (string) env('SLACK_WEBHOOK_URL', ''),
            );
        });
    }
}
