<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $account_id
 * @property string $ig_user_id
 * @property string $ig_username
 * @property string|null $full_name
 * @property string|null $bio
 * @property int $follower_count
 * @property int $following_count
 * @property int $post_count
 * @property string|null $detected_lang
 * @property string|null $source_hashtag
 * @property string|null $source_post_url
 * @property bool $is_tourist
 * @property int|null $tourist_score
 * @property string $status
 * @property \Illuminate\Support\Carbon $found_at
 * @property \Illuminate\Support\Carbon|null $dm_sent_at
 * @property \Illuminate\Support\Carbon|null $replied_at
 */
class Prospect extends Model
{
    /** @use HasFactory<\Database\Factories\ProspectFactory> */
    use HasFactory;

    public const STATUS_NEW = 'new';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_DM_SENT = 'dm_sent';

    public const STATUS_REPLIED = 'replied';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_BLACKLISTED = 'blacklisted';

    public const TOURIST_SCORE_THRESHOLD = 60;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'account_id',
        'ig_user_id',
        'ig_username',
        'full_name',
        'bio',
        'follower_count',
        'following_count',
        'post_count',
        'detected_lang',
        'source_hashtag',
        'source_post_url',
        'is_tourist',
        'tourist_score',
        'status',
        'found_at',
        'dm_sent_at',
        'replied_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'follower_count' => 'integer',
            'following_count' => 'integer',
            'post_count' => 'integer',
            'tourist_score' => 'integer',
            'is_tourist' => 'boolean',
            'found_at' => 'datetime',
            'dm_sent_at' => 'datetime',
            'replied_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Account, Prospect> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return HasMany<DmLog> */
    public function dmLogs(): HasMany
    {
        return $this->hasMany(DmLog::class);
    }
}
