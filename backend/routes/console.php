<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule as Sched;

/*
|--------------------------------------------------------------------------
| Console Routes / Scheduler 登録
|--------------------------------------------------------------------------
| `* * * * * php artisan schedule:run` を cron に登録する想定.
*/

// Phase 1: Worker 結果消費
Sched::command('unara:process-results')
    ->everyMinute()
    ->withoutOverlapping();

// Phase 2: 投稿スケジューラ (scheduled_at が到達した post_schedules を毎分処理)
Sched::command('unara:dispatch-scheduled-posts')
    ->everyMinute()
    ->withoutOverlapping();

// Phase 3: スクレイピング (毎時 0 分、設計書 §3.1.3 で 1 時間 10 タグ)
Sched::command('unara:dispatch-scraping')
    ->hourly()
    ->withoutOverlapping();

// Phase 4: 自動 DM 送信 (30 分毎、平日 9:00-21:00 のフィルタは Command 側で実施)
Sched::command('unara:dispatch-dm')
    ->everyThirtyMinutes()
    ->withoutOverlapping();

// Phase 4: ウォームアップ自動引き上げ (毎日 0:00 JST)
Sched::command('unara:adjust-warmup')
    ->dailyAt('00:00')
    ->timezone('Asia/Tokyo');

// Phase 6: 日次レポートを Slack に投稿 (毎日 09:00 JST)
Sched::command('unara:daily-report')
    ->dailyAt('09:00')
    ->timezone('Asia/Tokyo');

// Phase 6: 古いレコードの自動削除 (毎日 03:00 JST、設計書 §9.2)
Sched::command('unara:prune-old-records')
    ->dailyAt('03:00')
    ->timezone('Asia/Tokyo');
