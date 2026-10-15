<?php

namespace OtimOtim\BookKeeper\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use OtimOtim\BookKeeper\Casts\DateCast;
use OtimOtim\BookKeeper\Casts\MoneyCast;
use OtimOtim\BookKeeper\Enums\DocumentStatus;
use OtimOtim\BookKeeper\Enums\DocumentType;
use OtimOtim\BookKeeper\Support\Money;

/**
 * An invoice, credit note, bill or debit note. Amounts are in the
 * document currency.
 *
 * @property int $id
 * @property DocumentType $type
 * @property ?string $number
 * @property int $contact_id
 * @property Carbon $issue_date
 * @property Carbon $due_date
 * @property string $currency
 * @property string $exchange_rate
 * @property bool $tax_inclusive
 * @property DocumentStatus $status
 * @property Money $subtotal
 * @property Money $tax_total
 * @property Money $rounding
 * @property Money $total
 * @property Money $paid_total
 * @property ?int $journal_entry_id
 */
class Document extends Model
{
    protected $table = 'bookkeeper_documents';

    protected $attributes = [
        'subtotal' => 0,
        'tax_total' => 0,
        'rounding' => 0,
        'total' => 0,
        'base_total' => 0,
        'paid_total' => 0,
    ];

    protected $casts = [
        'type' => DocumentType::class,
        'status' => DocumentStatus::class,
        'issue_date' => DateCast::class,
        'due_date' => DateCast::class,
        'tax_inclusive' => 'boolean',
        'subtotal' => MoneyCast::class.':currency',
        'tax_total' => MoneyCast::class.':currency',
        'rounding' => MoneyCast::class.':currency',
        'total' => MoneyCast::class.':currency',
        'base_total' => MoneyCast::class,
        'paid_total' => MoneyCast::class.':currency',
        'metadata' => 'array',
        'issued_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(DocumentLine::class)->orderBy('position')->orderBy('id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(Allocation::class);
    }

    /**
     * Allocations where this (credit-type) document is applied to others.
     */
    public function creditAllocations(): HasMany
    {
        return $this->hasMany(Allocation::class, 'credit_document_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function amountDue(): Money
    {
        return $this->total->minus($this->paid_total);
    }

    public function isOverdue(\DateTimeInterface|string|null $asOf = null): bool
    {
        $asOf = Carbon::parse($asOf ?? now())->startOfDay();

        return $this->status->isOutstanding() && $this->due_date->lt($asOf);
    }

    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereIn('status', [DocumentStatus::Open->value, DocumentStatus::Partial->value]);
    }

    public function scopeOfType(Builder $query, DocumentType ...$types): Builder
    {
        return $query->whereIn('type', array_map(fn (DocumentType $t) => $t->value, $types));
    }
}
