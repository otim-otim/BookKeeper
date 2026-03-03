<?php

namespace OtimOtim\BookKeeper\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JournalEntry extends Model
{
    protected $table = 'bookkeeper_journal_entries';

    protected $fillable = [
        'date',
        'memo',
        'reference',
        'currency',
        'metadata',
    ];

    protected $casts = [
        'date' => 'date',
        'metadata' => 'array',
    ];

    /**
     * All individual transaction lines (debits and credits) in this entry.
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Return the total debits posted in this entry.
     */
    public function totalDebits(): float
    {
        return (float) $this->transactions()->where('type', 'debit')->sum('amount');
    }

    /**
     * Return the total credits posted in this entry.
     */
    public function totalCredits(): float
    {
        return (float) $this->transactions()->where('type', 'credit')->sum('amount');
    }

    /**
     * Confirm that this journal entry is balanced (debits == credits).
     */
    public function isBalanced(): bool
    {
        return abs($this->totalDebits() - $this->totalCredits()) < 0.0001;
    }
}
