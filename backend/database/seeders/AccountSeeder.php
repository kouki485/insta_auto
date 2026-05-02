<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Account;
use Illuminate\Database\Seeder;

class AccountSeeder extends Seeder
{
    public function run(): void
    {
        $username = env('SEED_IG_USERNAME', 'demo_account');

        Account::query()->updateOrCreate(
            ['ig_username' => $username],
            [
                'store_name' => env('SEED_STORE_NAME', 'Demo Store'),
                'ig_session_path' => env(
                    'SEED_SESSION_PATH',
                    "/storage/sessions/{$username}.json"
                ),
                // ローカル動作確認では空文字列で OK. Worker は LOCAL_MODE=true でスタブ応答する.
                'proxy_url' => env('SEED_PROXY_URL', ''),
                'ig_password' => env('SEED_IG_PASSWORD', 'change-me'),
                'daily_dm_limit' => 5,
                'daily_follow_limit' => 5,
                'daily_like_limit' => 30,
                'status' => Account::STATUS_PAUSED,
                'timezone' => env('SEED_TIMEZONE', 'Asia/Tokyo'),
                'warmup_started_at' => null,
            ],
        );
    }
}
