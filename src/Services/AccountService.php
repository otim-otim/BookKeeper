<?php

namespace Otimdibossman\BookKeeper\Services;

use Otimdibossman\BookKeeper\Enums\AccountType;
use Otimdibossman\BookKeeper\Exceptions\AccountNotFoundException;
use Otimdibossman\BookKeeper\Models\Account;
use Illuminate\Database\Eloquent\Collection;

class AccountService
{
    /**
     * Create a new accounting account.
     *
     * @param  string      $code        Unique account code, e.g. "1000"
     * @param  string      $name        Human-readable name, e.g. "Cash"
     * @param  AccountType $type        The account type
     * @param  array       $options     Additional options: description, currency, is_active
     */
    public function create(string $code, string $name, AccountType $type, array $options = []): Account
    {
        return Account::create([
            'code'        => $code,
            'name'        => $name,
            'type'        => $type,
            'description' => $options['description'] ?? null,
            'currency'    => $options['currency'] ?? 'USD',
            'is_active'   => $options['is_active'] ?? true,
        ]);
    }

    /**
     * Find an account by its code (e.g. "1000").
     *
     * @throws AccountNotFoundException
     */
    public function findByCode(string $code): Account
    {
        $account = Account::where('code', $code)->first();

        if (! $account) {
            throw new AccountNotFoundException($code);
        }

        return $account;
    }

    /**
     * Find an account by its primary key.
     *
     * @throws AccountNotFoundException
     */
    public function findById(int $id): Account
    {
        $account = Account::find($id);

        if (! $account) {
            throw new AccountNotFoundException($id);
        }

        return $account;
    }

    /**
     * List all accounts, optionally filtered by type.
     */
    public function all(?AccountType $type = null): Collection
    {
        $query = Account::active();

        if ($type !== null) {
            $query->ofType($type);
        }

        return $query->orderBy('code')->get();
    }

    /**
     * Get the current balance of an account.
     */
    public function balance(Account|int|string $account): float
    {
        $account = $this->resolve($account);

        return $account->balance();
    }

    /**
     * Get the balance of an account as of a date.
     */
    public function balanceAsOf(Account|int|string $account, \DateTimeInterface|string $date): float
    {
        $account = $this->resolve($account);

        return $account->balanceAsOf($date);
    }

    /**
     * Deactivate an account.
     */
    public function deactivate(Account|int|string $account): Account
    {
        $account = $this->resolve($account);
        $account->update(['is_active' => false]);

        return $account;
    }

    /**
     * Resolve an Account from an Account model, ID, or code string.
     */
    protected function resolve(Account|int|string $account): Account
    {
        if ($account instanceof Account) {
            return $account;
        }

        return is_int($account) ? $this->findById($account) : $this->findByCode($account);
    }
}
