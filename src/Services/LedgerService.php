<?php

namespace OtimOtim\BookKeeper\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use OtimOtim\BookKeeper\Enums\EntryStatus;
use OtimOtim\BookKeeper\Enums\EntryType;
use OtimOtim\BookKeeper\Enums\Side;
use OtimOtim\BookKeeper\Events\EntryPosted;
use OtimOtim\BookKeeper\Events\EntryReversed;
use OtimOtim\BookKeeper\Exceptions\InvalidEntryException;
use OtimOtim\BookKeeper\Exceptions\InvalidOperationException;
use OtimOtim\BookKeeper\Exceptions\UnbalancedEntryException;
use OtimOtim\BookKeeper\Models\Account;
use OtimOtim\BookKeeper\Models\JournalEntry;
use OtimOtim\BookKeeper\Models\JournalLine;
use OtimOtim\BookKeeper\Models\TaxRate;
use OtimOtim\BookKeeper\Support\Currency;
use OtimOtim\BookKeeper\Support\Database;
use OtimOtim\BookKeeper\Support\Money;

/**
 * Records, posts and reverses journal entries.
 *
 * A line is an array with:
 *  - account: Account, ID, code or "@role" (or "role" => 'cash')
 *  - debit => amount, or credit => amount, or type => 'debit'|'credit' with amount
 *    (amounts: Money, numeric string or int/float in major units)
 *  - memo, branch_id, subledger (a party model), tax_rate (TaxRate|ID|code), taxable_amount
 *  - currency + exchange_rate: the amount is in that currency and converted to base
 *    (or pass base_amount to fix the converted value)
 *
 * Entry options: date, memo, reference, type (EntryType), status ('draft'
 * to save without posting), branch_id, source (model), creator (model,
 * defaults to the authenticated user), idempotency_key, reverse_on,
 * metadata.
 */
class LedgerService
{
    public function __construct(
        protected AccountService $accounts,
        protected PeriodService $periods,
        protected BalanceService $balances,
        protected SequenceService $sequences,
        protected ExchangeRateService $rates,
    ) {}

    /**
     * Record a journal entry. It is posted unless $options['status'] is 'draft'.
     *
     * When an idempotency key is given and an entry with that key exists,
     * the existing entry is returned unchanged.
     *
     * @throws UnbalancedEntryException
     * @throws InvalidEntryException
     */
    public function record(array $lines, array $options = []): JournalEntry
    {
        $key = $options['idempotency_key'] ?? null;

        if ($key !== null && ($existing = $this->findByIdempotencyKey($key))) {
            return $existing;
        }

        $normalized = $this->normalize($lines, $options);
        $status = EntryStatus::tryFrom((string) ($options['status'] ?? 'posted')) ?? EntryStatus::Posted;

        try {
            return Database::transaction(function () use ($normalized, $options, $status) {
                $entry = JournalEntry::create([
                    'date' => Carbon::parse($options['date'] ?? now())->toDateString(),
                    'type' => $this->entryType($options['type'] ?? null),
                    'status' => EntryStatus::Draft,
                    'memo' => $options['memo'] ?? null,
                    'reference' => $options['reference'] ?? null,
                    'currency' => Currency::base(),
                    'branch_id' => $options['branch_id'] ?? null,
                    'source_type' => isset($options['source']) ? $options['source']->getMorphClass() : null,
                    'source_id' => isset($options['source']) ? $options['source']->getKey() : null,
                    'creator_type' => ($creator = $this->creator($options)) ? $creator->getMorphClass() : null,
                    'creator_id' => $creator?->getKey(),
                    'idempotency_key' => $options['idempotency_key'] ?? null,
                    'reverses_id' => $options['reverses_id'] ?? null,
                    'reverse_on' => $options['reverse_on'] ?? null,
                    'metadata' => $options['metadata'] ?? null,
                ]);

                foreach ($normalized as $position => $line) {
                    $entry->lines()->create([
                        'position' => $position,
                        'branch_id' => $line['branch_id'] ?? $entry->branch_id,
                    ] + $line);
                }

                if ($status === EntryStatus::Posted) {
                    $this->postEntry($entry, $options['allow_closed_period'] ?? false);
                }

                return $entry->load('lines.account');
            });
        } catch (UniqueConstraintViolationException $e) {
            if ($key !== null && ($existing = $this->findByIdempotencyKey($key))) {
                return $existing;
            }
            throw $e;
        }
    }

    /**
     * Save an entry as a draft. Drafts do not affect balances and can be
     * edited or deleted until posted.
     */
    public function draft(array $lines, array $options = []): JournalEntry
    {
        return $this->record($lines, ['status' => 'draft'] + $options);
    }

    /**
     * Replace the lines (and optionally header fields) of a draft.
     */
    public function updateDraft(JournalEntry $entry, array $lines, array $options = []): JournalEntry
    {
        if (! $entry->isDraft()) {
            throw new InvalidOperationException('Only draft entries can be edited.');
        }

        $normalized = $this->normalize($lines, $options + ['branch_id' => $entry->branch_id]);

        return Database::transaction(function () use ($entry, $normalized, $options) {
            $entry->lines()->get()->each->delete();

            $entry->fill(array_intersect_key($options, array_flip(['memo', 'reference', 'branch_id', 'reverse_on', 'metadata'])));
            if (isset($options['date'])) {
                $entry->date = Carbon::parse($options['date'])->toDateString();
            }
            $entry->save();

            foreach ($normalized as $position => $line) {
                $entry->lines()->create(['position' => $position, 'branch_id' => $line['branch_id'] ?? $entry->branch_id] + $line);
            }

            return $entry->load('lines.account');
        });
    }

    /**
     * Post a draft entry.
     */
    public function post(JournalEntry $entry): JournalEntry
    {
        if ($entry->isPosted()) {
            return $entry;
        }

        return Database::transaction(function () use ($entry) {
            $this->postEntry($entry->refresh(), false);

            return $entry->load('lines.account');
        });
    }

    public function deleteDraft(JournalEntry $entry): void
    {
        if (! $entry->isDraft()) {
            throw new InvalidOperationException('Only draft entries can be deleted; reverse posted entries.');
        }

        Database::transaction(fn () => $entry->delete());
    }

    /**
     * Debit one account and credit another with the same amount.
     */
    public function transfer(
        Account|int|string $debitAccount,
        Account|int|string $creditAccount,
        Money|int|float|string $amount,
        array $options = [],
    ): JournalEntry {
        return $this->record([
            ['account' => $debitAccount, 'debit' => $amount, 'memo' => $options['debit_memo'] ?? null, 'subledger' => $options['debit_subledger'] ?? null],
            ['account' => $creditAccount, 'credit' => $amount, 'memo' => $options['credit_memo'] ?? null, 'subledger' => $options['credit_subledger'] ?? null],
        ], $options);
    }

    /**
     * Reverse a posted entry with a mirror-image entry. The original stays
     * in the ledger and is linked to its reversal.
     *
     * Options: date (default: today, or the original date when
     * 'same_date' => true), memo, reference, idempotency_key.
     */
    public function reverse(JournalEntry $entry, array $options = []): JournalEntry
    {
        if (! $entry->isPosted()) {
            throw new InvalidOperationException('Only posted entries can be reversed; delete drafts instead.');
        }

        if ($entry->isReversed()) {
            throw new InvalidOperationException("Journal entry [{$entry->number}] has already been reversed.");
        }

        return Database::transaction(function () use ($entry, $options) {
            $entry = JournalEntry::query()->lockForUpdate()->findOrFail($entry->id);

            if ($entry->isReversed()) {
                throw new InvalidOperationException("Journal entry [{$entry->number}] has already been reversed.");
            }

            $lines = $entry->lines()->get()->map(fn (JournalLine $line) => [
                'account' => $line->account_id,
                'debit' => $line->credit,
                'credit' => $line->debit,
                'memo' => $line->memo,
                'branch_id' => $line->branch_id,
                'subledger_type' => $line->subledger_type,
                'subledger_id' => $line->subledger_id,
                'currency' => $line->currency,
                'foreign_amount' => $line->foreign_amount === null ? null : -$line->foreign_amount,
                'exchange_rate' => $line->exchange_rate,
                'tax_rate' => $line->tax_rate_id,
                'taxable_amount' => $line->taxable_amount?->negated(),
                'reversal' => true,
            ])->all();

            $date = $options['date'] ?? (($options['same_date'] ?? false) ? $entry->date : now());

            $reversal = $this->record($lines, [
                'date' => $date,
                // Reversing a year-end close is itself part of closing, so reports
                // that leave closing entries out leave both out.
                'type' => $entry->type === EntryType::Closing ? EntryType::Closing : EntryType::Reversal,
                'memo' => $options['memo'] ?? 'Reversal of '.$entry->number.($entry->memo ? ': '.$entry->memo : ''),
                'reference' => $options['reference'] ?? $entry->reference,
                'branch_id' => $entry->branch_id,
                'source' => $entry->source_type ? $entry->source : null,
                'creator' => $options['creator'] ?? null,
                'idempotency_key' => $options['idempotency_key'] ?? null,
                'reverses_id' => $entry->id,
                'allow_closed_period' => $options['allow_closed_period'] ?? false,
            ]);

            $entry->update(['reversed_by_id' => $reversal->id]);

            event(new EntryReversed($entry, $reversal));

            return $reversal;
        });
    }

    /**
     * Reverse posted entries whose scheduled reversal date has arrived
     * (accruals posted with a "reverse_on" option).
     *
     * @return Collection<int, JournalEntry> the reversals created
     */
    public function processScheduledReversals(\DateTimeInterface|string|null $asOf = null): Collection
    {
        $asOf = Carbon::parse($asOf ?? now())->toDateString();

        $due = JournalEntry::query()
            ->posted()
            ->whereNull('reversed_by_id')
            ->whereNotNull('reverse_on')
            ->where('reverse_on', '<=', $asOf)
            ->orderBy('reverse_on')
            ->get();

        return new Collection($due->map(fn (JournalEntry $entry) => $this->reverse($entry, [
            'date' => $entry->reverse_on,
            'memo' => 'Scheduled reversal of '.$entry->number,
            'idempotency_key' => 'scheduled-reversal:'.$entry->id,
        ]))->all());
    }

    /**
     * Post opening balances. $balances maps account codes (or "@role") to amounts
     * in the account's normal-balance direction (negative for the opposite
     * side). The difference goes to the "opening_balance_equity" role.
     */
    public function openingBalances(\DateTimeInterface|string $date, array $balances, array $options = []): JournalEntry
    {
        $lines = [];
        $net = Money::zero();

        foreach ($balances as $ref => $amount) {
            // PHP turns numeric string keys such as '1000' into integers: treat keys as codes.
            $account = $this->accounts->resolve((string) $ref);
            $amount = Money::of($amount);
            if ($amount->isZero()) {
                continue;
            }

            $side = $account->normalBalance();
            if ($amount->isNegative()) {
                $side = $side->opposite();
                $amount = $amount->abs();
            }

            $lines[] = ['account' => $account, $side->value => $amount] + array_intersect_key($options['subledgers'][$ref] ?? [], ['subledger' => 1]);
            $net = $side === Side::Debit ? $net->plus($amount) : $net->minus($amount);
        }

        if (! $net->isZero()) {
            $lines[] = [
                'account' => '@opening_balance_equity',
                $net->isPositive() ? 'credit' : 'debit' => $net->abs(),
            ];
        }

        return $this->record($lines, [
            'date' => $date,
            'type' => EntryType::Opening,
            'memo' => $options['memo'] ?? 'Opening balances',
        ] + $options);
    }

    /**
     * Find journal entries.
     *
     * Filters: from, to, reference, type, status, branch_id, source (model),
     * account (reference), number.
     */
    public function entries(array $filters = []): Collection
    {
        return JournalEntry::query()
            ->with('lines.account')
            ->when(isset($filters['from']), fn ($q) => $q->where('date', '>=', Carbon::parse($filters['from'])->toDateString()))
            ->when(isset($filters['to']), fn ($q) => $q->where('date', '<=', Carbon::parse($filters['to'])->toDateString()))
            ->when(isset($filters['reference']), fn ($q) => $q->where('reference', $filters['reference']))
            ->when(isset($filters['number']), fn ($q) => $q->where('number', $filters['number']))
            ->when(isset($filters['type']), fn ($q) => $q->where('type', $this->entryType($filters['type'])->value))
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status'] instanceof EntryStatus ? $filters['status']->value : $filters['status']))
            ->when(isset($filters['branch_id']), fn ($q) => $q->where('branch_id', $filters['branch_id']))
            ->when(isset($filters['source']), fn ($q) => $q
                ->where('source_type', $filters['source']->getMorphClass())
                ->where('source_id', $filters['source']->getKey()))
            ->when(isset($filters['account']), fn ($q) => $q->whereHas(
                'lines',
                fn ($l) => $l->where('account_id', $this->accounts->resolve($filters['account'])->id)
            ))
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get();
    }

    public function findByIdempotencyKey(string $key): ?JournalEntry
    {
        return JournalEntry::query()->with('lines.account')->where('idempotency_key', $key)->first();
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    protected function postEntry(JournalEntry $entry, bool $allowClosedPeriod): void
    {
        $period = $allowClosedPeriod
            ? $this->periods->periodFor($entry->date)
            : $this->periods->assertOpen($entry->date);

        $debits = (int) $entry->lines()->sum('debit');
        $credits = (int) $entry->lines()->sum('credit');

        if ($debits !== $credits) {
            throw new UnbalancedEntryException(Money::ofMinor($debits), Money::ofMinor($credits));
        }

        $entry->update([
            'status' => EntryStatus::Posted,
            'number' => $this->sequences->next('journal', config('bookkeeper.journal.number_format', 'JE-{Y}-{seq}'), $entry->date),
            'period_id' => $period?->id,
            'posted_at' => now(),
        ]);

        $this->balances->invalidateFrom($entry->date);

        event(new EntryPosted($entry));
    }

    /**
     * Validate lines and convert them to journal_lines attributes.
     *
     * @return list<array<string, mixed>>
     */
    protected function normalize(array $lines, array $options): array
    {
        $normalized = [];
        $debits = Money::zero();
        $credits = Money::zero();
        $enforceSubledgers = (bool) config('bookkeeper.journal.enforce_subledgers', true);

        foreach (array_values($lines) as $i => $line) {
            if (! is_array($line)) {
                throw new InvalidEntryException("Line {$i} must be an array.");
            }

            [$side, $amount] = $this->sideAndAmount($line, $i);

            $account = $this->accounts->resolve($line['account'] ?? (isset($line['role']) ? '@'.$line['role'] : throw new InvalidEntryException("Line {$i} has no account.")));

            if (! $account->is_active) {
                throw new InvalidEntryException("Account [{$account->code}] is inactive.");
            }
            if ($account->is_group) {
                throw new InvalidEntryException("Account [{$account->code}] is a group account and cannot be posted to.");
            }

            // Foreign currency: convert to base.
            $currency = isset($line['currency']) ? strtoupper($line['currency']) : null;
            $foreignAmount = $line['foreign_amount'] ?? null;
            $rate = $line['exchange_rate'] ?? null;

            if ($currency !== null && $currency !== Currency::base() && ! ($line['reversal'] ?? false)) {
                $foreign = $amount instanceof Money && $amount->currency() === $currency ? $amount : Money::of($amount instanceof Money ? $amount->amount() : $amount, $currency);
                $rate ??= $this->rates->rate($currency, $options['date'] ?? now());
                // Callers that split a converted total (documents, payments) pass the
                // base amount they computed so the parts still add up exactly.
                $base = isset($line['base_amount']) ? Money::of($line['base_amount']) : $foreign->convertTo(Currency::base(), $rate);
                $foreignAmount = $foreign->minor() * ($side === Side::Debit ? 1 : -1);
            } else {
                $base = $amount instanceof Money ? $amount : Money::of($amount);
                if ($currency === Currency::base()) {
                    $currency = null;
                }
            }

            if ($base->currency() !== Currency::base()) {
                throw new InvalidEntryException("Line {$i} is in {$base->currency()}; pass 'currency' so it can be converted to ".Currency::base().'.');
            }

            if ($account->currency && $account->currency !== Currency::base() && $currency !== $account->currency) {
                throw new InvalidEntryException("Account [{$account->code}] is held in {$account->currency}; lines must be in that currency.");
            }

            if ($base->isNegative()) {
                throw new InvalidEntryException("Line {$i} has a negative amount; use the opposite side instead.");
            }

            if ($base->isZero()) {
                continue;
            }

            $subledger = $line['subledger'] ?? null;
            $subledgerType = $subledger instanceof Model ? $subledger->getMorphClass() : ($line['subledger_type'] ?? null);
            $subledgerId = $subledger instanceof Model ? $subledger->getKey() : ($line['subledger_id'] ?? null);

            if ($account->is_control && $enforceSubledgers && $subledgerType === null) {
                throw new InvalidEntryException("Account [{$account->code}] is a control account; line {$i} must name a subledger party.");
            }

            $taxRate = $line['tax_rate'] ?? null;
            $taxRateId = match (true) {
                $taxRate instanceof TaxRate => $taxRate->id,
                is_int($taxRate) => $taxRate,
                is_string($taxRate) => TaxRate::query()->effectiveOn($options['date'] ?? now())->where('code', $taxRate)->value('id')
                    ?? throw new InvalidEntryException("Tax rate [{$taxRate}] not found."),
                default => null,
            };

            $taxable = $line['taxable_amount'] ?? null;

            $normalized[] = [
                'account_id' => $account->id,
                'debit' => $side === Side::Debit ? $base->minor() : 0,
                'credit' => $side === Side::Credit ? $base->minor() : 0,
                'memo' => $line['memo'] ?? null,
                'branch_id' => $line['branch_id'] ?? null,
                'subledger_type' => $subledgerType,
                'subledger_id' => $subledgerId,
                'currency' => $currency,
                'foreign_amount' => $foreignAmount,
                'exchange_rate' => $currency ? (string) $rate : null,
                'tax_rate_id' => $taxRateId,
                'taxable_amount' => $taxable === null ? null : ($taxable instanceof Money ? $taxable->minor() : Money::of($taxable)->minor()),
            ];

            $side === Side::Debit ? $debits = $debits->plus($base) : $credits = $credits->plus($base);
        }

        if ($normalized === []) {
            throw new InvalidEntryException('A journal entry needs at least one non-zero line.');
        }

        if (! $debits->equals($credits)) {
            throw new UnbalancedEntryException($debits, $credits);
        }

        return $normalized;
    }

    /**
     * @return array{0: Side, 1: Money|int|float|string}
     */
    protected function sideAndAmount(array $line, int $i): array
    {
        $hasDebit = isset($line['debit']) && ! $this->isZero($line['debit']);
        $hasCredit = isset($line['credit']) && ! $this->isZero($line['credit']);

        if ($hasDebit && $hasCredit) {
            throw new InvalidEntryException("Line {$i} has both a debit and a credit.");
        }

        if ($hasDebit) {
            return [Side::Debit, $line['debit']];
        }

        if ($hasCredit) {
            return [Side::Credit, $line['credit']];
        }

        if (isset($line['type'])) {
            $side = $line['type'] instanceof Side ? $line['type'] : Side::tryFrom(strtolower((string) $line['type']));

            if ($side === null) {
                throw new InvalidEntryException("Line {$i} has an invalid type [{$line['type']}]; use 'debit' or 'credit'.");
            }

            return [$side, $line['amount'] ?? 0];
        }

        // Explicit zero debit/credit lines are dropped later.
        return [array_key_exists('credit', $line) ? Side::Credit : Side::Debit, 0];
    }

    protected function isZero(mixed $amount): bool
    {
        return $amount instanceof Money ? $amount->isZero() : (is_numeric($amount) && (float) $amount == 0.0);
    }

    protected function entryType(EntryType|string|null $type): EntryType
    {
        return $type instanceof EntryType ? $type : (EntryType::tryFrom((string) $type) ?? EntryType::Standard);
    }

    protected function creator(array $options): ?Model
    {
        if (array_key_exists('creator', $options) && $options['creator'] !== null) {
            return $options['creator'];
        }

        $user = function_exists('auth') ? auth()->user() : null;

        return $user instanceof Model ? $user : null;
    }
}
