<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $account_id
 * @property int $prospect_id
 * @property int|null $template_id
 * @property string $language
 * @property string $message_sent
 * @property string $status
 * @property string|null $error_message
 * @property string|null $worker_job_id
 * @property string|null $ig_message_id
 * @property \Illuminate\Support\Carbon|null $sent_at
 */
class DmLog extends Model
{
    /** @use HasFactory<\Database\Factories\DmLogFactory> */
    use HasFactory;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_RATE_LIMITED = 'rate_limited';

    public const STATUS_BLOCKED = 'blocked';

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'account_id',
        'prospect_id',
        'template_id',
        'language',
        'message_sent',
        'status',
        'error_message',
        'worker_job_id',
        'ig_message_id',
        'sent_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Account, DmLog> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<Prospect, DmLog> */
    public function prospect(): BelongsTo
    {
        return $this->belongsTo(Prospect::class);
    }

    /** @return BelongsTo<DmTemplate, DmLog> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(DmTemplate::class, 'template_id');
    }
}
