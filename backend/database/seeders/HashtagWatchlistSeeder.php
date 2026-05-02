<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Account;
use App\Models\HashtagWatchlist;
use Illuminate\Database\Seeder;

/**
 * 監視ハッシュタグの初期データ投入.
 *
 * テナントごとに対象タグは大きく異なるため、ここでは「Instagram 上の旅行系
 * 一般タグ」を最小限投入する。本格運用前に /hashtags 画面でテナント業種・
 * 地域に合わせて差し替えること.
 */
class HashtagWatchlistSeeder extends Seeder
{
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
            ['travel', 'en', 8],
            ['traveling', 'en', 7],
            ['trip', 'en', 7],
            ['vacation', 'en', 6],
            ['foodie', 'en', 6],
            ['instafood', 'en', 5],
            ['旅行', 'ja', 6],
            ['여행', 'ko', 6],
            ['旅行', 'zh-tw', 6],
        ];
    }
}
