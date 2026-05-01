<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;

/**
 * Slack Webhook 通知ヘルパ.
 * 設計書 §7.3: severity=critical の safety_event 発生時に投稿する.
 */
class SlackNotifier
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $webhookUrl = '',
    ) {}

    public function notify(string $text): void
    {
        if ($this->webhookUrl === '') {
            return;
        }
        try {
            $this->http->timeout(5)->post($this->webhookUrl, ['text' => $text]);
        } catch (\Throwable $e) {
            Log::warning('slack_notify_failed', ['error' => $e->getMessage()]);
        }
    }
}
