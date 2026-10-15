<?php

namespace OtimOtim\BookKeeper\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use OtimOtim\BookKeeper\Enums\AccountType;
use OtimOtim\BookKeeper\Enums\CashFlowCategory;
use OtimOtim\BookKeeper\Enums\EntryType;
use OtimOtim\BookKeeper\Models\Account;
use OtimOtim\BookKeeper\Models\JournalLine;
use OtimOtim\BookKeeper\Support\Money;

/**
 * Financial statements and ledgers.
 *
 * Every report returns plain arrays of Money values. Account rows are in
 * chart order with a "depth" and roll-up balances on group accounts, so
 * they can be rendered as an indented tree. Money serialises to a decimal
 * string, so reports can be returned from controllers as JSON directly.
 *
 * Common options: branch (branch ID), include_zero (bool).
 */
class StatementService
{
    public function __construct(
        protected AccountService $accounts,
        protected BalanceService $balances,
    ) {}

    // -----------------------------------------------------------------------
    // Trial balance
    // -----------------------------------------------------------------------

    /**
     * Every posting account with its net debit or credit balance as of a
     * date. Total debits always equal total credits.
     */
    public function trialBalance(\DateTimeInterface|string|null $asOf = null, array $options = []): array
    {
        $asOf = $this->date($asOf);
        $totals = $this->balances->totals(['to' => $asOf, 'branch' => $options['branch'] ?? null]);

        $rows = [];
        $totalDebits = Money::zero();
        $totalCredits = Money::zero();

        foreach (Account::query()->postable()->orderBy('code')->get() as $account) {
            $debit = $totals[$account->id]['debit'] ?? 0;
            $credit = $totals[$account->id]['credit'] ?? 0;
            $net = $debit - $credit;

            if ($net === 0 && ! ($options['include_zero'] ?? false)) {
                continue;
            }

            $row = [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type->value,
                'currency' => $account->currencyCode(),
                'currency_balance' => $account->isForeignCurrency() ? $this->balances->currencyBalance($account, $asOf, null, ['branch' => $options['branch'] ?? null]) : null,
                'debit' => Money::ofMinor(max($net, 0)),
                'credit' => Money::ofMinor(max(-$net, 0)),
                'total_debits' => Money::ofMinor($debit),
                'total_credits' => Money::ofMinor($credit),
            ];

            $totalDebits = $totalDebits->plus($row['debit']);
            $totalCredits = $totalCredits->plus($row['credit']);
            $rows[] = $row;
        }

        return [
            'date' => $asOf,
            'accounts' => $rows,
            'totals' => ['debits' => $totalDebits, 'credits' => $totalCredits],
            'balanced' => $totalDebits->equals($totalCredits),
        ];
    }

    // -----------------------------------------------------------------------
    // Balance sheet
    // -----------------------------------------------------------------------

    /**
     * Assets, liabilities and equity as of a date. Profit or loss not yet
     * closed to retained earnings appears in equity as "current earnings".
     */
    public function balanceSheet(\DateTimeInterface|string|null $asOf = null, array $options = []): array
    {
        $asOf = $this->date($asOf);
        $totals = $this->balances->totals(['to' => $asOf, 'branch' => $options['branch'] ?? null]);
        $includeZero = (bool) ($options['include_zero'] ?? false);

        $assets = $this->rows(AccountType::Asset, $totals, $includeZero);
        $liabilities = $this->rows(AccountType::Liability, $totals, $includeZero);
        $equity = $this->rows(AccountType::Equity, $totals, $includeZero);

        $currentEarnings = $this->typeTotal(AccountType::Revenue, $totals)->minus($this->typeTotal(AccountType::Expense, $totals));

        $totalAssets = $this->typeTotal(AccountType::Asset, $totals);
        $totalLiabilities = $this->typeTotal(AccountType::Liability, $totals);
        $totalEquity = $this->typeTotal(AccountType::Equity, $totals)->plus($currentEarnings);

        return [
            'date' => $asOf,
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'current_earnings' => $currentEarnings,
            'classification' => $this->classification($totals),
            'totals' => [
                'assets' => $totalAssets,
                'liabilities' => $totalLiabilities,
                'equity' => $totalEquity,
                'liabilities_equity' => $totalLiabilities->plus($totalEquity),
            ],
            'balanced' => $totalAssets->equals($totalLiabilities->plus($totalEquity)),
        ];
    }

    // -----------------------------------------------------------------------
    // Income statement
    // -----------------------------------------------------------------------

    /**
     * Revenue and expenses over a date range (default: year to date).
     * Year-end closing entries are excluded so closed years still report.
     */
    public function incomeStatement(\DateTimeInterface|string|null $from = null, \DateTimeInterface|string|null $to = null, array $options = []): array
    {
        $from = $from ? $this->date($from) : now()->startOfYear()->toDateString();
        $to = $this->date($to);

        $totals = $this->balances->totals([
            'from' => $from,
            'to' => $to,
            'branch' => $options['branch'] ?? null,
            'exclude' => [EntryType::Closing],
        ]);
        $includeZero = (bool) ($options['include_zero'] ?? false);

        $revenue = $this->typeTotal(AccountType::Revenue, $totals);
        $expenses = $this->typeTotal(AccountType::Expense, $totals);

        return [
            'from' => $from,
            'to' => $to,
            'revenue' => $this->rows(AccountType::Revenue, $totals, $includeZero),
            'expenses' => $this->rows(AccountType::Expense, $totals, $includeZero),
            'by_subtype' => $this->bySubtype([AccountType::Revenue, AccountType::Expense], $totals),
            'totals' => [
                'revenue' => $revenue,
                'expenses' => $expenses,
                'net_income' => $revenue->minus($expenses),
            ],
        ];
    }

    /**
     * Net income (surplus) for a range, excluding closing entries.
     */
    public function netIncome(\DateTimeInterface|string $from, \DateTimeInterface|string $to, ?int $branch = null): Money
    {
        $totals = $this->balances->totals(['from' => $from, 'to' => $to, 'branch' => $branch, 'exclude' => [EntryType::Closing]]);

        return $this->typeTotal(AccountType::Revenue, $totals)->minus($this->typeTotal(AccountType::Expense, $totals));
    }

    // -----------------------------------------------------------------------
    // Cash flow statement (indirect method)
    // -----------------------------------------------------------------------

    /**
     * Cash flow for a range: net income adjusted for changes in non-cash
     * balance sheet accounts, grouped into operating, investing and
     * financing activities by each account's cash flow category.
     */
    public function cashFlow(\DateTimeInterface|string|null $from = null, \DateTimeInterface|string|null $to = null, array $options = []): array
    {
        $from = $from ? $this->date($from) : now()->startOfYear()->toDateString();
        $to = $this->date($to);
        $branch = $options['branch'] ?? null;

        $movements = $this->balances->totals(['from' => $from, 'to' => $to, 'branch' => $branch, 'exclude' => [EntryType::Closing]]);
        $netIncome = $this->typeTotal(AccountType::Revenue, $movements)->minus($this->typeTotal(AccountType::Expense, $movements));

        $sections = [
            CashFlowCategory::Operating->value => ['items' => [], 'total' => $netIncome],
            CashFlowCategory::Investing->value => ['items' => [], 'total' => Money::zero()],
            CashFlowCategory::Financing->value => ['items' => [], 'total' => Money::zero()],
        ];
        $cashAccounts = [];

        foreach (Account::query()->postable()->whereIn('type', ['asset', 'liability', 'equity'])->orderBy('code')->get() as $account) {
            $category = $account->cashFlowCategory();

            if ($category === CashFlowCategory::Cash) {
                $cashAccounts[] = $account->id;

                continue;
            }

            $move = $movements[$account->id] ?? null;
            if ($move === null) {
                continue;
            }

            // Cash effect: credits to a non-cash account bring cash in.
            $effect = Money::ofMinor($move['credit'] - $move['debit']);
            if ($effect->isZero()) {
                continue;
            }

            $sections[$category->value]['items'][] = ['code' => $account->code, 'name' => $account->name, 'amount' => $effect];
            $sections[$category->value]['total'] = $sections[$category->value]['total']->plus($effect);
        }

        $cashBalance = function (?string $date) use ($cashAccounts, $branch) {
            if ($date === null || $cashAccounts === []) {
                return Money::zero();
            }
            $totals = $this->balances->totals(['accounts' => $cashAccounts, 'to' => $date, 'branch' => $branch]);

            return Money::ofMinor(array_sum(array_column($totals, 'debit')) - array_sum(array_column($totals, 'credit')));
        };

        $opening = $cashBalance(Carbon::parse($from)->subDay()->toDateString());
        $closing = $cashBalance($to);
        $netChange = $sections['operating']['total']->plus($sections['investing']['total'])->plus($sections['financing']['total']);

        return [
            'from' => $from,
            'to' => $to,
            'net_income' => $netIncome,
            'operating' => $sections['operating'],
            'investing' => $sections['investing'],
            'financing' => $sections['financing'],
            'net_change' => $netChange,
            'opening_cash' => $opening,
            'closing_cash' => $closing,
            'reconciled' => $opening->plus($netChange)->equals($closing),
        ];
    }

    // -----------------------------------------------------------------------
    // Statement of changes in equity
    // -----------------------------------------------------------------------

    public function changesInEquity(\DateTimeInterface|string|null $from = null, \DateTimeInterface|string|null $to = null, array $options = []): array
    {
        $from = $from ? $this->date($from) : now()->startOfYear()->toDateString();
        $to = $this->date($to);
        $branch = $options['branch'] ?? null;
        $dayBefore = Carbon::parse($from)->subDay()->toDateString();

        $opening = $this->balances->totals(['to' => $dayBefore, 'branch' => $branch]);
        $movements = $this->balances->totals(['from' => $from, 'to' => $to, 'branch' => $branch]);

        $rows = [];
        $totals = ['opening' => Money::zero(), 'increases' => Money::zero(), 'decreases' => Money::zero(), 'closing' => Money::zero()];

        foreach (Account::query()->postable()->ofType(AccountType::Equity)->orderBy('code')->get() as $account) {
            $open = Money::ofMinor(($opening[$account->id]['credit'] ?? 0) - ($opening[$account->id]['debit'] ?? 0));
            $inc = Money::ofMinor($movements[$account->id]['credit'] ?? 0);
            $dec = Money::ofMinor($movements[$account->id]['debit'] ?? 0);
            $close = $open->plus($inc)->minus($dec);

            if ($open->isZero() && $inc->isZero() && $dec->isZero() && ! ($options['include_zero'] ?? false)) {
                continue;
            }

            $rows[] = ['code' => $account->code, 'name' => $account->name, 'opening' => $open, 'increases' => $inc, 'decreases' => $dec, 'closing' => $close];
            foreach (['opening' => $open, 'increases' => $inc, 'decreases' => $dec, 'closing' => $close] as $k => $v) {
                $totals[$k] = $totals[$k]->plus($v);
            }
        }

        // Unclosed earnings behave like an equity account: profit for the
        // period comes in, year-end closing moves it to retained earnings.
        $closingTotals = $this->balances->totals(['to' => $to, 'branch' => $branch]);
        $earningsOpen = $this->typeTotal(AccountType::Revenue, $opening)->minus($this->typeTotal(AccountType::Expense, $opening));
        $earningsClose = $this->typeTotal(AccountType::Revenue, $closingTotals)->minus($this->typeTotal(AccountType::Expense, $closingTotals));
        $periodNet = $this->netIncome($from, $to, $branch);
        $closedOut = $earningsOpen->plus($periodNet)->minus($earningsClose);

        $rows[] = [
            'code' => null,
            'name' => 'Current earnings',
            'opening' => $earningsOpen,
            'increases' => $periodNet,
            'decreases' => $closedOut,
            'closing' => $earningsClose,
        ];
        foreach (['opening' => $earningsOpen, 'increases' => $periodNet, 'decreases' => $closedOut, 'closing' => $earningsClose] as $k => $v) {
            $totals[$k] = $totals[$k]->plus($v);
        }

        return ['from' => $from, 'to' => $to, 'rows' => $rows, 'totals' => $totals];
    }

    // -----------------------------------------------------------------------
    // General ledger / account statement
    // -----------------------------------------------------------------------

    /**
     * Lines on an account (and its descendants) with opening balance and a
     * running balance in the account's normal direction.
     */
    public function generalLedger(Account|int|string $account, \DateTimeInterface|string|null $from = null, \DateTimeInterface|string|null $to = null, array $options = []): array
    {
        $account = $this->accounts->resolve($account);
        $from = $from ? $this->date($from) : null;
        $to = $to ? $this->date($to) : null;
        $branch = $options['branch'] ?? null;

        $opening = $from
            ? $this->balances->balance($account, Carbon::parse($from)->subDay(), $branch)
            : Money::zero();

        $ids = $account->is_group ? $account->descendantIds() : [$account->id];

        $lines = JournalLine::query()
            ->select('bookkeeper_journal_lines.*')
            ->join('bookkeeper_journal_entries as e', 'e.id', '=', 'bookkeeper_journal_lines.journal_entry_id')
            ->with(['entry', 'account'])
            ->whereIn('account_id', $ids)
            ->where('e.status', 'posted')
            ->when($from, fn ($q) => $q->where('e.date', '>=', $from))
            ->when($to, fn ($q) => $q->where('e.date', '<=', $to))
            ->when($branch, fn ($q) => $q->where('bookkeeper_journal_lines.branch_id', $branch))
            ->orderBy('e.date')
            ->orderBy('e.id')
            ->orderBy('bookkeeper_journal_lines.id')
            ->get();

        $running = $opening;
        $debits = Money::zero();
        $credits = Money::zero();
        $rows = [];

        // Foreign-currency accounts also carry a running balance in their own currency.
        $currency = $account->isForeignCurrency() ? $account->currency : null;
        $foreignOpening = $currency
            ? ($from ? $this->balances->currencyBalance($account, Carbon::parse($from)->subDay(), null, ['branch' => $branch]) : Money::zero($currency))
            : null;
        $foreignRunning = $foreignOpening;
        $sign = $account->type->isDebitNormal() ? 1 : -1;

        foreach ($lines as $line) {
            $running = $running->plus($account->type->balance($line->debit->minor(), $line->credit->minor()));
            $debits = $debits->plus($line->debit);
            $credits = $credits->plus($line->credit);

            $foreign = [];
            if ($currency) {
                $movement = $line->signedAmountIn($currency);
                $foreignRunning = $foreignRunning->plus($sign === 1 ? $movement : $movement->negated());
                $foreign = ['currency_amount' => $movement, 'currency_balance' => $foreignRunning];
            }

            $rows[] = $foreign + [
                'date' => $line->entry->date->toDateString(),
                'entry_id' => $line->journal_entry_id,
                'number' => $line->entry->number,
                'reference' => $line->entry->reference,
                'memo' => $line->memo ?? $line->entry->memo,
                'account' => $line->account->code,
                'subledger_type' => $line->subledger_type,
                'subledger_id' => $line->subledger_id,
                'debit' => $line->debit,
                'credit' => $line->credit,
                'balance' => $running,
            ];
        }

        return [
            'account' => ['code' => $account->code, 'name' => $account->name, 'type' => $account->type->value],
            'from' => $from,
            'to' => $to,
            'opening_balance' => $opening,
            'transactions' => $rows,
            'totals' => [
                'debits' => $debits,
                'credits' => $credits,
                'balance' => $running,
            ],
            'closing_balance' => $running,
            'currency' => $account->currencyCode(),
            'currency_opening_balance' => $foreignOpening,
            'currency_closing_balance' => $foreignRunning,
        ];
    }

    /**
     * Total balance of accounts carrying a tag (e.g. "liquid",
     * "core_capital"), for prudential ratios.
     */
    public function balanceByTag(string $tag, \DateTimeInterface|string|null $asOf = null, ?int $branch = null): Money
    {
        $accounts = Account::query()->postable()->get()->filter(fn (Account $a) => $a->hasTag($tag));

        if ($accounts->isEmpty()) {
            return Money::zero();
        }

        $totals = $this->balances->totals(['accounts' => $accounts->pluck('id')->all(), 'to' => $this->date($asOf), 'branch' => $branch]);

        return Money::sum($accounts->map(fn (Account $a) => $a->balanceIn($totals)));
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Rows for every account of a type in chart order with roll-ups.
     */
    protected function rows(AccountType $type, array $totals, bool $includeZero): array
    {
        $accounts = Account::query()->ofType($type)->orderBy('code')->get();
        $byParent = $accounts->groupBy(fn (Account $a) => $a->parent_id ?? 0);
        $ids = $accounts->pluck('id')->flip();

        $rows = [];

        $walk = function (Account $account, int $depth) use (&$walk, &$rows, $byParent, $totals, $includeZero): Money {
            $index = count($rows);
            $rows[] = null; // placeholder keeps parents before children

            $balance = $account->balanceIn($totals);

            foreach ($byParent->get($account->id, collect()) as $child) {
                $balance = $balance->plus($walk($child, $depth + 1));
            }

            $hasVisibleChild = count($rows) > $index + 1 && array_filter(array_slice($rows, $index + 1)) !== [];

            if ($includeZero || ! $balance->isZero() || $hasVisibleChild) {
                $rows[$index] = [
                    'id' => $account->id,
                    'code' => $account->code,
                    'name' => $account->name,
                    'subtype' => $account->subtype?->value,
                    'depth' => $depth,
                    'is_group' => $account->is_group,
                    'balance' => $balance,
                ];
            }

            return $balance;
        };

        // Roots are accounts whose parent is missing or of another type.
        foreach ($accounts as $account) {
            if ($account->parent_id === null || ! $ids->has($account->parent_id)) {
                $walk($account, 0);
            }
        }

        return array_values(array_filter($rows));
    }

    protected function typeTotal(AccountType $type, array $totals): Money
    {
        $ids = Account::query()->ofType($type)->pluck('id');
        $debit = 0;
        $credit = 0;

        foreach ($ids as $id) {
            $debit += $totals[$id]['debit'] ?? 0;
            $credit += $totals[$id]['credit'] ?? 0;
        }

        return $type->balance($debit, $credit);
    }

    /**
     * @param  list<AccountType>  $types
     * @return array<string, Money>
     */
    protected function bySubtype(array $types, array $totals): array
    {
        $result = [];

        foreach (Account::query()->whereIn('type', array_map(fn ($t) => $t->value, $types))->whereNotNull('subtype')->get() as $account) {
            $value = $account->balanceIn($totals);
            if (! $value->isZero()) {
                $key = $account->subtype->value;
                $result[$key] = ($result[$key] ?? Money::zero())->plus($value);
            }
        }

        return $result;
    }

    /**
     * Current / non-current split and liquid assets.
     */
    protected function classification(array $totals): array
    {
        $out = [
            'current_assets' => Money::zero(),
            'non_current_assets' => Money::zero(),
            'current_liabilities' => Money::zero(),
            'non_current_liabilities' => Money::zero(),
            'liquid_assets' => Money::zero(),
        ];

        /** @var Collection<int, Account> $accounts */
        $accounts = Account::query()->postable()->whereIn('type', ['asset', 'liability'])->get();

        foreach ($accounts as $account) {
            $value = $account->balanceIn($totals);
            $current = (bool) $account->subtype?->isCurrent();
            $key = ($current ? 'current_' : 'non_current_').($account->type === AccountType::Asset ? 'assets' : 'liabilities');
            $out[$key] = $out[$key]->plus($value);

            if ($account->subtype?->isLiquid() || $account->hasTag('liquid')) {
                $out['liquid_assets'] = $out['liquid_assets']->plus($value);
            }
        }

        return $out;
    }

    protected function date(\DateTimeInterface|string|null $date): string
    {
        return Carbon::parse($date ?? now())->toDateString();
    }
}
