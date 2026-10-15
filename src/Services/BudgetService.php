<?php

namespace OtimOtim\BookKeeper\Services;

use Illuminate\Support\Carbon;
use OtimOtim\BookKeeper\Enums\AccountType;
use OtimOtim\BookKeeper\Enums\BudgetStatus;
use OtimOtim\BookKeeper\Enums\EntryType;
use OtimOtim\BookKeeper\Exceptions\InvalidOperationException;
use OtimOtim\BookKeeper\Models\Account;
use OtimOtim\BookKeeper\Models\Budget;
use OtimOtim\BookKeeper\Models\BudgetLine;
use OtimOtim\BookKeeper\Models\FiscalYear;
use OtimOtim\BookKeeper\Support\Database;
use OtimOtim\BookKeeper\Support\Money;

/**
 * Monthly budgets per account and budget-versus-actual reporting.
 * Budget amounts are in each account's normal-balance direction.
 */
class BudgetService
{
    public function __construct(
        protected AccountService $accounts,
        protected BalanceService $balances,
        protected PeriodService $periods,
    ) {}

    /**
     * Create a budget for a fiscal year (model, ID, name or year) or for
     * explicit dates via $options['start_date'] and ['end_date'].
     */
    public function create(string $name, FiscalYear|int|string|null $fiscalYear = null, array $options = []): Budget
    {
        $fiscalYear = $fiscalYear !== null ? $this->periods->resolveFiscalYear($fiscalYear) : null;

        $start = Carbon::parse($options['start_date'] ?? $fiscalYear?->start_date ?? throw new InvalidOperationException('A budget needs a fiscal year or a start_date.'));
        $end = Carbon::parse($options['end_date'] ?? $fiscalYear?->end_date ?? $start->copy()->addYear()->subDay());

        return Budget::create([
            'name' => $name,
            'fiscal_year_id' => $fiscalYear?->id,
            'start_date' => $start,
            'end_date' => $end,
            'branch_id' => $options['branch_id'] ?? null,
            'status' => BudgetStatus::Draft,
            'description' => $options['description'] ?? null,
        ]);
    }

    /**
     * Set the budget for one account and month.
     */
    public function set(Budget $budget, Account|int|string $account, \DateTimeInterface|string $month, Money|int|float|string $amount): BudgetLine
    {
        $this->assertEditable($budget);
        $account = $this->accounts->resolve($account);
        $month = Carbon::parse($month)->startOfMonth();

        if ($month->lt($budget->start_date->copy()->startOfMonth()) || $month->gt($budget->end_date)) {
            throw new InvalidOperationException("{$month->format('M Y')} is outside the budget period.");
        }

        return BudgetLine::query()->updateOrCreate(
            ['budget_id' => $budget->id, 'account_id' => $account->id, 'period_start' => $month->toDateString()],
            ['amount' => Money::of($amount)],
        );
    }

    /**
     * Spread an annual amount evenly over the budget's months (remainders
     * go to the first months so the total is exact).
     */
    public function setAnnual(Budget $budget, Account|int|string $account, Money|int|float|string $amount): void
    {
        $months = $this->months($budget);
        $parts = Money::of($amount)->allocate(array_fill(0, count($months), 1));

        Database::transaction(function () use ($budget, $account, $months, $parts) {
            foreach ($months as $i => $month) {
                $this->set($budget, $account, $month, $parts[$i]);
            }
        });
    }

    public function approve(Budget $budget): Budget
    {
        $budget->update(['status' => BudgetStatus::Approved, 'approved_at' => now()]);

        return $budget;
    }

    /**
     * Budget against actual for every budgeted account, over the whole
     * budget or a sub-range.
     */
    public function report(Budget $budget, \DateTimeInterface|string|null $from = null, \DateTimeInterface|string|null $to = null): array
    {
        $from = Carbon::parse($from ?? $budget->start_date)->startOfMonth();
        $to = Carbon::parse($to ?? $budget->end_date);

        $budgeted = BudgetLine::query()
            ->with('account')
            ->where('budget_id', $budget->id)
            ->where('period_start', '>=', $from->toDateString())
            ->where('period_start', '<=', $to->toDateString())
            ->get()
            ->groupBy('account_id');

        $actuals = $this->balances->totals([
            'accounts' => $budgeted->keys()->all(),
            'from' => $from,
            'to' => $to,
            'branch' => $budget->branch_id,
            'exclude' => [EntryType::Closing],
        ]);

        $rows = [];
        $net = ['budget' => Money::zero(), 'actual' => Money::zero()];

        foreach ($budgeted as $accountId => $lines) {
            /** @var Account $account */
            $account = $lines->first()->account;
            $budgetAmount = Money::sum($lines->pluck('amount'));
            $actual = $account->balanceIn($actuals);
            $variance = $actual->minus($budgetAmount);

            $rows[] = [
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type->value,
                'budget' => $budgetAmount,
                'actual' => $actual,
                'variance' => $variance,
                'variance_percent' => $budgetAmount->isZero() ? null : round($variance->minor() / $budgetAmount->minor() * 100, 2),
                // Over budget is favourable for revenue, unfavourable for expenses.
                'favourable' => $account->type === AccountType::Revenue ? ! $variance->isNegative() : ! $variance->isPositive(),
            ];

            if ($account->type === AccountType::Revenue) {
                $net['budget'] = $net['budget']->plus($budgetAmount);
                $net['actual'] = $net['actual']->plus($actual);
            } elseif ($account->type === AccountType::Expense) {
                $net['budget'] = $net['budget']->minus($budgetAmount);
                $net['actual'] = $net['actual']->minus($actual);
            }
        }

        usort($rows, fn ($a, $b) => strcmp($a['code'], $b['code']));

        return [
            'budget' => $budget->name,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'rows' => $rows,
            'net_income' => $net + ['variance' => $net['actual']->minus($net['budget'])],
        ];
    }

    /**
     * @return list<Carbon>
     */
    protected function months(Budget $budget): array
    {
        $months = [];
        for ($m = $budget->start_date->copy()->startOfMonth(); $m->lte($budget->end_date); $m = $m->copy()->addMonthNoOverflow()) {
            $months[] = $m;
        }

        return $months;
    }

    protected function assertEditable(Budget $budget): void
    {
        if ($budget->status === BudgetStatus::Approved) {
            throw new InvalidOperationException("Budget [{$budget->name}] is approved and can no longer change.");
        }
    }
}
