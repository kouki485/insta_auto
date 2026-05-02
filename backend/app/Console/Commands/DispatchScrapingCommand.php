<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\HashtagWatchlist;
use App\Services\Worker\WorkerQueue;
use App\Services\Worker\WorkerQueueService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 観光客抽出スケジューラ.
 * 設計書 §3.1 / §3.1.3:
 * - 1 時間あたり 10 タグまでをキューに投入
 * - active=true、priority DESC、最後にスクレイプしてから時間が経った順
 */
class DispatchScrapingCommand extends Command
{
    protected $signature = 'instaauto:dispatch-scraping {--limit=10 : 1 回の起動でキューに積むタグ数}';

    protected $description = 'hashtag_watchlist から優先度順にタグを scrape キューへ投入する.';

    public function handle(WorkerQueueService $queue): int
    {
        $limit = (int) $this->option('limit');
        $accounts = Account::query()
            ->where('status', Account::STATUS_ACTIVE)
            ->get();

        if ($accounts->isEmpty()) {
            $this->info('no active accounts');

            return self::SUCCESS;
        }

        $dispatched = 0;
        foreach ($accounts as $account) {
            $hashtags = HashtagWatchlist::query()
                ->where('account_id', $account->id)
                ->where('active', true)
                ->orderByDesc('priority')
                ->orderByRaw("COALESCE(last_scraped_at, '1970-01-01') ASC")
                ->limit($limit)
                ->get();

            foreach ($hashtags as $tag) {
                try {
                    $queue->dispatch(WorkerQueue::SCRAPE, [
                        'hashtag' => $tag->hashtag,
                        'language' => $tag->language,
                        'priority' => $tag->priority,
                        'hashtag_id' => $tag->id,
                    ], $account->id);
                    // last_scraped_at は ProcessWorkerResults の applyScrapeResult で
                    // 結果受信後に更新する。dispatch 直後には更新しない.
                    $dispatched++;
                } catch (\Throwable $e) {
                    Log::error('scrape_dispatch_failed', [
                        'hashtag' => $tag->hashtag,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $this->info("dispatched {$dispatched} scrape job(s)");

        return self::SUCCESS;
    }
}
