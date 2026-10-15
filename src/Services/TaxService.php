<?php

namespace OtimOtim\BookKeeper\Services;

use Illuminate\Support\Carbon;
use OtimOtim\BookKeeper\Enums\AccountType;
use OtimOtim\BookKeeper\Enums\TaxType;
use OtimOtim\BookKeeper\Exceptions\InvalidOperationException;
use OtimOtim\BookKeeper\Models\TaxRate;
use OtimOtim\BookKeeper\Support\Money;

/**
 * Tax rates with effective dates (VAT, withholding tax, ...), tax
 * calculations, and the tax report.
 *
 * Rates are configuration, not code: when a rate changes, add a new
 * version of the same code with a later effective_from date.
 */
class TaxService
{
    public function __construct(
        protected AccountService $accounts,
        protected BalanceService $balances,
    ) {}

    /**
     * Options: account (reference; the tax control account — omit on a
     * purchase rate that is not recoverable), effective_from, effective_to,
     * description.
     */
    public function create(string $code, string $name, TaxType|string $type, string|int|float $rate, array $options = []): TaxRate
    {
        $type = $type instanceof TaxType ? $type : TaxType::from($type);

        if ($type !== TaxType::Purchase && ! isset($options['account'])) {
            throw new InvalidOperationException("A {$type->value} tax rate needs an account to post tax to.");
        }

        $from = isset($options['effective_from']) ? Carbon::parse($options['effective_from']) : null;

        // End the previous version of this code the day before the new one starts.
        if ($from) {
            TaxRate::query()
                ->where('code', $code)
                ->whereNull('effective_to')
                ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<', $from->toDateString()))
                ->update(['effective_to' => $from->copy()->subDay()->toDateString()]);
        }

        return TaxRate::create([
            'code' => $code,
            'name' => $name,
            'type' => $type,
            'rate' => (string) $rate,
            'account_id' => isset($options['account']) ? $this->accounts->resolve($options['account'])->id : null,
            'effective_from' => $from?->toDateString(),
            'effective_to' => isset($options['effective_to']) ? Carbon::parse($options['effective_to'])->toDateString() : null,
            'is_active' => $options['is_active'] ?? true,
            'description' => $options['description'] ?? null,
        ]);
    }

    /**
     * Find a tax rate by model, ID or code (effective on $date).
     */
    public function resolve(TaxRate|int|string $rate, \DateTimeInterface|string|null $date = null): TaxRate
    {
        if ($rate instanceof TaxRate) {
            return $rate;
        }

        $found = is_int($rate)
            ? TaxRate::query()->find($rate)
            : TaxRate::query()->effectiveOn($date ?? now())->where('code', $rate)->orderByDesc('effective_from')->first();

        return $found ?? throw new InvalidOperationException("Tax rate [{$rate}] not found or not effective on ".Carbon::parse($date ?? now())->toDateString().'.');
    }

    /**
     * Split an amount into net, tax and gross.
     *
     * @return array{net: Money, tax: Money, gross: Money}
     */
    public function calculate(Money $amount, TaxRate|int|string $rate, bool $inclusive = false, \DateTimeInterface|string|null $date = null): array
    {
        $rate = $this->resolve($rate, $date);

        if ($inclusive) {
            $tax = $rate->taxIn($amount);

            return ['net' => $amount->minus($tax), 'tax' => $tax, 'gross' => $amount];
        }

        $tax = $rate->taxOn($amount);

        return ['net' => $amount, 'tax' => $tax, 'gross' => $amount->plus($tax)];
    }

    /**
     * Tax report for a period: per rate, the taxable base and the tax
     * posted, from journal lines tagged with the rate. Output tax less
     * input tax gives the net amount payable to the tax authority.
     */
    public function report(\DateTimeInterface|string $from, \DateTimeInterface|string $to): array
    {
        $rows = $this->balances->lineQuery(['from' => $from, 'to' => $to])
            ->whereNotNull('l.tax_rate_id')
            ->groupBy('l.tax_rate_id')
            ->selectRaw('l.tax_rate_id, SUM(l.debit) as debit, SUM(l.credit) as credit, SUM(COALESCE(l.taxable_amount, 0)) as taxable')
            ->get()
            ->keyBy('tax_rate_id');

        $rates = TaxRate::query()->with('account')->whereIn('id', $rows->keys())->orderBy('code')->get();

        $report = [];
        $output = Money::zero();
        $input = Money::zero();
        $withheld = Money::zero();

        foreach ($rates as $rate) {
            $row = $rows[$rate->id];
            // Liability accounts accumulate tax as credits, asset accounts as debits.
            $creditNormal = $rate->account?->type !== AccountType::Asset;
            $tax = Money::ofMinor($creditNormal ? (int) $row->credit - (int) $row->debit : (int) $row->debit - (int) $row->credit);
            $taxable = Money::ofMinor((int) $row->taxable);

            match ($rate->type) {
                TaxType::Sales => $output = $output->plus($tax),
                TaxType::Purchase => $input = $input->plus($tax),
                TaxType::Withholding => $withheld = $withheld->plus($tax),
            };

            $report[] = [
                'code' => $rate->code,
                'name' => $rate->name,
                'type' => $rate->type->value,
                'rate' => $rate->rate,
                'taxable' => $taxable,
                'tax' => $tax,
            ];
        }

        return [
            'from' => Carbon::parse($from)->toDateString(),
            'to' => Carbon::parse($to)->toDateString(),
            'rates' => $report,
            'totals' => [
                'output_tax' => $output,
                'input_tax' => $input,
                'net_payable' => $output->minus($input),
                'withholding' => $withheld,
            ],
        ];
    }
}
