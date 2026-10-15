<?php

namespace OtimOtim\BookKeeper\Services;

use Illuminate\Support\Carbon;
use OtimOtim\BookKeeper\Enums\AccountType;
use OtimOtim\BookKeeper\Enums\EntryType;
use OtimOtim\BookKeeper\Events\SurplusAppropriated;
use OtimOtim\BookKeeper\Exceptions\InvalidOperationException;
use OtimOtim\BookKeeper\Models\Account;
use OtimOtim\BookKeeper\Models\FiscalYear;
use OtimOtim\BookKeeper\Support\Money;

/**
 * Appropriate a fiscal year's surplus to reserves and funds (statutory
 * reserve, share transfer fund, stabilisation levy, ...), in rule order.
 *
 * A rule is an array with:
 *  - name
 *  - credit: role or "#code" receiving the amount
 *  - debit:  role or "#code" giving it (default retained_earnings)
 *  - base:   net_surplus | remaining | average_total_assets | fixed
 *  - percent (of the base) or amount (for "fixed")
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
     * @return array{fiscal_year: string, net_surplus: Money, items: list<array>, total: Money, remaining: Money}
     */
    public function calculate(FiscalYear|int|string $fiscalYear, ?array $rules = null): array
    {
        $fiscalYear = $this->periods->resolveFiscalYear($fiscalYear);
        $rules ??= (array) config('bookkeeper.appropriation', []);

        $surplus = $this->statements->netIncome($fiscalYear->start_date, $fiscalYear->end_date);
        $remaining = $surplus;
        $items = [];

        foreach ($rules as $i => $rule) {
            $base = $rule['base'] ?? 'net_surplus';

            $amount = match ($base) {
                'net_surplus' => $surplus->percent($rule['percent']),
                'remaining' => $remaining->percent($rule['percent']),
                'average_total_assets' => $this->averageTotalAssets($fiscalYear)->percent($rule['percent']),
                'fixed' => Money::of($rule['amount']),
                default => throw new InvalidOperationException("Unknown appropriation base [{$base}] in rule {$i}."),
            };

            if (! $amount->isPositive()) {
                continue;
            }

            $items[] = [
                'name' => $rule['name'] ?? $rule['credit'],
                'debit' => $rule['debit'] ?? 'retained_earnings',
                'credit' => $rule['credit'],
                'base' => $base,
                'percent' => $rule['percent'] ?? null,
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
            $lines[] = ['account' => $this->ref($item['debit']), 'debit' => $item['amount'], 'memo' => $item['name']];
            $lines[] = ['account' => $this->ref($item['credit']), 'credit' => $item['amount'], 'memo' => $item['name']];
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

    protected function ref(string $reference): string
    {
        return str_starts_with($reference, '#') ? substr($reference, 1) : '@'.$reference;
    }
}
