<?php

namespace OtimOtim\BookKeeper\Services;

use Illuminate\Database\Eloquent\Collection;
use OtimOtim\BookKeeper\Data\AccountData;
use OtimOtim\BookKeeper\Enums\AccountSubtype;
use OtimOtim\BookKeeper\Enums\AccountType;
use OtimOtim\BookKeeper\Exceptions\AccountNotFoundException;
use OtimOtim\BookKeeper\Exceptions\InvalidOperationException;
use OtimOtim\BookKeeper\Models\Account;
use OtimOtim\BookKeeper\Support\Database;
use OtimOtim\BookKeeper\Support\Money;

/**
 * The chart of accounts: creating, finding and organising accounts.
 *
 * Accounts can be referenced everywhere as an Account model, an integer
 * ID, a code string ("1010"), or a role prefixed with "@" ("@cash").
 */
class AccountService
{
    public function __construct(protected BalanceService $balances) {}

    /**
     * Create an account from a code, name and type plus options (parent,
     * subtype, role, description, currency, is_group, is_control,
     * is_system, is_active, tags, cash_flow_category, metadata). The name
     * may be translations: ['en' => 'Cash', 'sw' => 'Fedha taslimu'].
     */
    public function create(string $code, string|array $name, AccountType|AccountSubtype|string $type, array $options = []): Account
    {
        return $this->createFrom(AccountData::make($code, $name, $type, $options));
    }

    public function createFrom(AccountData $data): Account
    {
        $parent = $data->parent !== null ? $this->resolve($data->parent) : null;

        if ($parent && $parent->type !== $data->type) {
            throw new InvalidOperationException("Account [{$data->code}] must have the same type as its parent [{$parent->code}].");
        }

        return Database::transaction(function () use ($data, $parent) {
            if ($parent && ! $parent->is_group) {
                if ($parent->lines()->exists()) {
                    throw new InvalidOperationException("Parent account [{$parent->code}] has postings and cannot become a group account.");
                }
                $parent->update(['is_group' => true]);
            }

            if ($data->role !== null) {
                Account::query()->where('role', $data->role)->update(['role' => null]);
            }

            return Account::create($data->attributes($parent));
        });
    }

    /**
     * Update an account's descriptive attributes. The type cannot change once
     * the account has postings.
     */
    public function update(Account|int|string $account, array $attributes): Account
    {
        $account = $this->resolve($account);

        if (isset($attributes['type']) && $account->lines()->exists()) {
            $new = $attributes['type'] instanceof AccountType ? $attributes['type'] : AccountType::from($attributes['type']);
            if ($new !== $account->type) {
                throw new InvalidOperationException("Account [{$account->code}] has postings; its type cannot change.");
            }
        }

        if (array_key_exists('role', $attributes)) {
            $this->assignRole($account, $attributes['role']);
            unset($attributes['role']);
        }

        if (array_key_exists('parent', $attributes)) {
            $attributes['parent_id'] = $attributes['parent'] ? $this->resolve($attributes['parent'])->id : null;
            unset($attributes['parent']);
        }

        $account->update($attributes);

        return $account->refresh();
    }

    /**
     * Resolve an account reference: model, ID, code, or "@role".
     *
     * @throws AccountNotFoundException
     */
    public function resolve(Account|int|string $account): Account
    {
        return match (true) {
            $account instanceof Account => $account,
            is_int($account) => $this->findById($account),
            str_starts_with($account, '@') => $this->findByRole(substr($account, 1)),
            default => $this->findByCode($account),
        };
    }

    public function find(Account|int|string $account): Account
    {
        return $this->resolve($account);
    }

    public function findByCode(string $code): Account
    {
        return Account::query()->where('code', $code)->first() ?? throw new AccountNotFoundException($code);
    }

    public function findById(int $id): Account
    {
        return Account::query()->find($id) ?? throw new AccountNotFoundException($id);
    }

    /**
     * Find the account playing a role. The bookkeeper.roles config takes
     * precedence over roles stored on accounts.
     */
    public function findByRole(string $role): Account
    {
        $code = config("bookkeeper.roles.{$role}");

        $account = $code
            ? Account::query()->where('code', $code)->first()
            : Account::query()->where('role', $role)->first();

        return $account ?? throw AccountNotFoundException::forRole($role);
    }

    public function hasRole(string $role): bool
    {
        $code = config("bookkeeper.roles.{$role}");

        return $code
            ? Account::query()->where('code', $code)->exists()
            : Account::query()->where('role', $role)->exists();
    }

    public function assignRole(Account|int|string $account, ?string $role): Account
    {
        $account = $this->resolve($account);

        Database::transaction(function () use ($account, $role) {
            if ($role !== null) {
                Account::query()->where('role', $role)->whereKeyNot($account->id)->update(['role' => null]);
            }
            $account->update(['role' => $role]);
        });

        return $account;
    }

    /**
     * Active accounts, optionally of one type, ordered by code.
     */
    public function all(?AccountType $type = null): Collection
    {
        return Account::query()
            ->active()
            ->when($type, fn ($q) => $q->ofType($type))
            ->orderBy('code')
            ->get();
    }

    /**
     * The chart as a tree: root accounts with nested "children" relations.
     */
    public function tree(bool $includeInactive = false): Collection
    {
        $accounts = Account::query()
            ->when(! $includeInactive, fn ($q) => $q->active())
            ->orderBy('code')
            ->get();

        $byParent = $accounts->groupBy(fn (Account $a) => $a->parent_id ?? 0);

        $attach = function (Account $account) use (&$attach, $byParent) {
            $children = new Collection($byParent->get($account->id, collect())->all());
            $children->each($attach);
            $account->setRelation('children', $children);
        };

        $roots = new Collection($byParent->get(0, collect())->all());
        $roots->each($attach);

        return $roots;
    }

    public function balance(Account|int|string $account, ?int $branchId = null): Money
    {
        return $this->balances->balance($this->resolve($account), null, $branchId);
    }

    public function balanceAsOf(Account|int|string $account, \DateTimeInterface|string $date, ?int $branchId = null): Money
    {
        return $this->balances->balance($this->resolve($account), $date, $branchId);
    }

    public function deactivate(Account|int|string $account): Account
    {
        $account = $this->resolve($account);
        $account->update(['is_active' => false]);

        return $account;
    }

    public function activate(Account|int|string $account): Account
    {
        $account = $this->resolve($account);
        $account->update(['is_active' => true]);

        return $account;
    }

    /**
     * Delete an account that has never been posted to.
     */
    public function delete(Account|int|string $account): void
    {
        $this->resolve($account)->delete();
    }
}
