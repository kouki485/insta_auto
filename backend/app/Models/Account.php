<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

/**
 * @property int $id
 * @property string $store_name
 * @property string $ig_username
 * @property string $ig_session_path
 * @property string|null $ig_password
 * @property string $proxy_url
 * @property int $daily_dm_limit
 * @property int $daily_follow_limit
 * @property int $daily_like_limit
 * @property string $status
 * @property int|null $account_age_days
 * @property string $timezone
 * @property \Illuminate\Support\Carbon|null $warmup_started_at
 */
class Account extends Model
{
    /** @use HasFactory<\Database\Factories\AccountFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_BANNED = 'banned';

    public const STATUS_WARNING = 'warning';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'store_name',
        'ig_username',
        'ig_session_path',
        'ig_password',
        'proxy_url',
        'daily_dm_limit',
        'daily_follow_limit',
        'daily_like_limit',
        'status',
        'account_age_days',
        'timezone',
        'warmup_started_at',
    ];

    /**
     * proxy_url / IG パスワードはどんなシリアライズ経路でも表に出さない.
     *
     * @var list<string>
     */
    protected $hidden = [
        'proxy_url',
        'ig_password',
        'ig_password_encrypted',
        'ig_session_path',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'daily_dm_limit' => 'integer',
            'daily_follow_limit' => 'integer',
            'daily_like_limit' => 'integer',
            'account_age_days' => 'integer',
            'warmup_started_at' => 'datetime',
        ];
    }

    /**
     * proxy_url を Crypt::encryptString で透過暗号化する.
     */
    protected function proxyUrl(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): ?string => $value === null ? null : Crypt::decryptString($value),
            set: fn (?string $value): ?string => $value === null ? null : Crypt::encryptString($value),
        );
    }

    /**
     * IG のパスワードは ig_password_encrypted カラムへ Crypt 暗号化して保存し、
     * 仮想プロパティ ig_password で読み書きできるようにする.
     */
    protected function igPassword(): Attribute
    {
        return Attribute::make(
            get: fn ($value, array $attributes): ?string => isset($attributes['ig_password_encrypted'])
                ? Crypt::decryptString($attributes['ig_password_encrypted'])
                : null,
            set: fn (?string $value): array => [
                'ig_password_encrypted' => $value === null ? null : Crypt::encryptString($value),
            ],
        );
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** @return HasMany<Prospect> */
    public function prospects(): HasMany
    {
        return $this->hasMany(Prospect::class);
    }

    /** @return HasMany<DmTemplate> */
    public function dmTemplates(): HasMany
    {
        return $this->hasMany(DmTemplate::class);
    }

    /** @return HasMany<DmLog> */
    public function dmLogs(): HasMany
    {
        return $this->hasMany(DmLog::class);
    }

    /** @return HasMany<PostSchedule> */
    public function postSchedules(): HasMany
    {
        return $this->hasMany(PostSchedule::class);
    }

    /** @return HasMany<SafetyEvent> */
    public function safetyEvents(): HasMany
    {
        return $this->hasMany(SafetyEvent::class);
    }

    /** @return HasMany<HashtagWatchlist> */
    public function hashtags(): HasMany
    {
        return $this->hasMany(HashtagWatchlist::class);
    }
}
