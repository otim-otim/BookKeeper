<?php

namespace OtimOtim\BookKeeper\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use OtimOtim\BookKeeper\Data\EntryData;
use OtimOtim\BookKeeper\Data\LineData;
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
use OtimOtim\BookKeeper\Support\Currency;
use OtimOtim\BookKeeper\Support\Database;
use OtimOtim\BookKeeper\Support\Money;
use OtimOtim\BookKeeper\Support\Morph;

/**
 * Records, posts and reverses journal entries.
 *
 * Lines are LineData (or the equivalent arrays, see LineData::fromArray)
 * and the entry header is EntryData (or an options array, see
 * EntryData::fromArray). Validation lives in LineNormalizer.
 */
class LedgerService
{
    public function __construct(
        protected AccountService $accounts,
        protected PeriodService $periods,
        protected BalanceService $balances,
        protected SequenceService $sequences,
        protected SettingsService $settings,
        protected LineNormalizer $normalizer,
    ) {}

    /**
     * Record a journal entry. It is posted unless the entry is a draft.
     *
     * When an idempotency key is given and an entry with that key exists,
     * the existing entry is returned unchanged.
     *
     * @param  iterable<LineData|array>  $lines
     *
     * @throws UnbalancedEntryException
     * @throws InvalidEntryException
     */
    public function record(iterable $lines, EntryData|array $entry = []): JournalEntry
    {
        $entry = EntryData::from($entry);

        if ($existing = $this->existing($entry)) {
            return $existing;
        }

        $rows = $this->normalizer->normalize(LineData::collect($lines), $entry);

        try {
            return Database::transaction(function () use ($rows, $entry) {
                $journal = JournalEntry::create([
                    'date' => $entry->date(),
                    'type' => $entry->type,
                    'status' => EntryStatus::Draft,
                    'memo' => $entry->memo,
                    'reference' => $entry->reference,
                    'currency' => Currency::base(),
                    'branch_id' => $entry->branchId,
                    'idempotency_key' => $entry->idempotencyKey,
                    'reverses_id' => $entry->reversesId,
                    'reverse_on' => $entry->reverseOn,
                    'metadata' => $entry->metadata,
                ] + Morph::attributes($entry->source, 'source') + Morph::attributes($this->creator($entry), 'creator'));

                $this->writeLines($journal, $rows);

                if (! $entry->draft) {
                    $this->postEntry($journal, $entry->allowClosedPeriod);
                }

                return $journal->load('lines.account');
            });
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent request with the same idempotency key won the race.
            return $this->existing($entry) ?? throw $e;
        }
    }

    /**
     * Save an entry as a draft. Drafts do not affect balances and can be
     * edited or deleted until posted.
     */
    public function draft(iterable $lines, EntryData|array $entry = []): JournalEntry
    {
        return $this->record($lines, EntryData::from($entry)->with(draft: true));
    }

    /**
     * Replace the lines (and optionally header fields) of a draft.
     */
    public function updateDraft(JournalEntry $journal, iterable $lines, array $attributes = []): JournalEntry
    {
        if (! $journal->isDraft()) {
            throw new InvalidOperationException('Only draft entries can be edited.');
        }

        $entry = EntryData::fromArray($attributes + ['date' => $journal->date, 'branch_id' => $journal->branch_id]);
        $rows = $this->normalizer->normalize(LineData::collect($lines), $entry);

        return Database::transaction(function () use ($journal, $rows, $entry, $attributes) {
            $journal->lines()->get()->each->delete();
            $journal->fill(array_intersect_key($attributes, array_flip(['memo', 'reference', 'branch_id', 'reverse_on', 'metadata'])));
            $journal->date = $entry->date();
            $journal->save();

            $this->writeLines($journal, $rows);

            return $journal->load('lines.account');
        });
    }

    /**
     * Post a draft entry.
     */
    public function post(JournalEntry $journal): JournalEntry
    {
        if ($journal->isPosted()) {
            return $journal;
        }

        return Database::transaction(function () use ($journal) {
            $this->postEntry($journal->refresh(), false);

            return $journal->load('lines.account');
        });
    }

    public function deleteDraft(JournalEntry $journal): void
    {
        if (! $journal->isDraft()) {
            throw new InvalidOperationException('Only draft entries can be deleted; reverse posted entries.');
        }

        Database::transaction(fn () => $journal->delete());
    }

    /**
     * Debit one account and credit another with the same amount.
     *
     * Besides the entry options, accepts debit_memo, credit_memo,
     * debit_subledger and credit_subledger.
     */
    public function transfer(
        Account|int|string $debitAccount,
        Account|int|string $creditAccount,
        Money|int|float|string $amount,
        EntryData|array $entry = [],
    ): JournalEntry {
        $extras = is_array($entry) ? $entry : [];

        return $this->record([
            LineData::debit($debitAccount, $amount, memo: $extras['debit_memo'] ?? null, subledger: $extras['debit_subledger'] ?? null),
            LineData::credit($creditAccount, $amount, memo: $extras['credit_memo'] ?? null, subledger: $extras['credit_subledger'] ?? null),
        ], $entry);
    }

    /**
     * Reverse a posted entry with a mirror-image entry. The original stays
     * in the ledger and is linked to its reversal.
     *
     * Options: date (default: today; 'same_date' => true uses the original
     * date), memo, reference, idempotency_key, creator.
     */
    public function reverse(JournalEntry $journal, EntryData|array $options = []): JournalEntry
    {
        if (is_array($options) && ($options['same_date'] ?? false) && ! isset($options['date'])) {
            $options['date'] = $journal->date;
        }
        $options = EntryData::from($options);

        return Database::transaction(function () use ($journal, $options) {
            $journal = JournalEntry::query()->lockForUpdate()->findOrFail($journal->id);

            if (! $journal->isPosted()) {
                throw new InvalidOperationException('Only posted entries can be reversed; delete drafts instead.');
            }
            if ($journal->isReversed()) {
                throw new InvalidOperationException("Journal entry [{$journal->number}] has already been reversed.");
            }

            $reversal = $this->record(
                $journal->lines()->get()->map(fn (JournalLine $line) => $this->mirror($line))->all(),
                $options->with(
                    // Reversing a year-end close is itself part of closing, so reports
                    // that leave closing entries out leave both out.
                    type: $journal->type === EntryType::Closing ? EntryType::Closing : EntryType::Reversal,
                    memo: $options->memo ?? 'Reversal of '.$journal->number.($journal->memo ? ': '.$journal->memo : ''),
                    reference: $options->reference ?? $journal->reference,
                    branchId: $journal->branch_id,
                    source: $journal->source_type ? $journal->source : null,
                    reversesId: $journal->id,
                    draft: false,
                ),
            );

            $journal->update(['reversed_by_id' => $reversal->id]);

            event(new EntryReversed($journal, $reversal));

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
        $due = JournalEntry::query()
            ->posted()
            ->whereNull('reversed_by_id')
            ->whereNotNull('reverse_on')
            ->where('reverse_on', '<=', Carbon::parse($asOf ?? now())->toDateString())
            ->orderBy('reverse_on')
            ->get();

        return new Collection($due->map(fn (JournalEntry $journal) => $this->reverse($journal, [
            'date' => $journal->reverse_on,
            'memo' => 'Scheduled reversal of '.$journal->number,
            'idempotency_key' => 'scheduled-reversal:'.$journal->id,
        ]))->all());
    }

    /**
     * Post opening balances. $balances maps account codes (or "@role") to
     * amounts in the account's normal-balance direction (negative for the
     * opposite side). The difference goes to the "opening_balance_equity" role.
     */
    public function openingBalances(\DateTimeInterface|string $date, array $balances, array $options = []): JournalEntry
    {
        $lines = [];
        $net = Money::zero();

        foreach ($balances as $reference => $amount) {
            // PHP turns numeric string keys such as '1000' into integers: treat keys as codes.
            $account = $this->accounts->resolve((string) $reference);
            $amount = Money::of($amount);

            $side = $amount->isNegative() ? $account->normalBalance()->opposite() : $account->normalBalance();
            $lines[] = new LineData($account, $side, $amount->abs(), subledger: $options['subledgers'][$reference]['subledger'] ?? null);
            $net = $side === Side::Debit ? $net->plus($amount->abs()) : $net->minus($amount->abs());
        }

        if (! $net->isZero()) {
            $lines[] = new LineData('@opening_balance_equity', $net->isPositive() ? Side::Credit : Side::Debit, $net->abs());
        }

        return $this->record($lines, EntryData::fromArray(['date' => $date, 'memo' => 'Opening balances'] + $options)->with(type: EntryType::Opening));
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
            ->when(isset($filters['type']), fn ($q) => $q->where('type', EntryData::fromArray(['type' => $filters['type']])->type->value))
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status'] instanceof EntryStatus ? $filters['status']->value : $filters['status']))
            ->when(isset($filters['branch_id']), fn ($q) => $q->where('branch_id', $filters['branch_id']))
            ->when(isset($filters['source']), fn ($q) => $q->where(Morph::attributes($filters['source'], 'source')))
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

    protected function existing(EntryData $entry): ?JournalEntry
    {
        return $entry->idempotencyKey !== null ? $this->findByIdempotencyKey($entry->idempotencyKey) : null;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    protected function writeLines(JournalEntry $journal, array $rows): void
    {
        foreach ($rows as $position => $row) {
            $journal->lines()->create(['position' => $position, 'branch_id' => $row['branch_id'] ?? $journal->branch_id] + $row);
        }
    }

    protected function postEntry(JournalEntry $journal, bool $allowClosedPeriod): void
    {
        $period = $allowClosedPeriod
            ? $this->periods->periodFor($journal->date)
            : $this->periods->assertOpen($journal->date);

        // From the first posting on, amounts are in this currency for good.
        $this->settings->lockBaseCurrency();

        $debits = (int) $journal->lines()->sum('debit');
        $credits = (int) $journal->lines()->sum('credit');

        if ($debits !== $credits) {
            throw new UnbalancedEntryException(Money::ofMinor($debits), Money::ofMinor($credits));
        }

        $journal->update([
            'status' => EntryStatus::Posted,
            'number' => $this->sequences->next('journal', config('bookkeeper.journal.number_format', 'JE-{Y}-{seq}'), $journal->date),
            'period_id' => $period?->id,
            'posted_at' => now(),
        ]);

        $this->balances->invalidateFrom($journal->date);

        event(new EntryPosted($journal));
    }

    /**
     * The opposite of a posted line, copied exactly (including its foreign amount).
     */
    protected function mirror(JournalLine $line): LineData
    {
        return new LineData(
            account: $line->account_id,
            side: $line->side()->opposite(),
            amount: $line->amount(),
            memo: $line->memo,
            branchId: $line->branch_id,
            subledgerType: $line->subledger_type,
            subledgerId: $line->subledger_id,
            currency: $line->currency,
            exchangeRate: $line->exchange_rate,
            taxRate: $line->tax_rate_id,
            taxableAmount: $line->taxable_amount?->negated(),
            foreignAmount: $line->foreign_amount === null ? null : -$line->foreign_amount,
        );
    }

    protected function creator(EntryData $entry): ?Model
    {
        if ($entry->creator !== null) {
            return $entry->creator;
        }

        $user = function_exists('auth') ? auth()->user() : null;

        return $user instanceof Model ? $user : null;
    }
}
