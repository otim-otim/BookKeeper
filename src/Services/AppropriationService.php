<?php

namespace OtimOtim\BookKeeper\Services;

use Illuminate\Support\Carbon;
use OtimOtim\BookKeeper\Data\AppropriationRuleData;
use OtimOtim\BookKeeper\Data\LineData;
use OtimOtim\BookKeeper\Enums\AccountType;
use OtimOtim\BookKeeper\Enums\EntryType;
use OtimOtim\BookKeeper\Events\SurplusAppropriated;
use OtimOtim\BookKeeper\Exceptions\InvalidOperationException;
use OtimOtim\BookKeeper\Models\Account;
use OtimOtim\BookKeeper\Models\FiscalYear;
use OtimOtim\BookKeeper\Support\AccountReference;
use OtimOtim\BookKeeper\Support\Money;

/**
 * Appropriate a fiscal year's surplus to reserves and funds (statutory
 * reserve, share transfer fund, stabilisation levy, ...), in rule order.
 *
 * Rules are AppropriationRuleData (or arrays with name, credit, debit,
 * base: net_surplus | remaining | average_total_assets | fixed, and percent
 * or amount).
 */
class AppropriationService
{
    public function __construct(
        protected AccountService $accounts,
        protected BalanceService $balances,
        protected LedgerService $ledger,
        protected PeriodService $periods,
        protected StatementService $statements,
    ) {}

    /**
     * Work out the appropriation without posting it.
     *
     * @param  list<AppropriationRuleData|array>|null  $rules  default: config('bookkeeper.appropriation')
     * @return array{fiscal_year: string, net_surplus: Money, items: list<array>, total: Money, remaining: Money}
     */
    public function calculate(FiscalYear|int|string $fiscalYear, ?array $rules = null): array
    {
        $fiscalYear = $this->periods->resolveFiscalYear($fiscalYear);
        $rules = AppropriationRuleData::collect($rules ?? (array) config('bookkeeper.appropriation', []));

        $surplus = $this->statements->netIncome($fiscalYear->start_date, $fiscalYear->end_date);
        $remaining = $surplus;
        $items = [];

        foreach ($rules as $rule) {
            $amount = match ($rule->base) {
                'net_surplus' => $surplus->percent($rule->percent),
                'remaining' => $remaining->percent($rule->percent),
                'average_total_assets' => $this->averageTotalAssets($fiscalYear)->percent($rule->percent),
                'fixed' => Money::of($rule->amount),
            };

            if (! $amount->isPositive()) {
                continue;
            }

            $items[] = [
                'name' => $rule->label(),
                'debit' => $rule->debit,
                'credit' => $rule->credit,
                'base' => $rule->base,
                'percent' => $rule->percent,
                'amount' => $amount,
            ];
            $remaining = $remaining->minus($amount);
        }

        return [
            'fiscal_year' => $fiscalYear->name,
            'net_surplus' => $surplus,
            'items' => $items,
            'total' => Money::sum(array_column($items, 'amount')),
            'remaining' => $remaining,
        ];
    }

    /**
     * Post the appropriation (normally after the AGM approves it). Posting
     * is idempotent per fiscal year.
     *
     * Options: date (default today), memo, rules.
     */
    public function appropriate(FiscalYear|int|string $fiscalYear, array $options = []): array
    {
        $fiscalYear = $this->periods->resolveFiscalYear($fiscalYear);

        if ($existing = $this->ledger->findByIdempotencyKey('appropriation:'.$fiscalYear->id)) {
            return ['fiscal_year' => $fiscalYear->name, 'entry' => $existing, 'already_appropriated' => true];
        }

        $calculation = $this->calculate($fiscalYear, $options['rules'] ?? null);

        if (! $calculation['net_surplus']->isPositive()) {
            throw new InvalidOperationException("{$fiscalYear->name} has no surplus to appropriate.");
        }

        if ($calculation['items'] === []) {
            throw new InvalidOperationException('No appropriation rules produced an amount.');
        }

        $lines = [];
        foreach ($calculation['items'] as $item) {
            $lines[] = LineData::debit(AccountReference::fromConfig($item['debit']), $item['amount'], memo: $item['name']);
            $lines[] = LineData::credit(AccountReference::fromConfig($item['credit']), $item['amount'], memo: $item['name']);
        }

        $entry = $this->ledger->record($lines, [
            'date' => Carbon::parse($options['date'] ?? now()),
            'type' => EntryType::Appropriation,
            'memo' => $options['memo'] ?? "Appropriation of {$fiscalYear->name} surplus",
            'idempotency_key' => 'appropriation:'.$fiscalYear->id,
            'metadata' => ['fiscal_year_id' => $fiscalYear->id],
        ]);

        event(new SurplusAppropriated($entry, $calculation));

        return $calculation + ['entry' => $entry];
    }

    /**
     * Average of the opening balance and each month-end total assets.
     */
    public function averageTotalAssets(FiscalYear $fiscalYear): Money
    {
        $assetIds = Account::query()->ofType(AccountType::Asset)->pluck('id')->all();
        $points = [$fiscalYear->start_date->copy()->subDay()];

        for ($m = $fiscalYear->start_date->copy()->endOfMonth()->startOfDay(); $m->lte($fiscalYear->end_date); $m = $m->copy()->addDay()->endOfMonth()->startOfDay()) {
            $points[] = $m;
        }

        $values = array_map(function (Carbon $date) use ($assetIds) {
            $totals = $this->balances->totals(['accounts' => $assetIds, 'to' => $date]);

            return Money::ofMinor(array_sum(array_column($totals, 'debit')) - array_sum(array_column($totals, 'credit')));
        }, $points);

        return Money::sum($values)->dividedBy(count($values));
    }
}
