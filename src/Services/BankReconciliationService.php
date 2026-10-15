<?php

namespace OtimOtim\BookKeeper\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use OtimOtim\BookKeeper\Data\BankStatementData;
use OtimOtim\BookKeeper\Data\LineData;
use OtimOtim\BookKeeper\Enums\Side;
use OtimOtim\BookKeeper\Enums\StatementStatus;
use OtimOtim\BookKeeper\Events\BankStatementReconciled;
use OtimOtim\BookKeeper\Exceptions\InvalidOperationException;
use OtimOtim\BookKeeper\Models\Account;
use OtimOtim\BookKeeper\Models\BankStatement;
use OtimOtim\BookKeeper\Models\BankStatementLine;
use OtimOtim\BookKeeper\Models\JournalLine;
use OtimOtim\BookKeeper\Support\Currency;
use OtimOtim\BookKeeper\Support\Database;
use OtimOtim\BookKeeper\Support\Money;

/**
 * Reconcile bank and mobile-money float accounts against provider
 * statements: import the statement, match its lines to ledger lines, post
 * adjustments for items only the bank knows about (charges, interest),
 * then confirm the balances agree.
 *
 * Statement amounts are positive for money in, negative for money out, and
 * in the account's own currency (USD for a USD bank account).
 */
class BankReconciliationService
{
    public function __construct(
        protected AccountService $accounts,
        protected BalanceService $balances,
        protected LedgerService $ledger,
        protected ExchangeRateService $rates,
    ) {}

    /**
     * Import a statement.
     *
     * $data: start_date, end_date, opening_balance, closing_balance, reference, notes.
     * $lines: list of [date, amount, description?, reference?] arrays (keyed or positional).
     */
    public function import(Account|int|string $account, BankStatementData|array $data, array $lines = []): BankStatement
    {
        $data = is_array($data) ? BankStatementData::fromArray($data + ['lines' => $lines]) : $data;
        $account = $this->accounts->resolve($account);
        $currency = $account->currencyCode();

        return Database::transaction(function () use ($account, $data, $currency) {
            $statement = BankStatement::create([
                'account_id' => $account->id,
                'currency' => $currency,
                'reference' => $data->reference,
                'start_date' => $data->startDate,
                'end_date' => $data->endDate,
                'opening_balance' => Money::of($data->openingBalance, $currency),
                'closing_balance' => Money::of($data->closingBalance, $currency),
                'status' => StatementStatus::Draft,
                'notes' => $data->notes,
            ]);

            foreach ($data->lines as $line) {
                $statement->lines()->create([
                    'date' => $line->date,
                    'amount' => Money::of($line->amount, $currency),
                    'description' => $line->description,
                    'reference' => $line->reference,
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
                    if ($this->amountOf($candidate, $statement)->minor() !== $line->amount->minor()) {
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
        if ($this->amountOf($journalLine, $line->statement)->minor() !== $line->amount->minor()) {
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
            // Foreign-currency statement: convert at the line date's rate.
            $rate = $this->rates->rate($amount->currency(), $line->date);
            $base = $amount->convertTo(Currency::base(), $rate);
            $bankSide = $in ? Side::Debit : Side::Credit;

            $entry = $this->ledger->record([
                LineData::converted($statement->account_id, $bankSide, $amount, $base, $rate),
                new LineData($counterAccount, $bankSide->opposite(), $base, subledger: $options['subledger'] ?? null),
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
        $currency = $statement->closing_balance->currency();
        $book = $this->balances->currencyBalance($account, $statement->end_date, $currency);

        $outstanding = $this->unmatchedLedgerLines($statement);
        $outstandingTotal = Money::sum($outstanding->map(fn (JournalLine $l) => $this->amountOf($l, $statement)), $currency);

        $unmatched = $statement->lines()->whereNull('journal_line_id')->get();
        $unmatchedTotal = Money::sum($unmatched->pluck('amount'), $currency);

        $statementMovement = Money::sum($statement->lines()->get()->pluck('amount'), $currency);
        $adjustedBank = $statement->closing_balance->plus($outstandingTotal);
        $difference = $adjustedBank->minus($book)->minus($unmatchedTotal);

        return [
            'account' => $account->code,
            'currency' => $currency,
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
                'amount' => $this->amountOf($l, $statement),
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

    /**
     * A ledger line's money in (+) or out (-) in the statement's currency.
     */
    protected function amountOf(JournalLine $line, BankStatement $statement): Money
    {
        return $line->signedAmountIn($statement->closing_balance->currency());
    }

    protected function assertDraft(BankStatement $statement): void
    {
        if ($statement->status === StatementStatus::Reconciled) {
            throw new InvalidOperationException('The statement is already reconciled.');
        }
    }
}
