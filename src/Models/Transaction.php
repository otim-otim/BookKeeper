<?php

namespace OtimOtim\BookKeeper\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    protected $table = 'bookkeeper_transactions';

    protected $fillable = [
        'journal_entry_id',
        'account_id',
        'type',      // 'debit' or 'credit'
        'amount',
        'memo',
    ];

    protected $casts = [
        'amount' => 'float',
    ];

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function scopeDebits($query)
    {
        return $query->where('type', 'debit');
    }

    public function scopeCredits($query)
    {
        return $query->where('type', 'credit');
    }
}
