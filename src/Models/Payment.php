<?php

namespace OtimOtim\BookKeeper\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use OtimOtim\BookKeeper\Casts\DateCast;
use OtimOtim\BookKeeper\Casts\MoneyCast;
use OtimOtim\BookKeeper\Enums\PaymentDirection;
use OtimOtim\BookKeeper\Support\Money;

/**
 * Money received from a customer or paid to a vendor. Amounts are in the
 * payment currency; "amount" is the gross amount settled, including any
 * tax withheld.
 *
 * @property int $id
 * @property PaymentDirection $direction
 * @property string $number
 * @property int $contact_id
 * @property Carbon $date
 * @property string $currency
 * @property string $exchange_rate
 * @property Money $amount
 * @property Money $withholding_amount
 * @property int $account_id
 * @property ?int $journal_entry_id
 * @property string $status
 */
class Payment extends Model
{
    protected $table = 'bookkeeper_payments';

    protected $attributes = [
        'withholding_amount' => 0,
        'status' => 'posted',
    ];

    protected $casts = [
        'direction' => PaymentDirection::class,
        'date' => DateCast::class,
        'amount' => MoneyCast::class.':currency',
        'withholding_amount' => MoneyCast::class.':currency',
        'metadata' => 'array',
        'voided_at' => 'datetime',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function controlAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'control_account_id');
    }

    public function withholdingTaxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class, 'withholding_tax_rate_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(Allocation::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Cash actually moved: gross amount less tax withheld.
     */
    public function netAmount(): Money
    {
        return $this->amount->minus($this->withholding_amount);
    }

    public function allocatedAmount(): Money
    {
        return Money::ofMinor((int) $this->allocations()->sum('amount'), $this->currency);
    }

    public function unallocatedAmount(): Money
    {
        return $this->amount->minus($this->allocatedAmount());
    }

    public function isVoid(): bool
    {
        return $this->status === 'void';
    }
}
