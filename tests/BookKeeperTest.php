<?php

use Otimdibossman\BookKeeper\Enums\AccountType;
use Otimdibossman\BookKeeper\Exceptions\UnbalancedEntryException;
use Otimdibossman\BookKeeper\Facades\BookKeeper;

// ---------------------------------------------------------------------------
// Account management
// ---------------------------------------------------------------------------

test('can create an account', function () {
    $account = BookKeeper::createAccount('1000', 'Cash', AccountType::Asset);

    expect($account->code)->toBe('1000')
        ->and($account->name)->toBe('Cash')
        ->and($account->type)->toBe(AccountType::Asset);
});

test('can retrieve an account by code', function () {
    BookKeeper::createAccount('2000', 'Accounts Payable', AccountType::Liability);

    expect(BookKeeper::account('2000')->name)->toBe('Accounts Payable');
});

test('a new account has zero balance', function () {
    BookKeeper::createAccount('1000', 'Cash', AccountType::Asset);

    expect(BookKeeper::balance('1000'))->toBe(0.0);
});

// ---------------------------------------------------------------------------
// Journal entries
// ---------------------------------------------------------------------------

test('can record a balanced journal entry', function () {
    BookKeeper::createAccount('1000', 'Cash', AccountType::Asset);
    BookKeeper::createAccount('4000', 'Sales Revenue', AccountType::Revenue);

    $entry = BookKeeper::record([
        ['account' => '1000', 'type' => 'debit',  'amount' => 1000.00],
        ['account' => '4000', 'type' => 'credit', 'amount' => 1000.00],
    ], ['memo' => 'Cash sale', 'reference' => 'INV-001']);

    expect($entry->id)->not->toBeNull()
        ->and($entry->isBalanced())->toBeTrue();
});

test('an unbalanced entry throws an exception', function () {
    BookKeeper::createAccount('1000', 'Cash', AccountType::Asset);
    BookKeeper::createAccount('4000', 'Sales Revenue', AccountType::Revenue);

    BookKeeper::record([
        ['account' => '1000', 'type' => 'debit',  'amount' => 1000.00],
        ['account' => '4000', 'type' => 'credit', 'amount' => 500.00], // deliberately wrong
    ]);
})->throws(UnbalancedEntryException::class);

test('transfer convenience method creates a balanced entry', function () {
    BookKeeper::createAccount('1000', 'Cash', AccountType::Asset);
    BookKeeper::createAccount('3000', 'Capital', AccountType::Equity);

    $entry = BookKeeper::transfer('1000', '3000', 5000.00);

    expect($entry->isBalanced())->toBeTrue()
        ->and(BookKeeper::balance('1000'))->toBe(5000.0)
        ->and(BookKeeper::balance('3000'))->toBe(5000.0);
});

// ---------------------------------------------------------------------------
// Account balances (normal balance direction)
// ---------------------------------------------------------------------------

test('asset balance is debits minus credits, liability balance is credits minus debits', function () {
    BookKeeper::createAccount('1000', 'Cash', AccountType::Asset);
    BookKeeper::createAccount('2000', 'Accounts Payable', AccountType::Liability);

    // Borrow cash
    BookKeeper::record([
        ['account' => '1000', 'type' => 'debit',  'amount' => 3000],
        ['account' => '2000', 'type' => 'credit', 'amount' => 3000],
    ]);

    // Repay part of it
    BookKeeper::record([
        ['account' => '2000', 'type' => 'debit',  'amount' => 1000],
        ['account' => '1000', 'type' => 'credit', 'amount' => 1000],
    ]);

    expect(BookKeeper::balance('1000'))->toBe(2000.0) // asset:     debits - credits
        ->and(BookKeeper::balance('2000'))->toBe(2000.0); // liability: credits - debits
});

// ---------------------------------------------------------------------------
// Financial statements
// ---------------------------------------------------------------------------

test('balance sheet is in balance', function () {
    BookKeeper::createAccount('1000', 'Cash',    AccountType::Asset);
    BookKeeper::createAccount('3000', 'Capital', AccountType::Equity);

    BookKeeper::transfer('1000', '3000', 10000);

    $sheet = BookKeeper::balanceSheet();

    expect($sheet['balanced'])->toBeTrue()
        ->and($sheet['totals']['assets'])->toBe(10000.0);
});

test('income statement calculates net income correctly', function () {
    BookKeeper::createAccount('1000', 'Cash',     AccountType::Asset);
    BookKeeper::createAccount('4000', 'Revenue',  AccountType::Revenue);
    BookKeeper::createAccount('5000', 'Expenses', AccountType::Expense);

    BookKeeper::record([
        ['account' => '1000', 'type' => 'debit',  'amount' => 5000],
        ['account' => '4000', 'type' => 'credit', 'amount' => 5000],
    ]);

    BookKeeper::record([
        ['account' => '5000', 'type' => 'debit',  'amount' => 2000],
        ['account' => '1000', 'type' => 'credit', 'amount' => 2000],
    ]);

    $pl = BookKeeper::incomeStatement();

    expect($pl['totals']['revenue'])->toBe(5000.0)
        ->and($pl['totals']['expenses'])->toBe(2000.0)
        ->and($pl['totals']['net_income'])->toBe(3000.0);
});

test('trial balance has equal debit and credit totals', function () {
    BookKeeper::createAccount('1000', 'Cash',    AccountType::Asset);
    BookKeeper::createAccount('3000', 'Capital', AccountType::Equity);

    BookKeeper::transfer('1000', '3000', 7500);

    $trial = BookKeeper::trialBalance();

    expect($trial['balanced'])->toBeTrue()
        ->and($trial['totals']['debits'])->toBe($trial['totals']['credits']);
});
