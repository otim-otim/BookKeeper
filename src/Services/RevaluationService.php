<?php

namespace OtimOtim\BookKeeper\Services;

use Illuminate\Support\Carbon;
use OtimOtim\BookKeeper\Data\LineData;
use OtimOtim\BookKeeper\Enums\AccountType;
use OtimOtim\BookKeeper\Enums\EntryType;
use OtimOtim\BookKeeper\Enums\Side;
use OtimOtim\BookKeeper\Models\Account;
use OtimOtim\BookKeeper\Models\JournalEntry;
use OtimOtim\BookKeeper\Support\AccountReference;
use OtimOtim\BookKeeper\Support\Currency;
use OtimOtim\BookKeeper\Support\Money;

/**
 * Unrealised exchange gains and losses (IAS 21): restate foreign-currency
 * monetary balances (bank accounts, receivables, payables, borrowings) at
 * the closing rate.
 *
 * Balances are grouped by account, currency and subledger party, so each
 * customer's or member's foreign balance is adjusted individually. The
 * entry reverses the next day by default, so settlements still realise
 * differences against the original rates.
 */
class RevaluationService
{
    public function __construct(
        protected AccountService $accounts,
        protected BalanceService $balances,
        protected LedgerService $ledger,
        protected ExchangeRateService $rates,
    ) {}

    /**
     * Preview the adjustments without posting.
     *
     * @return list<array{account: string, currency: string, subledger_type: ?string, subledger_id: int|string|null, foreign_balance: Money, rate: string, book_value: Money, revalued: Money, adjustment: Money}>
     */
    public function calculate(\DateTimeInterface|string $asOf): array
    {
        $asOf = Carbon::parse($asOf)->toDateString();
        $base = Currency::base();

        $monetary = Account::query()
            ->postable()
            ->whereIn('type', [AccountType::Asset->value, AccountType::Liability->value])
            ->get()
            ->filter(fn (Account $a) => $a->subtype === null || $a->subtype->isMonetary())
            ->keyBy('id');

        if ($monetary->isEmpty()) {
            return [];
        }

        $groups = $this->balances->lineQuery(['accounts' => $monetary->keys()->all(), 'to' => $asOf])
            ->whereNotNull('l.currency')
            ->where('l.currency', '!=', $base)
            ->groupBy('l.account_id', 'l.currency', 'l.subledger_type', 'l.subledger_id')
            ->selectRaw('l.account_id, l.currency, l.subledger_type, l.subledger_id, SUM(l.foreign_amount) as foreign_net, SUM(l.debit) as debit, SUM(l.credit) as credit')
            ->get();

        $rows = [];

        foreach ($groups as $group) {
            $currency = strtoupper(trim($group->currency));
            $foreign = Money::ofMinor((int) $group->foreign_net, $currency);
            $book = Money::ofMinor((int) $group->debit - (int) $group->credit);
            $rate = $this->rates->rate($currency, $asOf);
            $revalued = $foreign->convertTo($base, $rate);
            $adjustment = $revalued->minus($book);

            if ($adjustment->isZero()) {
                continue;
            }

            $rows[] = [
                'account' => $monetary[$group->account_id]->code,
                'account_id' => (int) $group->account_id,
                'currency' => $currency,
                'subledger_type' => $group->subledger_type,
                'subledger_id' => $group->subledger_id,
                'foreign_balance' => $foreign,
                'rate' => $rate,
                'book_value' => $book,
                'revalued' => $revalued,
                'adjustment' => $adjustment,
            ];
        }

        return $rows;
    }

    /**
     * Post the revaluation as of $asOf (normally a month end).
     *
     * Options: reverse (bool, default config), role (gain/loss account role).
     */
    public function revalue(\DateTimeInterface|string $asOf, array $options = []): ?JournalEntry
    {
        $asOf = Carbon::parse($asOf)->startOfDay();
        $key = 'fx-revaluation:'.$asOf->toDateString();

        if ($existing = $this->ledger->findByIdempotencyKey($key)) {
            return $existing;
        }

        // Earlier revaluations due to reverse by now must be reversed first,
        // or this one would be measured against stale book values.
        $this->ledger->processScheduledReversals($asOf);

        $rows = $this->calculate($asOf);
        if ($rows === []) {
            return null;
        }

        $role = AccountReference::fromConfig($options['role'] ?? config('bookkeeper.revaluation.role', 'fx_gain_loss'));
        $lines = [];
        $net = Money::zero();

        foreach ($rows as $row) {
            // Change the base value only; the foreign amount stays as it is.
            $lines[] = new LineData(
                account: $row['account_id'],
                side: $row['adjustment']->isPositive() ? Side::Debit : Side::Credit,
                amount: Money::zero($row['currency']),
                memo: "Revalue {$row['currency']} at {$row['rate']}",
                subledgerType: $row['subledger_type'],
                subledgerId: $row['subledger_id'],
                currency: $row['currency'],
                exchangeRate: $row['rate'],
                baseAmount: $row['adjustment']->abs(),
            );
            $net = $net->plus($row['adjustment']);
        }

        // When gains and losses cancel out, the adjustments balance on their own.
        if (! $net->isZero()) {
            $lines[] = LineData::signed($role, $net->negated(), memo: 'Unrealised exchange difference');
        }

        $reverse = $options['reverse'] ?? config('bookkeeper.revaluation.reverse', true);

        return $this->ledger->record($lines, [
            'date' => $asOf,
            'type' => EntryType::Adjustment,
            'memo' => 'Unrealised exchange revaluation at '.$asOf->toDateString(),
            'reverse_on' => $reverse ? $asOf->copy()->addDay() : null,
            'idempotency_key' => $key,
        ]);
    }
}
