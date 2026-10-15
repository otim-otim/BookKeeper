<?php

namespace OtimOtim\BookKeeper\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use OtimOtim\BookKeeper\Enums\EntryStatus;
use OtimOtim\BookKeeper\Enums\EntryType;
use OtimOtim\BookKeeper\Models\Account;
use OtimOtim\BookKeeper\Support\Currency;
use OtimOtim\BookKeeper\Support\Database;
use OtimOtim\BookKeeper\Support\Money;

/**
 * Computes account balances from posted journal lines with aggregate SQL,
 * using daily snapshots (when present) to avoid re-summing old history.
 */
class BalanceService
{
    /**
     * Raw debit and credit totals per account.
     *
     * Filters:
     *  - accounts:  list of account IDs (default: all)
     *  - from / to: inclusive entry-date range (either may be null)
     *  - branch:    branch ID
     *  - exclude:   list of EntryType to leave out (e.g. closing entries)
     *  - subledger: a party model
     *
     * @return array<int, array{debit: int, credit: int}> keyed by account ID
     */
    public function totals(array $filters = []): array
    {
        $from = isset($filters['from']) ? Carbon::parse($filters['from'])->toDateString() : null;
        $to = isset($filters['to']) ? Carbon::parse($filters['to'])->toDateString() : null;

        $canUseSnapshot = $from === null
            && empty($filters['branch'])
            && empty($filters['exclude'])
            && empty($filters['subledger'])
            && config('bookkeeper.snapshots.enabled', true);

        $snapshotDate = $canUseSnapshot ? $this->latestSnapshotOnOrBefore($to) : null;

        $totals = [];

        if ($snapshotDate !== null) {
            $rows = Database::connection()->table('bookkeeper_account_balances')
                ->where('date', $snapshotDate)
                ->when(isset($filters['accounts']), fn (Builder $q) => $q->whereIn('account_id', $filters['accounts']))
                ->get(['account_id', 'debit', 'credit']);

            foreach ($rows as $row) {
                $totals[(int) $row->account_id] = ['debit' => (int) $row->debit, 'credit' => (int) $row->credit];
            }

            $from = Carbon::parse($snapshotDate)->addDay()->toDateString();
        }

        $rows = $this->lineQuery($filters + ['from' => $from, 'to' => $to], $from, $to)
            ->groupBy('l.account_id')
            ->selectRaw('l.account_id, SUM(l.debit) as debit, SUM(l.credit) as credit')
            ->get();

        foreach ($rows as $row) {
            $id = (int) $row->account_id;
            $totals[$id] = [
                'debit' => ($totals[$id]['debit'] ?? 0) + (int) $row->debit,
                'credit' => ($totals[$id]['credit'] ?? 0) + (int) $row->credit,
            ];
        }

        return $totals;
    }

    /**
     * Balance of an account (and its descendants) in its normal-balance
     * direction, as of a date (inclusive) or now.
     */
    public function balance(Account $account, \DateTimeInterface|string|null $asOf = null, ?int $branchId = null): Money
    {
        return $this->sum($account, ['to' => $asOf, 'branch' => $branchId]);
    }

    /**
     * Net movement on an account (and descendants) between two dates, in
     * its normal-balance direction.
     */
    public function movement(Account $account, \DateTimeInterface|string|null $from, \DateTimeInterface|string|null $to, array $filters = []): Money
    {
        return $this->sum($account, ['from' => $from, 'to' => $to] + $filters);
    }

    /**
     * Normal-direction balance of an account and its descendants for the
     * given filters.
     */
    public function sum(Account $account, array $filters = []): Money
    {
        $ids = $account->is_group ? $account->descendantIds() : [$account->id];
        $totals = $this->totals(['accounts' => $ids] + array_filter($filters, fn ($v) => $v !== null));

        $debit = array_sum(array_column($totals, 'debit'));
        $credit = array_sum(array_column($totals, 'credit'));

        return $account->type->balance($debit, $credit);
    }

    /**
     * Balance in a transaction currency (default: the account's own), in
     * the account's normal direction. For the base currency this is the
     * ordinary balance; otherwise it sums the foreign amounts of lines in
     * that currency.
     */
    public function currencyBalance(Account $account, \DateTimeInterface|string|null $asOf = null, ?string $currency = null, array $filters = []): Money
    {
        $currency = strtoupper($currency ?? $account->currencyCode());

        if ($currency === Currency::base()) {
            return $this->sum($account, ['to' => $asOf] + $filters);
        }

        $net = (int) $this->lineQuery(['accounts' => $account->is_group ? $account->descendantIds() : [$account->id], 'to' => $asOf] + $filters)
            ->where('l.currency', $currency)
            ->sum('l.foreign_amount');

        return Money::ofMinor($account->type->isDebitNormal() ? $net : -$net, $currency);
    }

    /**
     * Balance of one subledger party on an account.
     */
    public function subledgerBalance(Account $account, Model $party, \DateTimeInterface|string|null $asOf = null): Money
    {
        return $this->sum($account, ['to' => $asOf, 'subledger' => $party]);
    }

    /**
     * Base query over posted journal lines joined to their entries.
     */
    public function lineQuery(array $filters = [], ?string $from = null, ?string $to = null): Builder
    {
        $from ??= isset($filters['from']) ? Carbon::parse($filters['from'])->toDateString() : null;
        $to ??= isset($filters['to']) ? Carbon::parse($filters['to'])->toDateString() : null;

        return Database::connection()->table('bookkeeper_journal_lines as l')
            ->join('bookkeeper_journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.status', EntryStatus::Posted->value)
            ->when($from, fn (Builder $q) => $q->where('e.date', '>=', $from))
            ->when($to, fn (Builder $q) => $q->where('e.date', '<=', $to))
            ->when(isset($filters['accounts']), fn (Builder $q) => $q->whereIn('l.account_id', $filters['accounts']))
            ->when(! empty($filters['branch']), fn (Builder $q) => $q->where('l.branch_id', $filters['branch']))
            ->when(! empty($filters['exclude']), fn (Builder $q) => $q->whereNotIn(
                'e.type',
                array_map(fn (EntryType|string $t) => $t instanceof EntryType ? $t->value : $t, $filters['exclude'])
            ))
            ->when(! empty($filters['subledger']), fn (Builder $q) => $q
                ->where('l.subledger_type', $filters['subledger']->getMorphClass())
                ->where('l.subledger_id', $filters['subledger']->getKey()));
    }

    // -----------------------------------------------------------------------
    // Snapshots
    // -----------------------------------------------------------------------

    /**
     * Store cumulative totals for every account as of the end of $date.
     */
    public function snapshot(\DateTimeInterface|string $date): int
    {
        $date = Carbon::parse($date)->toDateString();

        return Database::transaction(function () use ($date) {
            $db = Database::connection();
            $db->table('bookkeeper_account_balances')->where('date', $date)->delete();
            $db->table('bookkeeper_snapshots')->where('date', $date)->delete();

            $previous = $this->latestSnapshotOnOrBefore(Carbon::parse($date)->subDay()->toDateString());
            $totals = $this->totals(['to' => $date]);
            $now = now();

            $rows = [];
            foreach ($totals as $accountId => $t) {
                $rows[] = [
                    'account_id' => $accountId,
                    'date' => $date,
                    'debit' => $t['debit'],
                    'credit' => $t['credit'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                $db->table('bookkeeper_account_balances')->insert($chunk);
            }

            $db->table('bookkeeper_snapshots')->insert(['date' => $date, 'created_at' => $now, 'updated_at' => $now]);

            // Keep only the latest snapshot plus month ends to bound table size.
            if ($previous && Carbon::parse($previous)->toDateString() !== Carbon::parse($previous)->endOfMonth()->toDateString()) {
                $db->table('bookkeeper_account_balances')->where('date', $previous)->delete();
                $db->table('bookkeeper_snapshots')->where('date', $previous)->delete();
            }

            return count($rows);
        });
    }

    /**
     * Drop snapshots that a posting dated $date makes stale.
     */
    public function invalidateFrom(\DateTimeInterface|string $date): void
    {
        $date = Carbon::parse($date)->toDateString();
        $db = Database::connection();

        if ($db->table('bookkeeper_snapshots')->where('date', '>=', $date)->exists()) {
            $db->table('bookkeeper_account_balances')->where('date', '>=', $date)->delete();
            $db->table('bookkeeper_snapshots')->where('date', '>=', $date)->delete();
        }
    }

    protected function latestSnapshotOnOrBefore(?string $date): ?string
    {
        $value = Database::connection()->table('bookkeeper_snapshots')
            ->when($date, fn (Builder $q) => $q->where('date', '<=', $date))
            ->max('date');

        return $value ? Carbon::parse($value)->toDateString() : null;
    }
}
