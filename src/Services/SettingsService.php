<?php

namespace OtimOtim\BookKeeper\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use OtimOtim\BookKeeper\Exceptions\InvalidOperationException;
use OtimOtim\BookKeeper\Support\Currency;
use OtimOtim\BookKeeper\Support\Database;

/**
 * Settings that belong to a set of books rather than to the application,
 * stored in bookkeeper_settings. The most important is the base currency:
 * it is written when the books are set up (or on the first posting) and
 * cannot change once entries exist, because every stored amount is
 * expressed in it.
 *
 * Values are cached per database (connection, database name, schema), so
 * multi-tenant applications get each tenant's own settings.
 */
class SettingsService
{
    public const BASE_CURRENCY = 'base_currency';

    /** @var array<string, array<string, ?string>> */
    protected array $cache = [];

    /** @var array<string, bool> */
    protected array $tables = [];

    public function get(string $key, ?string $default = null): ?string
    {
        $scope = $this->scope();

        if (! array_key_exists($key, $this->cache[$scope] ?? [])) {
            // Not migrated (yet) on this connection, e.g. a tenant app's central
            // database: use defaults. Checked up front rather than by catching
            // the error, which would abort an open PostgreSQL transaction.
            $this->tables[$scope] ??= Database::connection()->getSchemaBuilder()->hasTable('bookkeeper_settings');

            if (! $this->tables[$scope]) {
                return $default;
            }

            $this->cache[$scope][$key] = Database::connection()->table('bookkeeper_settings')->where('key', $key)->value('value');
        }

        return $this->cache[$scope][$key] ?? $default;
    }

    public function set(string $key, ?string $value): void
    {
        Database::connection()->table('bookkeeper_settings')->updateOrInsert(
            ['key' => $key],
            ['value' => $value, 'updated_at' => now(), 'created_at' => now()],
        );

        $this->cache[$this->scope()][$key] = $value;
    }

    public function baseCurrency(): string
    {
        return $this->get(self::BASE_CURRENCY) ?? Currency::configuredBase();
    }

    public function isBaseCurrencyLocked(): bool
    {
        return $this->get(self::BASE_CURRENCY) !== null && $this->hasPostings();
    }

    /**
     * Set the books' base currency. Allowed until the first entry is posted.
     */
    public function setBaseCurrency(string $currency): void
    {
        $currency = Currency::assertValid($currency);

        if ($currency === $this->get(self::BASE_CURRENCY)) {
            return;
        }

        if ($this->hasPostings()) {
            throw new InvalidOperationException(
                "The books already have posted entries in {$this->baseCurrency()}; the base currency cannot change to {$currency}."
            );
        }

        $this->set(self::BASE_CURRENCY, $currency);
    }

    /**
     * Record the current base currency permanently. Called when the first
     * entry is posted so a later config change cannot reinterpret amounts.
     */
    public function lockBaseCurrency(): void
    {
        if ($this->get(self::BASE_CURRENCY) !== null) {
            return;
        }

        try {
            Database::connection()->table('bookkeeper_settings')->insert([
                'key' => self::BASE_CURRENCY,
                'value' => Currency::configuredBase(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Another process locked it first.
        }

        unset($this->cache[$this->scope()][self::BASE_CURRENCY]);
    }

    public function flush(): void
    {
        $this->cache = [];
        $this->tables = [];
    }

    protected function hasPostings(): bool
    {
        return Database::connection()->table('bookkeeper_journal_entries')->where('status', 'posted')->exists();
    }

    /**
     * Identity of the database the current books live in.
     */
    protected function scope(): string
    {
        $connection = Database::connection();

        return implode('|', [
            $connection->getName(),
            $connection->getDatabaseName(),
            json_encode($connection->getConfig('search_path')),
            $connection->getTablePrefix(),
        ]);
    }
}
