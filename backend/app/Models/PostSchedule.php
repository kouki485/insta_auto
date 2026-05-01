<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $account_id
 * @property string $type
 * @property string $image_path
 * @property string|null $caption
 * @property array|null $text_overlay
 * @property \Illuminate\Support\Carbon $scheduled_at
 * @property \Illuminate\Support\Carbon|null $posted_at
 * @property string|null $ig_media_id
 * @property string $status
 * @property string|null $error_message
 * @property string|null $worker_job_id
 */
class PostSchedule extends Model
{
    /** @use HasFactory<\Database\Factories\PostScheduleFactory> */
    use HasFactory;

    public const TYPE_FEED = 'feed';

    public const TYPE_STORY = 'story';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_POSTING = 'posting';

    public const STATUS_POSTED = 'posted';

    public const STATUS_FAILED = 'failed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'account_id',
        'type',
        'image_path',
        'caption',
        'text_overlay',
        'scheduled_at',
        'posted_at',
        'ig_media_id',
        'status',
        'error_message',
        'worker_job_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'text_overlay' => 'array',
            'scheduled_at' => 'datetime',
            'posted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Account, PostSchedule> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
