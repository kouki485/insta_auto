<?php

declare(strict_types=1);

namespace App\Services\Worker;

/**
 * 設計書 §1.3 のキュー名定数. Laravel ↔ Python Worker 間で共有する.
 */
final class WorkerQueue
{
    public const PREFIX = 'unara:queue:';

    public const DM = 'dm';

    public const SCRAPE = 'scrape';

    public const POST_FEED = 'post_feed';

    public const POST_STORY = 'post_story';

    public const RESULT = 'result';

    public static function key(string $name): string
    {
        return str_starts_with($name, self::PREFIX) ? $name : self::PREFIX.$name;
    }
}
