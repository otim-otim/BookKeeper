<?php

namespace OtimOtim\BookKeeper\Services;

use Brick\Math\BigDecimal;
use Closure;
use Illuminate\Support\Carbon;
use OtimOtim\BookKeeper\Enums\TaxType;
use OtimOtim\BookKeeper\Events\DividendsPaid;
use OtimOtim\BookKeeper\Exceptions\InvalidOperationException;
use OtimOtim\BookKeeper\Models\Account;
use OtimOtim\BookKeeper\Models\TaxRate;
use OtimOtim\BookKeeper\Support\Money;

/**
 * Dividends on time-weighted share balances (or interest on any
 * time-weighted subledger balance), with optional withholding tax.
 */
class DividendService
{
    public function __construct(
        protected AccountService $accounts,
        protected SubledgerService $subledgers,
        protected LedgerService $ledger,
        protected TaxService $taxes,
    ) {}

    /**
     * Calculate dividends at $rate percent of each holder's day-weighted
     * average balance on $account between $from and $to.
     *
     * Options: withholding (TaxRate/ID/code of a withholding rate),
     * prorate (bool: scale by days/365 for a rate quoted per annum over a
     * partial year), minimum (skip holders whose gross is below this).
     */
    public function calculate(Account|int|string $account, string|int|float $rate, \DateTimeInterface|string $from, \DateTimeInterface|string $to, array $options = []): array
    {
        $account = $this->accounts->resolve($account);
        $from = Carbon::parse($from)->startOfDay();
        $to = Carbon::parse($to)->startOfDay();

        $withholding = isset($options['withholding']) ? $this->taxes->resolve($options['withholding'], $to) : null;
        if ($withholding && $withholding->type !== TaxType::Withholding) {
            throw new InvalidOperationException("Tax rate [{$withholding->code}] is not a withholding rate.");
        }

        $factor = BigDecimal::of((string) $rate);
        if ($options['prorate'] ?? false) {
            $factor = $factor->multipliedBy((int) $from->diffInDays($to) + 1)->dividedBy(365, 12, \Brick\Math\RoundingMode::Down);
        }

        $minimum = Money::of($options['minimum'] ?? 0);
        $holders = [];
        $totals = ['average' => Money::zero(), 'gross' => Money::zero(), 'withholding' => Money::zero(), 'net' => Money::zero()];

        foreach ($this->subledgers->averageBalances($account, $from, $to) as $key => $row) {
            if (! $row['average']->isPositive()) {
                continue;
            }

            $gross = $row['average']->percent($factor);
            if (! $gross->isPositive() || $gross->isLessThan($minimum)) {
                continue;
            }

            $tax = $withholding ? $gross->percent($withholding->rate) : Money::zero();
            $holder = [
                'subledger_type' => $row['subledger_type'],
                'subledger_id' => $row['subledger_id'],
                'average_balance' => $row['average'],
                'gross' => $gross,
                'withholding' => $tax,
                'net' => $gross->minus($tax),
            ];
            $holders[$key] = $holder;

            foreach (['average' => 'average_balance', 'gross' => 'gross', 'withholding' => 'withholding', 'net' => 'net'] as $t => $k) {
                $totals[$t] = $totals[$t]->plus($holder[$k]);
            }
        }

        return [
            'account' => $account->code,
            'rate' => (string) $rate,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'withholding_tax_rate_id' => $withholding?->id,
            'holders' => $holders,
            'totals' => $totals,
        ];
    }

    /**
     * Post a calculated dividend.
     *
     * Options:
     *  - debit: account/"@role" funding the dividend (default "@retained_earnings")
     *  - credit: where each holder's net dividend goes. An account reference
     *    (e.g. "@dividends_payable"), or a Closure receiving
     *    (subledger_type, subledger_id) and returning ['account' => ..., 'subledger' => model]
     *    to pay into each member's savings account.
     *  - date, memo, idempotency_key
     */
    public function pay(array $calculation, array $options = []): \OtimOtim\BookKeeper\Models\JournalEntry
    {
        if ($calculation['holders'] === []) {
            throw new InvalidOperationException('The calculation has no holders to pay.');
        }

        $credit = $options['credit'] ?? '@dividends_payable';
        $lines = [['account' => $options['debit'] ?? '@retained_earnings', 'debit' => $calculation['totals']['gross'], 'memo' => 'Dividend declared']];

        foreach ($calculation['holders'] as $holder) {
            $target = $credit instanceof Closure
                ? $credit($holder['subledger_type'], $holder['subledger_id'])
                : ['account' => $credit, 'subledger' => $this->subledgers->party($holder['subledger_type'], $holder['subledger_id'])];

            $lines[] = ['credit' => $holder['net'], 'memo' => 'Dividend'] + $target;
        }

        if ($calculation['totals']['withholding']->isPositive()) {
            $rate = TaxRate::query()->findOrFail($calculation['withholding_tax_rate_id']);
            $lines[] = [
                'account' => $rate->account_id,
                'credit' => $calculation['totals']['withholding'],
                'tax_rate' => $rate,
                'taxable_amount' => $calculation['totals']['gross'],
                'memo' => 'Withholding tax on dividends',
            ];
        }

        $entry = $this->ledger->record($lines, [
            'date' => $options['date'] ?? now(),
            'memo' => $options['memo'] ?? "Dividend at {$calculation['rate']}% for {$calculation['from']} to {$calculation['to']}",
            'idempotency_key' => $options['idempotency_key'] ?? null,
            'metadata' => ['dividend' => ['account' => $calculation['account'], 'rate' => $calculation['rate'], 'from' => $calculation['from'], 'to' => $calculation['to']]],
        ]);

        event(new DividendsPaid($entry, $calculation));

        return $entry;
    }
}
