<?php

namespace OtimOtim\BookKeeper\Services;

use Illuminate\Support\Carbon;
use OtimOtim\BookKeeper\Close\CloseContext;
use OtimOtim\BookKeeper\Contracts\CloseTask;
use OtimOtim\BookKeeper\Data\LineData;
use OtimOtim\BookKeeper\Enums\AccountType;
use OtimOtim\BookKeeper\Enums\CloseType;
use OtimOtim\BookKeeper\Enums\EntryType;
use OtimOtim\BookKeeper\Enums\PeriodStatus;
use OtimOtim\BookKeeper\Events\DayClosed;
use OtimOtim\BookKeeper\Events\FiscalYearClosed;
use OtimOtim\BookKeeper\Events\FiscalYearReopened;
use OtimOtim\BookKeeper\Exceptions\InvalidOperationException;
use OtimOtim\BookKeeper\Models\Account;
use OtimOtim\BookKeeper\Models\CloseRun;
use OtimOtim\BookKeeper\Models\FiscalYear;
use OtimOtim\BookKeeper\Models\JournalEntry;
use OtimOtim\BookKeeper\Models\Period;
use OtimOtim\BookKeeper\Support\Database;
use OtimOtim\BookKeeper\Support\Money;

/**
 * End of day, end of month and end of year.
 *
 * Each close runs the host application's configured CloseTask classes
 * first (interest accrual, penalties, provisions, ...), then BookKeeper's
 * own steps, inside one transaction, and records that it ran so it cannot
 * run twice for the same date.
 */
class CloseService
{
    public function __construct(
        protected LedgerService $ledger,
        protected BalanceService $balances,
        protected PeriodService $periods,
        protected FixedAssetService $assets,
        protected RevaluationService $revaluation,
    ) {}

    /**
     * End of day: host tasks, scheduled reversals, balance snapshot.
     */
    public function closeDay(\DateTimeInterface|string|null $date = null): CloseRun
    {
        $date = Carbon::parse($date ?? now())->startOfDay();

        return Database::transaction(function () use ($date) {
            $this->assertNotRun(CloseType::Day, $date);
            $context = new CloseContext(CloseType::Day, $date, $this->periods->periodFor($date));

            $this->runTasks(CloseType::Day, $context);
            $reversals = $this->ledger->processScheduledReversals($date);

            $snapshot = config('bookkeeper.snapshots.enabled', true) ? $this->balances->snapshot($date) : null;

            $run = $this->record(CloseType::Day, $date, [
                'reversals' => $reversals->count(),
                'snapshot_accounts' => $snapshot,
            ]);

            event(new DayClosed($date));

            return $run;
        });
    }

    /**
     * End of month: host tasks, FX revaluation, depreciation, then lock the period.
     */
    public function closeMonth(Period|int|\DateTimeInterface|string $period): CloseRun
    {
        $period = $this->periods->resolvePeriod($period);

        if (! $period->isOpen()) {
            throw new InvalidOperationException("Period {$period->name} is already closed.");
        }

        return Database::transaction(function () use ($period) {
            $this->assertNotRun(CloseType::Month, $period->end_date);
            $context = new CloseContext(CloseType::Month, $period->end_date->copy(), $period, $period->fiscalYear);

            $this->runTasks(CloseType::Month, $context);
            $revaluation = config('bookkeeper.revaluation.enabled', true)
                ? $this->revaluation->revalue($period->end_date)
                : null;
            $depreciation = $this->assets->depreciate($period->end_date);
            $this->periods->close($period);

            return $this->record(CloseType::Month, $period->end_date, [
                'period_id' => $period->id,
                'depreciation_entries' => $depreciation->count(),
                'revaluation_entry_id' => $revaluation?->id,
            ]);
        });
    }

    /**
     * End of year: close any open months, run host tasks, post the closing
     * entry that moves revenue and expenses to retained earnings, lock the
     * year and open the next one.
     */
    public function closeYear(FiscalYear|int|string $fiscalYear): CloseRun
    {
        $fiscalYear = $this->periods->resolveFiscalYear($fiscalYear);

        if (! $fiscalYear->isOpen()) {
            throw new InvalidOperationException("Fiscal year {$fiscalYear->name} is already closed.");
        }

        return Database::transaction(function () use ($fiscalYear) {
            $this->assertNotRun(CloseType::Year, $fiscalYear->end_date);

            foreach ($fiscalYear->periods()->where('status', PeriodStatus::Open->value)->get() as $period) {
                $this->closeMonth($period);
            }

            $context = new CloseContext(CloseType::Year, $fiscalYear->end_date->copy(), null, $fiscalYear);
            $this->runTasks(CloseType::Year, $context);

            $entry = $this->closingEntry($fiscalYear);

            $fiscalYear->update([
                'status' => PeriodStatus::Closed,
                'closed_at' => now(),
                'closing_entry_id' => $entry?->id,
            ]);

            $next = $fiscalYear->end_date->copy()->addDay();
            if (! $this->periods->fiscalYearFor($next)) {
                $this->periods->createFiscalYear($next);
            }

            $run = $this->record(CloseType::Year, $fiscalYear->end_date, [
                'fiscal_year_id' => $fiscalYear->id,
                'closing_entry_id' => $entry?->id,
            ]);

            event(new FiscalYearClosed($fiscalYear));

            return $run;
        });
    }

    /**
     * Reopen a closed fiscal year: reverses its closing entry. Periods stay
     * closed until reopened individually.
     */
    public function reopenYear(FiscalYear|int|string $fiscalYear): FiscalYear
    {
        $fiscalYear = $this->periods->resolveFiscalYear($fiscalYear);

        if ($fiscalYear->isOpen()) {
            return $fiscalYear;
        }

        return Database::transaction(function () use ($fiscalYear) {
            if ($fiscalYear->closingEntry && ! $fiscalYear->closingEntry->isReversed()) {
                $this->ledger->reverse($fiscalYear->closingEntry, [
                    'same_date' => true,
                    'allow_closed_period' => true,
                    'memo' => "Reopen {$fiscalYear->name}",
                ]);
            }

            $fiscalYear->update(['status' => PeriodStatus::Open, 'closed_at' => null, 'closing_entry_id' => null]);
            CloseRun::query()->where('type', CloseType::Year->value)->where('date', $fiscalYear->end_date->toDateString())->delete();

            event(new FiscalYearReopened($fiscalYear));

            return $fiscalYear;
        });
    }

    public function hasRun(CloseType $type, \DateTimeInterface|string $date): bool
    {
        return CloseRun::query()->where('type', $type->value)->where('date', Carbon::parse($date)->toDateString())->exists();
    }

    /**
     * Zero every revenue and expense account for the year into retained
     * earnings, dated the last day of the year.
     */
    protected function closingEntry(FiscalYear $fiscalYear): ?JournalEntry
    {
        // What is still sitting on revenue and expense accounts for the year,
        // after any earlier close (and its reversal on reopening).
        $totals = $this->balances->totals([
            'from' => $fiscalYear->start_date,
            'to' => $fiscalYear->end_date,
        ]);

        $lines = [];
        $net = 0; // credit-positive: profit

        $accounts = Account::query()->whereIn('type', [AccountType::Revenue->value, AccountType::Expense->value])->postable()->orderBy('code')->get();

        foreach ($accounts as $account) {
            $balance = ($totals[$account->id]['debit'] ?? 0) - ($totals[$account->id]['credit'] ?? 0);
            if ($balance === 0) {
                continue;
            }

            // Post the opposite of the debit-minus-credit balance.
            $lines[] = LineData::signed($account, Money::ofMinor(-$balance));
            $net -= $balance;
        }

        if ($lines === []) {
            return null;
        }

        if ($net !== 0) {
            $lines[] = LineData::signed('@retained_earnings', Money::ofMinor(-$net));
        }

        return $this->ledger->record($lines, [
            'date' => $fiscalYear->end_date,
            'type' => EntryType::Closing,
            'memo' => "Year-end close {$fiscalYear->name}",
            'allow_closed_period' => true,
        ]);
    }

    protected function runTasks(CloseType $type, CloseContext $context): void
    {
        foreach ((array) config("bookkeeper.close.{$type->value}", []) as $class) {
            $task = app($class);

            if (! $task instanceof CloseTask) {
                throw new InvalidOperationException("{$class} must implement ".CloseTask::class.'.');
            }

            $task->handle($context);
        }
    }

    protected function assertNotRun(CloseType $type, Carbon $date): void
    {
        if ($this->hasRun($type, $date)) {
            throw new InvalidOperationException("The {$type->value} close for {$date->toDateString()} has already run.");
        }
    }

    protected function record(CloseType $type, Carbon $date, array $metadata): CloseRun
    {
        return CloseRun::create([
            'type' => $type,
            'date' => $date,
            'completed_at' => now(),
            'metadata' => $metadata,
        ]);
    }
}
