<?php

namespace OtimOtim\BookKeeper\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use OtimOtim\BookKeeper\Enums\SessionStatus;
use OtimOtim\BookKeeper\Enums\TillType;

/**
 * A teller till or vault, backed by its own cash GL account.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property TillType $type
 * @property int $account_id
 * @property ?int $branch_id
 */
class Till extends Model
{
    protected $table = 'bookkeeper_tills';

    protected $casts = [
        'type' => TillType::class,
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(TillSession::class);
    }

    public function openSession(): HasOne
    {
        return $this->hasOne(TillSession::class)->where('status', SessionStatus::Open->value)->latestOfMany();
    }
}
