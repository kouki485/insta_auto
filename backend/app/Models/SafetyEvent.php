<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $account_id
 * @property string $event_type
 * @property string $severity
 * @property array|null $details
 * @property \Illuminate\Support\Carbon $occurred_at
 */
class SafetyEvent extends Model
{
    /** @use HasFactory<\Database\Factories\SafetyEventFactory> */
    use HasFactory;

    public const TYPE_CHALLENGE_REQUIRED = 'challenge_required';

    public const TYPE_LOGIN_FAILED = 'login_failed';

    public const TYPE_RATE_LIMITED = 'rate_limited';

    public const TYPE_FEEDBACK_REQUIRED = 'feedback_required';

    public const TYPE_ACTION_BLOCKED = 'action_blocked';

    public const TYPE_CHECKPOINT = 'checkpoint';

    public const TYPE_AUTO_PAUSED = 'auto_paused';

    public const TYPE_MANUAL_RESUMED = 'manual_resumed';

    public const SEVERITY_INFO = 'info';

    public const SEVERITY_WARNING = 'warning';

    public const SEVERITY_CRITICAL = 'critical';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'account_id',
        'event_type',
        'severity',
        'details',
        'occurred_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'details' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Account, SafetyEvent> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
