<?php

namespace OtimOtim\BookKeeper\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OtimOtim\BookKeeper\Casts\DateCast;

/**
 * Applies a payment or a credit-type document to an invoice or bill.
 * The amount is in the document currency, stored as minor units.
 */
class Allocation extends Model
{
    protected $table = 'bookkeeper_allocations';

    protected $casts = [
        'date' => DateCast::class,
        'amount' => 'integer',
        'base_amount' => 'integer',
        'credit_base_amount' => 'integer',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function creditDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'credit_document_id');
    }
}
