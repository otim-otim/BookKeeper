<?php

namespace OtimOtim\BookKeeper\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use OtimOtim\BookKeeper\Casts\MoneyCast;
use OtimOtim\BookKeeper\Enums\SessionStatus;
use OtimOtim\BookKeeper\Support\Money;

/**
 * @property int $id
 * @property int $till_id
 * @property SessionStatus $status
 * @property Money $opening_balance
 * @property ?Money $expected_balance
 * @property ?Money $counted_balance
 * @property ?Money $variance
 */
class TillSession extends Model
{
    protected $table = 'bookkeeper_till_sessions';

    protected $casts = [
        'status' => SessionStatus::class,
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'opening_balance' => MoneyCast::class,
        'opening_count' => MoneyCast::class,
        'expected_balance' => MoneyCast::class,
        'counted_balance' => MoneyCast::class,
        'variance' => MoneyCast::class,
    ];

    public function till(): BelongsTo
    {
        return $this->belongsTo(Till::class);
    }

    public function teller(): MorphTo
    {
        return $this->morphTo();
    }

    public function varianceEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'variance_entry_id');
    }

    public function isOpen(): bool
    {
        return $this->status === SessionStatus::Open;
    }
}
