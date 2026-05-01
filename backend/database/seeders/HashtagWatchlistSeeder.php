<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Account;
use App\Models\HashtagWatchlist;
use Illuminate\Database\Seeder;

class HashtagWatchlistSeeder extends Seeder
{
    /**
     * 設計書 §2.2.7 の14ハッシュタグを投入する.
     */
    public function run(): void
    {
        $account = Account::query()->first();
        if ($account === null) {
            $this->command?->warn('AccountSeeder が先に必要です。スキップしました。');

            return;
        }

        foreach ($this->hashtags() as [$tag, $language, $priority]) {
            HashtagWatchlist::query()->updateOrCreate(
                ['account_id' => $account->id, 'hashtag' => $tag],
                ['language' => $language, 'priority' => $priority, 'active' => true],
            );
        }
    }

    /**
     * @return list<array{0: string, 1: string, 2: int}>
     */
    private function hashtags(): array
    {
        return [
            ['asakusa', 'en', 10],
            ['浅草', 'ja', 9],
            ['sensoji', 'en', 9],
            ['浅草寺', 'ja', 8],
            ['asakusatemple', 'en', 8],
            ['tokyotrip', 'en', 7],
            ['japantrip', 'en', 7],
            ['淺草', 'zh-tw', 8],
            ['浅草旅行', 'zh-cn', 7],
            ['아사쿠사', 'ko', 8],
            ['센소지', 'ko', 7],
            ['asakusafood', 'en', 7],
            ['unagi', 'en', 6],
            ['japanfood', 'en', 5],
        ];
    }
}
