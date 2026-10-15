<?php

namespace OtimOtim\BookKeeper\Models;

use OtimOtim\BookKeeper\Casts\DateCast;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OtimOtim\BookKeeper\Casts\MoneyCast;

/**
 * @property int $id
 * @property int $bank_statement_id
 * @property \Illuminate\Support\Carbon $date
 * @property ?string $description
 * @property ?string $reference
 * @property \OtimOtim\BookKeeper\Support\Money $amount Positive: money in.
 * @property ?int $journal_line_id
 */
class BankStatementLine extends Model
{
    protected $table = 'bookkeeper_bank_statement_lines';

    protected $casts = [
        'date' => DateCast::class,
        'amount' => MoneyCast::class,
        'matched_at' => 'datetime',
    ];

    public function statement(): BelongsTo
    {
        return $this->belongsTo(BankStatement::class, 'bank_statement_id');
    }

    public function journalLine(): BelongsTo
    {
        return $this->belongsTo(JournalLine::class);
    }

    public function isMatched(): bool
    {
        return $this->journal_line_id !== null;
    }
}
