<?php

namespace OtimOtim\BookKeeper\Models;

use OtimOtim\BookKeeper\Casts\DateCast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use OtimOtim\BookKeeper\Enums\EntryStatus;
use OtimOtim\BookKeeper\Enums\EntryType;
use OtimOtim\BookKeeper\Exceptions\ImmutableRecordException;
use OtimOtim\BookKeeper\Support\Money;

/**
 * A journal entry: the header of a balanced set of debit and credit lines.
 *
 * Posted entries are immutable. Corrections are made with a reversing entry
 * (BookKeeper::reverse()); only the reversal link may change afterwards.
 *
 * @property int $id
 * @property ?string $number
 * @property \Illuminate\Support\Carbon $date
 * @property EntryType $type
 * @property EntryStatus $status
 * @property ?string $memo
 * @property ?string $reference
 * @property string $currency
 * @property ?int $branch_id
 * @property ?string $idempotency_key
 * @property ?int $reverses_id
 * @property ?int $reversed_by_id
 * @property ?\Illuminate\Support\Carbon $posted_at
 */
class JournalEntry extends Model
{
    protected $table = 'bookkeeper_journal_entries';

    /**
     * Attributes that may still change after posting.
     */
    public const MUTABLE_AFTER_POSTING = ['reversed_by_id', 'reverse_on', 'updated_at'];

    protected $casts = [
        'date' => DateCast::class,
        'type' => EntryType::class,
        'status' => EntryStatus::class,
        'reverse_on' => DateCast::class,
        'posted_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(function (JournalEntry $entry) {
            if ($entry->getOriginal('status') !== EntryStatus::Posted) {
                return;
            }

            $changed = array_diff(array_keys($entry->getDirty()), self::MUTABLE_AFTER_POSTING);

            if ($changed) {
                throw new ImmutableRecordException(
                    "Journal entry [{$entry->number}] is posted and cannot be changed (".implode(', ', $changed).'). Reverse it instead.'
                );
            }
        });

        static::deleting(function (JournalEntry $entry) {
            if ($entry->getOriginal('status') === EntryStatus::Posted) {
                throw new ImmutableRecordException("Journal entry [{$entry->number}] is posted and cannot be deleted. Reverse it instead.");
            }

            $entry->lines()->each(fn (JournalLine $line) => $line->delete());
        });
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @deprecated v1 name for lines().
     */
    public function transactions(): HasMany
    {
        return $this->lines();
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function creator(): MorphTo
    {
        return $this->morphTo();
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reverses_id');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversed_by_id');
    }

    public function isPosted(): bool
    {
        return $this->status === EntryStatus::Posted;
    }

    public function isDraft(): bool
    {
        return $this->status === EntryStatus::Draft;
    }

    public function isReversed(): bool
    {
        return $this->reversed_by_id !== null;
    }

    public function totalDebits(): Money
    {
        return Money::ofMinor((int) $this->lines()->sum('debit'));
    }

    public function totalCredits(): Money
    {
        return Money::ofMinor((int) $this->lines()->sum('credit'));
    }

    public function isBalanced(): bool
    {
        return $this->totalDebits()->equals($this->totalCredits());
    }

    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', EntryStatus::Posted->value);
    }

    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', EntryStatus::Draft->value);
    }

    public function scopeBetween(Builder $query, ?string $from, ?string $to): Builder
    {
        return $query
            ->when($from, fn ($q) => $q->whereDate('date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('date', '<=', $to));
    }
}
