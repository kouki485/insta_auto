<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Schedule as Sched;

/*
|--------------------------------------------------------------------------
| Console Routes / Scheduler 登録
|--------------------------------------------------------------------------
| Phase 2: 投稿スケジューラを毎分起動する.
| Phase 1: Worker 結果消費 (`unara:process-results`) を 1 分間隔で実行.
|
| `* * * * * php artisan schedule:run` を cron に登録すれば動作する.
*/

Sched::command('unara:process-results')
    ->everyMinute()
    ->withoutOverlapping();

Sched::command('unara:dispatch-scheduled-posts')
    ->everyMinute()
    ->withoutOverlapping();

// 設計書 §3.1.3: スクレイピングは 1 時間あたり 10 タグ。毎時 0 分に投入する.
Sched::command('unara:dispatch-scraping')
    ->hourly()
    ->withoutOverlapping();
