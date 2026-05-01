<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $account_id
 * @property string $hashtag
 * @property string|null $language
 * @property int $priority
 * @property bool $active
 * @property \Illuminate\Support\Carbon|null $last_scraped_at
 */
class HashtagWatchlist extends Model
{
    /** @use HasFactory<\Database\Factories\HashtagWatchlistFactory> */
    use HasFactory;

    protected $table = 'hashtag_watchlist';

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'account_id',
        'hashtag',
        'language',
        'priority',
        'active',
        'last_scraped_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'active' => 'boolean',
            'last_scraped_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Account, HashtagWatchlist> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
