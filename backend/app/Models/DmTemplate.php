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
 * @property string $language
 * @property string $template
 * @property bool $active
 */
class DmTemplate extends Model
{
    /** @use HasFactory<\Database\Factories\DmTemplateFactory> */
    use HasFactory;

    public const FALLBACK_LANGUAGE = 'en';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'account_id',
        'language',
        'template',
        'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Account, DmTemplate> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return HasMany<DmLog> */
    public function dmLogs(): HasMany
    {
        return $this->hasMany(DmLog::class, 'template_id');
    }
}
