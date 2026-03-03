<?php

namespace Otimdibossman\BookKeeper\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;
use Otimdibossman\BookKeeper\BookKeeperServiceProvider;
use Otimdibossman\BookKeeper\Facades\BookKeeper;
use Otimdibossman\BookKeeper\Enums\AccountType;
use Otimdibossman\BookKeeper\Exceptions\UnbalancedEntryException;

class BookKeeperTest extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [BookKeeperServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['BookKeeper' => BookKeeper::class];
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    // ------------------------------------------------------------------
    // Account management
    // ------------------------------------------------------------------

    public function test_can_create_an_account(): void
    {
        $account = BookKeeper::createAccount('1000', 'Cash', AccountType::Asset);

        $this->assertEquals('1000', $account->code);
        $this->assertEquals('Cash', $account->name);
        $this->assertEquals(AccountType::Asset, $account->type);
    }

    public function test_can_retrieve_account_by_code(): void
    {
        BookKeeper::createAccount('2000', 'Accounts Payable', AccountType::Liability);
        $account = BookKeeper::account('2000');

        $this->assertEquals('Accounts Payable', $account->name);
    }

    public function test_new_account_has_zero_balance(): void
    {
        BookKeeper::createAccount('1000', 'Cash', AccountType::Asset);

        $this->assertEquals(0.0, BookKeeper::balance('1000'));
    }

    // ------------------------------------------------------------------
    // Ledger / journal entries
    // ------------------------------------------------------------------

    public function test_can_record_balanced_journal_entry(): void
    {
        BookKeeper::createAccount('1000', 'Cash', AccountType::Asset);
        BookKeeper::createAccount('4000', 'Sales Revenue', AccountType::Revenue);

        $entry = BookKeeper::record([
            ['account' => '1000', 'type' => 'debit',  'amount' => 1000.00],
            ['account' => '4000', 'type' => 'credit', 'amount' => 1000.00],
        ], ['memo' => 'Cash sale', 'reference' => 'INV-001']);

        $this->assertNotNull($entry->id);
        $this->assertTrue($entry->isBalanced());
    }

    public function test_unbalanced_entry_throws_exception(): void
    {
        $this->expectException(UnbalancedEntryException::class);

        BookKeeper::createAccount('1000', 'Cash', AccountType::Asset);
        BookKeeper::createAccount('4000', 'Sales Revenue', AccountType::Revenue);

        BookKeeper::record([
            ['account' => '1000', 'type' => 'debit',  'amount' => 1000.00],
            ['account' => '4000', 'type' => 'credit', 'amount' => 500.00], // deliberately wrong
        ]);
    }

    public function test_transfer_convenience_method(): void
    {
        BookKeeper::createAccount('1000', 'Cash', AccountType::Asset);
        BookKeeper::createAccount('3000', 'Capital', AccountType::Equity);

        $entry = BookKeeper::transfer('1000', '3000', 5000.00);

        $this->assertTrue($entry->isBalanced());
        $this->assertEquals(5000.0, BookKeeper::balance('1000'));
        $this->assertEquals(5000.0, BookKeeper::balance('3000'));
    }

    // ------------------------------------------------------------------
    // Account balances
    // ------------------------------------------------------------------

    public function test_asset_account_balance_is_debits_minus_credits(): void
    {
        BookKeeper::createAccount('1000', 'Cash', AccountType::Asset);
        BookKeeper::createAccount('2000', 'Accounts Payable', AccountType::Liability);

        BookKeeper::record([
            ['account' => '1000', 'type' => 'debit',  'amount' => 3000],
            ['account' => '2000', 'type' => 'credit', 'amount' => 3000],
        ]);

        BookKeeper::record([
            ['account' => '2000', 'type' => 'debit',  'amount' => 1000],
            ['account' => '1000', 'type' => 'credit', 'amount' => 1000],
        ]);

        $this->assertEquals(2000.0, BookKeeper::balance('1000')); // asset: debits - credits
        $this->assertEquals(2000.0, BookKeeper::balance('2000')); // liability: credits - debits
    }

    // ------------------------------------------------------------------
    // Financial statements
    // ------------------------------------------------------------------

    public function test_balance_sheet_is_balanced(): void
    {
        BookKeeper::createAccount('1000', 'Cash',    AccountType::Asset);
        BookKeeper::createAccount('3000', 'Capital', AccountType::Equity);

        BookKeeper::transfer('1000', '3000', 10000);

        $sheet = BookKeeper::balanceSheet();

        $this->assertTrue($sheet['balanced']);
        $this->assertEquals(10000.0, $sheet['totals']['assets']);
    }

    public function test_income_statement_calculates_net_income(): void
    {
        BookKeeper::createAccount('1000', 'Cash',     AccountType::Asset);
        BookKeeper::createAccount('4000', 'Revenue',  AccountType::Revenue);
        BookKeeper::createAccount('5000', 'Expenses', AccountType::Expense);

        // Revenue booking
        BookKeeper::record([
            ['account' => '1000', 'type' => 'debit',  'amount' => 5000],
            ['account' => '4000', 'type' => 'credit', 'amount' => 5000],
        ]);

        // Expense booking
        BookKeeper::record([
            ['account' => '5000', 'type' => 'debit',  'amount' => 2000],
            ['account' => '1000', 'type' => 'credit', 'amount' => 2000],
        ]);

        $p_and_l = BookKeeper::incomeStatement();

        $this->assertEquals(5000.0, $p_and_l['totals']['revenue']);
        $this->assertEquals(2000.0, $p_and_l['totals']['expenses']);
        $this->assertEquals(3000.0, $p_and_l['totals']['net_income']);
    }

    public function test_trial_balance_is_balanced(): void
    {
        BookKeeper::createAccount('1000', 'Cash',    AccountType::Asset);
        BookKeeper::createAccount('3000', 'Capital', AccountType::Equity);

        BookKeeper::transfer('1000', '3000', 7500);

        $trial = BookKeeper::trialBalance();

        $this->assertTrue($trial['balanced']);
        $this->assertEquals($trial['totals']['debits'], $trial['totals']['credits']);
    }
}
