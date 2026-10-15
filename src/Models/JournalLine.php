<?php

namespace OtimOtim\BookKeeper\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use OtimOtim\BookKeeper\Casts\MoneyCast;
use OtimOtim\BookKeeper\Enums\EntryStatus;
use OtimOtim\BookKeeper\Enums\Side;
use OtimOtim\BookKeeper\Exceptions\ImmutableRecordException;
use OtimOtim\BookKeeper\Support\Money;

/**
 * One debit or credit line of a journal entry. Amounts are base currency.
 *
 * @property int $id
 * @property int $journal_entry_id
 * @property int $account_id
 * @property Money $debit
 * @property Money $credit
 * @property ?string $memo
 * @property ?int $branch_id
 * @property ?string $subledger_type
 * @property int|string|null $subledger_id
 * @property ?string $currency
 * @property ?int $foreign_amount
 * @property ?string $exchange_rate
 */
class JournalLine extends Model
{
    protected $table = 'bookkeeper_journal_lines';

    protected $casts = [
        'debit' => MoneyCast::class,
        'credit' => MoneyCast::class,
        'taxable_amount' => MoneyCast::class,
    ];

    protected static function booted(): void
    {
        $guard = function (JournalLine $line, string $action) {
            $status = JournalEntry::query()->whereKey($line->journal_entry_id)->toBase()->value('status');

            if ($status === EntryStatus::Posted->value) {
                throw new ImmutableRecordException("Lines of a posted journal entry cannot be {$action}.");
            }
        };

        static::updating(fn (JournalLine $line) => $guard($line, 'changed'));
        static::deleting(fn (JournalLine $line) => $guard($line, 'deleted'));
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    /**
     * @deprecated v1 name for entry().
     */
    public function journalEntry(): BelongsTo
    {
        return $this->entry();
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function subledger(): MorphTo
    {
        return $this->morphTo();
    }

    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }

    public function side(): Side
    {
        return $this->debit->isPositive() ? Side::Debit : Side::Credit;
    }

    public function amount(): Money
    {
        return $this->debit->isPositive() ? $this->debit : $this->credit;
    }

    /**
     * Debit minus credit.
     */
    public function signedAmount(): Money
    {
        return $this->debit->minus($this->credit);
    }

    public function scopeDebits(Builder $query): Builder
    {
        return $query->where('debit', '>', 0);
    }

    public function scopeCredits(Builder $query): Builder
    {
        return $query->where('credit', '>', 0);
    }

    public function scopePosted(Builder $query): Builder
    {
        return $query->whereHas('entry', fn ($q) => $q->where('status', EntryStatus::Posted->value));
    }

    public function scopeForSubledger(Builder $query, \Illuminate\Database\Eloquent\Model $party): Builder
    {
        return $query->where('subledger_type', $party->getMorphClass())->where('subledger_id', $party->getKey());
    }
}
