<?php

namespace OtimOtim\BookKeeper\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use OtimOtim\BookKeeper\Casts\DateCast;
use OtimOtim\BookKeeper\Casts\MoneyCast;
use OtimOtim\BookKeeper\Enums\StatementStatus;
use OtimOtim\BookKeeper\Support\Money;

/**
 * A bank or mobile-money provider statement to reconcile against the ledger.
 *
 * @property int $id
 * @property int $account_id
 * @property Carbon $start_date
 * @property Carbon $end_date
 * @property Money $opening_balance
 * @property Money $closing_balance
 * @property StatementStatus $status
 */
class BankStatement extends Model
{
    protected $table = 'bookkeeper_bank_statements';

    protected $casts = [
        'start_date' => DateCast::class,
        'end_date' => DateCast::class,
        'opening_balance' => MoneyCast::class.':currency',
        'closing_balance' => MoneyCast::class.':currency',
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
