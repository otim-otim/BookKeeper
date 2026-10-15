<?php

namespace OtimOtim\BookKeeper\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use OtimOtim\BookKeeper\Enums\StatementStatus;
use OtimOtim\BookKeeper\Events\BankStatementReconciled;
use OtimOtim\BookKeeper\Exceptions\InvalidOperationException;
use OtimOtim\BookKeeper\Models\Account;
use OtimOtim\BookKeeper\Models\BankStatement;
use OtimOtim\BookKeeper\Models\BankStatementLine;
use OtimOtim\BookKeeper\Models\JournalLine;
use OtimOtim\BookKeeper\Support\Database;
use OtimOtim\BookKeeper\Support\Money;

/**
 * Reconcile bank and mobile-money float accounts against provider
 * statements: import the statement, match its lines to ledger lines, post
 * adjustments for items only the bank knows about (charges, interest),
 * then confirm the balances agree.
 *
 * Statement amounts are positive for money in, negative for money out.
 */
class BankReconciliationService
{
    public function __construct(
        protected AccountService $accounts,
        protected BalanceService $balances,
        protected LedgerService $ledger,
    ) {}

    /**
     * Import a statement.
     *
     * $data: start_date, end_date, opening_balance, closing_balance, reference, notes.
     * $lines: list of [date, amount, description?, reference?] arrays (keyed or positional).
     */
    public function import(Account|int|string $account, array $data, array $lines): BankStatement
    {
        $account = $this->accounts->resolve($account);

        return Database::transaction(function () use ($account, $data, $lines) {
            $statement = BankStatement::create([
                'account_id' => $account->id,
                'reference' => $data['reference'] ?? null,
                'start_date' => Carbon::parse($data['start_date']),
                'end_date' => Carbon::parse($data['end_date']),
                'opening_balance' => Money::of($data['opening_balance'] ?? 0),
                'closing_balance' => Money::of($data['closing_balance']),
                'status' => StatementStatus::Draft,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($lines as $line) {
                $statement->lines()->create([
                    'date' => Carbon::parse($line['date'] ?? $line[0]),
                    'amount' => Money::of($line['amount'] ?? $line[1]),
                    'description' => $line['description'] ?? $line[2] ?? null,
                    'reference' => $line['reference'] ?? $line[3] ?? null,
                ]);
            }

            return $statement->load('lines');
        });
    }

    /**
     * Ledger lines on the statement's account that no statement has matched
     * yet, dated up to $until.
     *
     * @return Collection<int, JournalLine>
     */
    public function unmatchedLedgerLines(BankStatement $statement, \DateTimeInterface|string|null $until = null): Collection
    {
        $until = Carbon::parse($until ?? $statement->end_date)->toDateString();

        return JournalLine::query()
            ->select('bookkeeper_journal_lines.*')
            ->join('bookkeeper_journal_entries as e', 'e.id', '=', 'bookkeeper_journal_lines.journal_entry_id')
            ->with('entry')
            ->where('bookkeeper_journal_lines.account_id', $statement->account_id)
            ->where('e.status', 'posted')
            ->where('e.date', '<=', $until)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('bookkeeper_bank_statement_lines as s')
                ->whereColumn('s.journal_line_id', 'bookkeeper_journal_lines.id'))
            ->orderBy('e.date')
            ->orderBy('bookkeeper_journal_lines.id')
            ->get();
    }

    /**
     * Match statement lines to ledger lines with the same amount, dated
     * within $toleranceDays, preferring the same reference, then the
     * nearest date.
     *
     * @return int number of lines matched
     */
    public function autoMatch(BankStatement $statement, int $toleranceDays = 3): int
    {
        $this->assertDraft($statement);

        $candidates = $this->unmatchedLedgerLines($statement, $statement->end_date->copy()->addDays($toleranceDays))->all();
        $matched = 0;

        Database::transaction(function () use ($statement, &$candidates, $toleranceDays, &$matched) {
            foreach ($statement->lines()->whereNull('journal_line_id')->get() as $line) {
                $best = null;
                $bestScore = null;

                foreach ($candidates as $i => $candidate) {
                    if ($candidate->signedAmount()->minor() !== $line->amount->minor()) {
                        continue;
                    }

                    $days = abs((int) $candidate->entry->date->diffInDays($line->date, false));
                    if ($days > $toleranceDays) {
                        continue;
                    }

                    $sameReference = $line->reference !== null && $line->reference !== ''
                        && ($line->reference === $candidate->entry->reference || $line->reference === $candidate->entry->number);
                    $score = ($sameReference ? 0 : 1000) + $days;

                    if ($bestScore === null || $score < $bestScore) {
                        [$best, $bestScore] = [$i, $score];
                    }
                }

                if ($best !== null) {
                    $line->update(['journal_line_id' => $candidates[$best]->id, 'matched_at' => now()]);
                    unset($candidates[$best]);
                    $matched++;
                }
            }
        });

        return $matched;
    }

    public function match(BankStatementLine $line, JournalLine $journalLine): BankStatementLine
    {
        $this->assertDraft($line->statement);

        if ($journalLine->account_id !== $line->statement->account_id) {
            throw new InvalidOperationException('The ledger line is on a different account.');
        }
        if ($journalLine->signedAmount()->minor() !== $line->amount->minor()) {
            throw new InvalidOperationException('The ledger line amount differs from the statement line.');
        }
        if (BankStatementLine::query()->where('journal_line_id', $journalLine->id)->exists()) {
            throw new InvalidOperationException('The ledger line is already matched.');
        }

        $line->update(['journal_line_id' => $journalLine->id, 'matched_at' => now()]);

        return $line;
    }

    public function unmatch(BankStatementLine $line): BankStatementLine
    {
        $this->assertDraft($line->statement);
        $line->update(['journal_line_id' => null, 'matched_at' => null]);

        return $line;
    }

    /**
     * Post an entry for a statement line the ledger does not have yet (bank
     * charges, interest, direct debits) against $counterAccount, and match it.
     */
    public function adjust(BankStatementLine $line, Account|int|string $counterAccount, array $options = []): BankStatementLine
    {
        $statement = $line->statement;
        $this->assertDraft($statement);

        if ($line->isMatched()) {
            throw new InvalidOperationException('The statement line is already matched.');
        }

        return Database::transaction(function () use ($line, $statement, $counterAccount, $options) {
            $amount = $line->amount->abs();
            $in = $line->amount->isPositive();

            $entry = $this->ledger->record([
                ['account' => $statement->account_id, ($in ? 'debit' : 'credit') => $amount],
                ['account' => $counterAccount, ($in ? 'credit' : 'debit') => $amount, 'subledger' => $options['subledger'] ?? null],
            ], [
                'date' => $line->date,
                'memo' => $options['memo'] ?? $line->description ?? 'Bank statement adjustment',
                'reference' => $line->reference,
                'source' => $statement,
            ] + $options);

            $journalLine = $entry->lines->firstWhere('account_id', $statement->account_id);
            $line->update(['journal_line_id' => $journalLine->id, 'matched_at' => now()]);

            return $line;
        });
    }

    /**
     * Reconciliation summary:
     *   statement closing balance
     *   - outstanding ledger items (in the books, not yet on any statement)
     *   = should equal the ledger balance at the statement end date.
     */
    public function summary(BankStatement $statement): array
    {
        $account = $statement->account;
        $book = $this->balances->balance($account, $statement->end_date);

        $outstanding = $this->unmatchedLedgerLines($statement);
        $outstandingTotal = Money::sum($outstanding->map->signedAmount());

        $unmatched = $statement->lines()->whereNull('journal_line_id')->get();
        $unmatchedTotal = Money::sum($unmatched->pluck('amount'));

        $statementMovement = Money::sum($statement->lines()->get()->pluck('amount'));
        $adjustedBank = $statement->closing_balance->plus($outstandingTotal);
        $difference = $adjustedBank->minus($book)->minus($unmatchedTotal);

        return [
            'account' => $account->code,
            'start_date' => $statement->start_date->toDateString(),
            'end_date' => $statement->end_date->toDateString(),
            'statement_opening' => $statement->opening_balance,
            'statement_closing' => $statement->closing_balance,
            'statement_lines_consistent' => $statement->opening_balance->plus($statementMovement)->equals($statement->closing_balance),
            'book_balance' => $book,
            'outstanding_items' => $outstanding->map(fn (JournalLine $l) => [
                'date' => $l->entry->date->toDateString(),
                'number' => $l->entry->number,
                'memo' => $l->memo ?? $l->entry->memo,
                'amount' => $l->signedAmount(),
            ])->all(),
            'outstanding_total' => $outstandingTotal,
            'unmatched_statement_lines' => $unmatched->count(),
            'unmatched_statement_total' => $unmatchedTotal,
            'adjusted_bank_balance' => $adjustedBank,
            'difference' => $difference,
            'reconciled' => $difference->isZero() && $unmatched->isEmpty(),
        ];
    }

    /**
     * Mark the statement reconciled. Every statement line must be matched
     * and the adjusted balances must agree.
     */
    public function reconcile(BankStatement $statement): BankStatement
    {
        $this->assertDraft($statement);
        $summary = $this->summary($statement);

        if (! $summary['reconciled']) {
            throw new InvalidOperationException(sprintf(
                'Statement does not reconcile: %d unmatched statement line(s), difference %s.',
                $summary['unmatched_statement_lines'],
                $summary['difference']->amount(),
            ));
        }

        $statement->update(['status' => StatementStatus::Reconciled, 'reconciled_at' => now()]);

        event(new BankStatementReconciled($statement));

        return $statement;
    }

    protected function assertDraft(BankStatement $statement): void
    {
        if ($statement->status === StatementStatus::Reconciled) {
            throw new InvalidOperationException('The statement is already reconciled.');
        }
    }
}
