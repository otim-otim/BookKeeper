<?php

namespace OtimOtim\BookKeeper\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OtimOtim\BookKeeper\Enums\AccountType;

class Account extends Model
{
    protected $table = 'bookkeeper_accounts';

    protected $fillable = [
        'code',
        'name',
        'type',
        'description',
        'currency',
        'is_active',
    ];

    protected $casts = [
        'type' => AccountType::class,
        'is_active' => 'boolean',
    ];

    /**
     * All transactions that affect this account.
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Compute the current balance of this account.
     * For asset/expense accounts: balance = debits - credits
     * For liability/equity/revenue accounts: balance = credits - debits
     */
    public function balance(): float
    {
        $debits = (float) $this->transactions()->where('type', 'debit')->sum('amount');
        $credits = (float) $this->transactions()->where('type', 'credit')->sum('amount');

        return match ($this->type) {
            AccountType::Asset, AccountType::Expense => $debits - $credits,
            AccountType::Liability, AccountType::Equity, AccountType::Revenue => $credits - $debits,
        };
    }

    /**
     * Compute balance as of a specific date.
     */
    public function balanceAsOf(\DateTimeInterface|string $date): float
    {
        $query = $this->transactions()
            ->whereHas('journalEntry', fn ($q) => $q->whereDate('date', '<=', $date));

        $debits = (float) (clone $query)->where('type', 'debit')->sum('amount');
        $credits = (float) (clone $query)->where('type', 'credit')->sum('amount');

        return match ($this->type) {
            AccountType::Asset, AccountType::Expense => $debits - $credits,
            AccountType::Liability, AccountType::Equity, AccountType::Revenue => $credits - $debits,
        };
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOfType($query, AccountType|string $type)
    {
        return $query->where('type', $type instanceof AccountType ? $type->value : $type);
    }
}
