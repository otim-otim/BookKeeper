<?php

namespace OtimOtim\BookKeeper\Services;

use Illuminate\Database\Eloquent\Model;
use OtimOtim\BookKeeper\Enums\AccountSubtype;
use OtimOtim\BookKeeper\Enums\SessionStatus;
use OtimOtim\BookKeeper\Enums\TillType;
use OtimOtim\BookKeeper\Events\TillSessionClosed;
use OtimOtim\BookKeeper\Exceptions\InvalidOperationException;
use OtimOtim\BookKeeper\Models\Account;
use OtimOtim\BookKeeper\Models\JournalEntry;
use OtimOtim\BookKeeper\Models\Till;
use OtimOtim\BookKeeper\Models\TillSession;
use OtimOtim\BookKeeper\Support\Database;
use OtimOtim\BookKeeper\Support\Money;

/**
 * Teller tills and vaults. Each till has its own cash account; a teller
 * opens a session, transacts against the till account, then counts the
 * cash at close. Any difference is posted as an overage or shortage.
 */
class TillService
{
    public function __construct(
        protected AccountService $accounts,
        protected BalanceService $balances,
        protected LedgerService $ledger,
    ) {}

    /**
     * Create a till or vault. Pass an existing cash account as
     * $options['account'], or a group account as $options['parent'] (default
     * role "cash_on_hand") to create one with code $options['account_code'].
     */
    public function create(string $code, string $name, array $options = []): Till
    {
        return Database::transaction(function () use ($code, $name, $options) {
            $account = isset($options['account'])
                ? $this->accounts->resolve($options['account'])
                : $this->accounts->create($options['account_code'] ?? $code, $name, AccountSubtype::Cash, [
                    'parent' => $options['parent'] ?? (($p = $this->parentFor()) ? $p : null),
                ]);

            return Till::create([
                'code' => $code,
                'name' => $name,
                'type' => TillType::from($options['type'] ?? TillType::Till->value),
                'account_id' => $account->id,
                'branch_id' => $options['branch_id'] ?? null,
                'metadata' => $options['metadata'] ?? null,
            ]);
        });
    }

    public function resolve(Till|int|string $till): Till
    {
        return match (true) {
            $till instanceof Till => $till,
            is_int($till) => Till::query()->find($till),
            default => Till::query()->where('code', $till)->first(),
        } ?? throw new InvalidOperationException("Till [{$till}] not found.");
    }

    public function balance(Till|int|string $till): Money
    {
        return $this->balances->balance($this->resolve($till)->account);
    }

    /**
     * Open a session. If the teller counts the cash on opening and it
     * differs from the book balance, the difference is posted immediately.
     */
    public function open(Till|int|string $till, ?Model $teller = null, Money|int|float|string|null $counted = null): TillSession
    {
        $till = $this->resolve($till);

        return Database::transaction(function () use ($till, $teller, $counted) {
            Till::query()->whereKey($till->id)->lockForUpdate()->first();

            if ($till->openSession()->exists()) {
                throw new InvalidOperationException("Till [{$till->code}] already has an open session.");
            }

            $book = $this->balances->balance($till->account);
            $session = TillSession::create([
                'till_id' => $till->id,
                'teller_type' => $teller?->getMorphClass(),
                'teller_id' => $teller?->getKey(),
                'status' => SessionStatus::Open,
                'opened_at' => now(),
                'opening_balance' => $book,
                'opening_count' => $counted === null ? null : Money::of($counted),
            ]);

            if ($counted !== null) {
                $this->postVariance($till, Money::of($counted)->minus($book), $session, 'Opening count');
            }

            return $session;
        });
    }

    /**
     * Close a session with the cash counted. Posts any overage or shortage
     * to the "cash_over_short" role.
     */
    public function close(TillSession $session, Money|int|float|string $counted, ?string $notes = null): TillSession
    {
        if (! $session->isOpen()) {
            throw new InvalidOperationException('The till session is already closed.');
        }

        return Database::transaction(function () use ($session, $counted, $notes) {
            $till = $session->till;
            $expected = $this->balances->balance($till->account);
            $counted = Money::of($counted);
            $variance = $counted->minus($expected);

            $entry = $this->postVariance($till, $variance, $session, 'Closing count');

            $session->update([
                'status' => SessionStatus::Closed,
                'closed_at' => now(),
                'expected_balance' => $expected,
                'counted_balance' => $counted,
                'variance' => $variance,
                'variance_entry_id' => $entry?->id,
                'notes' => $notes,
            ]);

            event(new TillSessionClosed($session));

            return $session;
        });
    }

    /**
     * Move cash between tills and vaults (e.g. vault to teller at start of
     * day). Tills, but not vaults, must have an open session.
     */
    public function transfer(Till|int|string $from, Till|int|string $to, Money|int|float|string $amount, array $options = []): JournalEntry
    {
        $from = $this->resolve($from);
        $to = $this->resolve($to);
        $amount = Money::of($amount);

        foreach ([$from, $to] as $till) {
            $this->assertOpen($till);
        }

        if ($this->balances->balance($from->account)->isLessThan($amount) && ! ($options['allow_negative'] ?? false)) {
            throw new InvalidOperationException("Till [{$from->code}] does not hold enough cash.");
        }

        return $this->ledger->transfer($to->account, $from->account, $amount, [
            'memo' => $options['memo'] ?? "Cash transfer {$from->code} to {$to->code}",
            'branch_id' => $options['branch_id'] ?? $from->branch_id,
        ] + $options);
    }

    /**
     * Throw unless a till (not a vault) has an open session. Call before
     * posting teller transactions to a till account.
     */
    public function assertOpen(Till|int|string $till): void
    {
        $till = $this->resolve($till);

        if ($till->type === TillType::Till && ! $till->openSession()->exists()) {
            throw new InvalidOperationException("Till [{$till->code}] has no open session.");
        }
    }

    protected function postVariance(Till $till, Money $variance, TillSession $session, string $label): ?JournalEntry
    {
        if ($variance->isZero()) {
            return null;
        }

        $overShort = '@'.config('bookkeeper.tills.over_short_role', 'cash_over_short');
        $memo = "{$label} ".($variance->isPositive() ? 'overage' : 'shortage')." at till {$till->code}";

        return $variance->isPositive()
            ? $this->ledger->transfer($till->account, $overShort, $variance, ['memo' => $memo, 'branch_id' => $till->branch_id, 'source' => $session])
            : $this->ledger->transfer($overShort, $till->account, $variance->abs(), ['memo' => $memo, 'branch_id' => $till->branch_id, 'source' => $session]);
    }

    protected function parentFor(): ?Account
    {
        return $this->accounts->hasRole('cash_on_hand') ? $this->accounts->findByRole('cash_on_hand') : null;
    }
}
