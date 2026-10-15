<?php

namespace OtimOtim\BookKeeper\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OtimOtim\BookKeeper\Casts\DateCast;
use OtimOtim\BookKeeper\Casts\MoneyCast;
use OtimOtim\BookKeeper\Support\Money;

/**
 * @property int $id
 * @property int $bank_statement_id
 * @property Carbon $date
 * @property ?string $description
 * @property ?string $reference
 * @property Money $amount Positive: money in.
 * @property ?int $journal_line_id
 */
class BankStatementLine extends Model
{
    protected $table = 'bookkeeper_bank_statement_lines';

    protected $casts = [
        'date' => DateCast::class,
        'amount' => MoneyCast::class.':statement.currency',
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
