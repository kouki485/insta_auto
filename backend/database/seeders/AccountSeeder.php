<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Account;
use Illuminate\Database\Seeder;

class AccountSeeder extends Seeder
{
    public function run(): void
    {
        $username = env('SEED_IG_USERNAME', 'unara_official');

        Account::query()->updateOrCreate(
            ['ig_username' => $username],
            [
                'store_name' => env('SEED_STORE_NAME', 'うなら'),
                'ig_session_path' => env(
                    'SEED_SESSION_PATH',
                    "/storage/sessions/{$username}.json"
                ),
                'proxy_url' => env(
                    'SEED_PROXY_URL',
                    'http://user:pass@brd.superproxy.io:22225'
                ),
                'ig_password' => env('SEED_IG_PASSWORD', 'change-me'),
                'daily_dm_limit' => 5,
                'daily_follow_limit' => 5,
                'daily_like_limit' => 30,
                'status' => Account::STATUS_PAUSED,
                'timezone' => 'Asia/Tokyo',
                'warmup_started_at' => null,
            ],
        );
    }
}
