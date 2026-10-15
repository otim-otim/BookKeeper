<?php

namespace OtimOtim\BookKeeper\Models;

use OtimOtim\BookKeeper\Casts\DateCast;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OtimOtim\BookKeeper\Casts\MoneyCast;
use OtimOtim\BookKeeper\Enums\StatementStatus;

/**
 * A bank or mobile-money provider statement to reconcile against the ledger.
 *
 * @property int $id
 * @property int $account_id
 * @property \Illuminate\Support\Carbon $start_date
 * @property \Illuminate\Support\Carbon $end_date
 * @property \OtimOtim\BookKeeper\Support\Money $opening_balance
 * @property \OtimOtim\BookKeeper\Support\Money $closing_balance
 * @property StatementStatus $status
 */
class BankStatement extends Model
{
    protected $table = 'bookkeeper_bank_statements';

    protected $casts = [
        'start_date' => DateCast::class,
        'end_date' => DateCast::class,
        'opening_balance' => MoneyCast::class,
        'closing_balance' => MoneyCast::class,
        'status' => StatementStatus::class,
        'reconciled_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class)->orderBy('date')->orderBy('id');
    }
}
