<?php

use OtimOtim\BookKeeper\Enums\AccountSubtype;
use OtimOtim\BookKeeper\Enums\AccountType;
use OtimOtim\BookKeeper\Events\EntryPosted;
use OtimOtim\BookKeeper\Events\EntryReversed;
use OtimOtim\BookKeeper\Exceptions\AccountNotFoundException;
use OtimOtim\BookKeeper\Exceptions\ImmutableRecordException;
use OtimOtim\BookKeeper\Exceptions\InvalidEntryException;
use OtimOtim\BookKeeper\Exceptions\InvalidOperationException;
use OtimOtim\BookKeeper\Exceptions\PeriodClosedException;
use OtimOtim\BookKeeper\Exceptions\UnbalancedEntryException;
use OtimOtim\BookKeeper\Facades\BookKeeper;
use OtimOtim\BookKeeper\Models\Account;
use OtimOtim\BookKeeper\Models\Contact;
use OtimOtim\BookKeeper\Models\JournalEntry;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    travelToDate('2026-03-15');
    BookKeeper::createAccount('1000', 'Cash', AccountType::Asset, ['role' => 'cash']);
    BookKeeper::createAccount('2000', 'Accounts Payable', AccountType::Liability);
    BookKeeper::createAccount('3000', 'Capital', AccountType::Equity);
    BookKeeper::createAccount('4000', 'Sales', AccountType::Revenue, ['role' => 'sales']);
    BookKeeper::createAccount('5000', 'Expenses', AccountType::Expense);
});

// ---------------------------------------------------------------------------
// Version 1 API
// ---------------------------------------------------------------------------

test('v1 style lines with type and amount still work', function () {
    $entry = BookKeeper::record([
        ['account' => '1000', 'type' => 'debit', 'amount' => 1000.00],
        ['account' => '4000', 'type' => 'credit', 'amount' => 1000.00],
    ], ['memo' => 'Cash sale', 'reference' => 'INV-001']);

    expect($entry->isPosted())->toBeTrue()
        ->and($entry->isBalanced())->toBeTrue()
        ->and($entry->number)->toBe('JE-2026-000001')
        ->and(BookKeeper::balance('1000'))->toEqualMoney('1000.00')
        ->and(BookKeeper::balance('4000'))->toEqualMoney('1000.00');
});

test('asset balance is debits minus credits, liability balance is credits minus debits', function () {
    BookKeeper::record([['account' => '1000', 'debit' => 3000], ['account' => '2000', 'credit' => 3000]]);
    BookKeeper::record([['account' => '2000', 'debit' => 1000], ['account' => '1000', 'credit' => 1000]]);

    expect(BookKeeper::balance('1000'))->toEqualMoney(2000)
        ->and(BookKeeper::balance('2000'))->toEqualMoney(2000);
});

test('transfer debits one account and credits another', function () {
    $entry = BookKeeper::transfer('1000', '3000', '5000.00');

    expect($entry->lines)->toHaveCount(2)
        ->and(BookKeeper::balance('1000'))->toEqualMoney(5000)
        ->and(BookKeeper::balance('3000'))->toEqualMoney(5000);
});

// ---------------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------------

test('an unbalanced entry is rejected and nothing is saved', function () {
    expect(fn () => BookKeeper::record([
        ['account' => '1000', 'debit' => 1000],
        ['account' => '4000', 'credit' => 999.99],
    ]))->toThrow(UnbalancedEntryException::class);

    expect(JournalEntry::count())->toBe(0);
});

test('amounts are exact: many small lines still balance', function () {
    $lines = [];
    for ($i = 0; $i < 10; $i++) {
        $lines[] = ['account' => '1000', 'debit' => 0.1];
    }
    $lines[] = ['account' => '4000', 'credit' => 1];

    expect(BookKeeper::record($lines)->isBalanced())->toBeTrue();
});

test('accounts can be referenced by id, code, role or model', function () {
    $cash = Account::where('code', '1000')->first();

    BookKeeper::record([
        ['account' => $cash->id, 'debit' => 10],
        ['account' => '@sales', 'credit' => 4],
        ['role' => 'sales', 'credit' => 3],
        ['account' => Account::where('code', '4000')->first(), 'credit' => 3],
    ]);

    expect(BookKeeper::balance('@cash'))->toEqualMoney(10);
});

test('unknown accounts and roles are reported clearly', function () {
    expect(fn () => BookKeeper::transfer('9999', '1000', 1))->toThrow(AccountNotFoundException::class)
        ->and(fn () => BookKeeper::transfer('@nope', '1000', 1))->toThrow(AccountNotFoundException::class, 'role [nope]');
});

test('an account with postings cannot become a parent', function () {
    BookKeeper::transfer('1000', '3000', 10);

    BookKeeper::createAccount('1100', 'Bank', AccountSubtype::Bank, ['parent' => '1000']);
})->throws(InvalidOperationException::class, 'has postings');

test('a parent with no postings becomes a group and rolls up its children', function () {
    BookKeeper::createAccount('1100', 'Current assets', AccountType::Asset, ['is_group' => true]);
    BookKeeper::createAccount('1110', 'Petty cash', AccountSubtype::Cash, ['parent' => '1100']);
    BookKeeper::createAccount('1120', 'Bank', AccountSubtype::Bank, ['parent' => '1100']);

    BookKeeper::transfer('1110', '3000', 100);
    BookKeeper::transfer('1120', '3000', 250);

    expect(BookKeeper::balance('1100'))->toEqualMoney(350)
        ->and(fn () => BookKeeper::transfer('1100', '3000', 1))->toThrow(InvalidEntryException::class, 'group account');
});

test('control accounts require a subledger party', function () {
    BookKeeper::createAccount('1200', 'Receivables', AccountSubtype::Receivable, ['is_control' => true]);
    $contact = BookKeeper::contacts()->customer('Acme');

    expect(fn () => BookKeeper::transfer('1200', '4000', 100))->toThrow(InvalidEntryException::class, 'control account');

    BookKeeper::transfer('1200', '4000', 100, ['debit_subledger' => $contact]);

    expect(BookKeeper::subledgers()->balance('1200', $contact))->toEqualMoney(100);
});

test('inactive accounts reject postings', function () {
    BookKeeper::accounts()->deactivate('5000');

    BookKeeper::transfer('5000', '1000', 10);
})->throws(InvalidEntryException::class, 'inactive');

test('subtype must match the account type', function () {
    BookKeeper::createAccount('2100', 'Odd', AccountType::Liability, ['subtype' => 'bank']);
})->throws(InvalidOperationException::class);

// ---------------------------------------------------------------------------
// Immutability, drafts and reversals
// ---------------------------------------------------------------------------

test('posted entries cannot be edited or deleted', function () {
    $entry = BookKeeper::transfer('1000', '4000', 100);

    expect(fn () => $entry->update(['memo' => 'changed']))->toThrow(ImmutableRecordException::class)
        ->and(fn () => $entry->delete())->toThrow(ImmutableRecordException::class)
        ->and(fn () => $entry->lines->first()->update(['debit' => 5]))->toThrow(ImmutableRecordException::class)
        ->and(fn () => $entry->lines->first()->delete())->toThrow(ImmutableRecordException::class);
});

test('drafts do not affect balances until posted', function () {
    $draft = BookKeeper::draft([['account' => '1000', 'debit' => 70], ['account' => '4000', 'credit' => 70]]);

    expect($draft->isDraft())->toBeTrue()
        ->and($draft->number)->toBeNull()
        ->and(BookKeeper::balance('1000'))->toEqualMoney(0);

    $draft = BookKeeper::ledger()->updateDraft($draft, [['account' => '1000', 'debit' => 80], ['account' => '4000', 'credit' => 80]]);
    BookKeeper::ledger()->post($draft);

    expect(BookKeeper::balance('1000'))->toEqualMoney(80)
        ->and($draft->refresh()->number)->toBe('JE-2026-000001');
});

test('drafts can be deleted', function () {
    $draft = BookKeeper::draft([['account' => '1000', 'debit' => 70], ['account' => '4000', 'credit' => 70]]);
    BookKeeper::ledger()->deleteDraft($draft);

    expect(JournalEntry::count())->toBe(0);
});

test('reversal creates a mirror entry and links both', function () {
    Event::fake([EntryReversed::class]);
    $entry = BookKeeper::transfer('1000', '4000', 100, ['reference' => 'R-1']);
    $reversal = BookKeeper::reverse($entry);

    expect(BookKeeper::balance('1000'))->toEqualMoney(0)
        ->and($entry->refresh()->reversed_by_id)->toBe($reversal->id)
        ->and($reversal->reverses_id)->toBe($entry->id)
        ->and($reversal->type->value)->toBe('reversal')
        ->and($reversal->reference)->toBe('R-1');

    Event::assertDispatched(EntryReversed::class);
    expect(fn () => BookKeeper::reverse($entry->refresh()))->toThrow(InvalidOperationException::class, 'already been reversed');
});

test('scheduled reversals post on their date', function () {
    BookKeeper::record(
        [['account' => '5000', 'debit' => 300], ['account' => '2000', 'credit' => 300]],
        ['memo' => 'Accrued rent', 'reverse_on' => '2026-04-01', 'date' => '2026-03-31'],
    );

    expect(BookKeeper::ledger()->processScheduledReversals('2026-03-31'))->toHaveCount(0);

    $reversals = BookKeeper::ledger()->processScheduledReversals('2026-04-02');

    expect($reversals)->toHaveCount(1)
        ->and($reversals->first()->date->toDateString())->toBe('2026-04-01')
        ->and(BookKeeper::balanceAsOf('2000', '2026-03-31'))->toEqualMoney(300)
        ->and(BookKeeper::balance('2000'))->toEqualMoney(0)
        ->and(BookKeeper::ledger()->processScheduledReversals('2026-04-05'))->toHaveCount(0);
});

// ---------------------------------------------------------------------------
// Idempotency, metadata and events
// ---------------------------------------------------------------------------

test('an idempotency key makes recording safe to retry', function () {
    $first = BookKeeper::transfer('1000', '4000', 100, ['idempotency_key' => 'deposit-42']);
    $second = BookKeeper::transfer('1000', '4000', 100, ['idempotency_key' => 'deposit-42']);

    expect($second->id)->toBe($first->id)
        ->and(JournalEntry::count())->toBe(1)
        ->and(BookKeeper::balance('1000'))->toEqualMoney(100);
});

test('entries record branch, source document and creator', function () {
    $source = BookKeeper::contacts()->customer('Source doc');
    $user = BookKeeper::contacts()->customer('Teller');

    $entry = BookKeeper::transfer('1000', '4000', 10, ['branch_id' => 7, 'source' => $source, 'creator' => $user]);

    expect($entry->branch_id)->toBe(7)
        ->and($entry->lines->every(fn ($l) => $l->branch_id === 7))->toBeTrue()
        ->and($entry->source->is($source))->toBeTrue()
        ->and($entry->creator->is($user))->toBeTrue()
        ->and(BookKeeper::ledger()->entries(['source' => $source]))->toHaveCount(1)
        ->and(BookKeeper::balance('1000', branchId: 7))->toEqualMoney(10)
        ->and(BookKeeper::balance('1000', branchId: 8))->toEqualMoney(0);
});

test('posting dispatches an event', function () {
    Event::fake([EntryPosted::class]);
    BookKeeper::transfer('1000', '4000', 10);

    Event::assertDispatched(EntryPosted::class, fn ($e) => $e->entry->number === 'JE-2026-000001');
});

// ---------------------------------------------------------------------------
// Periods
// ---------------------------------------------------------------------------

test('the fiscal year and monthly periods are created on first posting', function () {
    BookKeeper::transfer('1000', '4000', 10);

    $year = BookKeeper::periods()->fiscalYearFor('2026-03-15');

    expect($year->name)->toBe('FY2026')
        ->and($year->periods)->toHaveCount(12)
        ->and($year->periods->first()->start_date->toDateString())->toBe('2026-01-01')
        ->and($year->periods->last()->end_date->toDateString())->toBe('2026-12-31');
});

test('a fiscal year can start in any month', function () {
    config()->set('bookkeeper.fiscal_year.start_month', 7);
    BookKeeper::transfer('1000', '4000', 10);

    $year = BookKeeper::periods()->fiscalYearFor('2026-03-15');

    expect($year->name)->toBe('FY2025/26')
        ->and($year->start_date->toDateString())->toBe('2025-07-01')
        ->and($year->end_date->toDateString())->toBe('2026-06-30');
});

test('postings into a closed period are rejected', function () {
    BookKeeper::transfer('1000', '4000', 10, ['date' => '2026-02-10']);
    BookKeeper::periods()->close('2026-02-01');

    expect(fn () => BookKeeper::transfer('1000', '4000', 10, ['date' => '2026-02-20']))->toThrow(PeriodClosedException::class)
        ->and(fn () => BookKeeper::reverse(JournalEntry::first(), ['date' => '2026-02-25']))->toThrow(PeriodClosedException::class);

    BookKeeper::periods()->reopen('2026-02-01');
    BookKeeper::transfer('1000', '4000', 10, ['date' => '2026-02-20']);

    expect(BookKeeper::balance('1000'))->toEqualMoney(20);
});

test('auto-creation of fiscal years can be turned off', function () {
    config()->set('bookkeeper.fiscal_year.auto_create', false);

    BookKeeper::transfer('1000', '4000', 10);
})->throws(PeriodClosedException::class, 'No accounting period');

// ---------------------------------------------------------------------------
// Opening balances and foreign currency
// ---------------------------------------------------------------------------

test('opening balances post against opening balance equity', function () {
    BookKeeper::createAccount('3900', 'Opening balance equity', AccountSubtype::OpeningBalanceEquity, ['role' => 'opening_balance_equity']);

    $entry = BookKeeper::openingBalances('2026-01-01', ['1000' => 5000, '2000' => 1200]);

    expect($entry->type->value)->toBe('opening')
        ->and(BookKeeper::balance('1000'))->toEqualMoney(5000)
        ->and(BookKeeper::balance('2000'))->toEqualMoney(1200)
        ->and(BookKeeper::balance('3900'))->toEqualMoney(3800);
});

test('foreign currency lines are converted to base currency', function () {
    BookKeeper::exchangeRates()->set('EUR', '1.1', '2026-03-01');

    $entry = BookKeeper::record([
        ['account' => '1000', 'debit' => '100.00', 'currency' => 'EUR'],
        ['account' => '4000', 'credit' => '110.00'],
    ]);

    $line = $entry->lines->first();

    expect($line->debit)->toEqualMoney('110.00')
        ->and($line->currency)->toBe('EUR')
        ->and($line->foreign_amount)->toBe(10000)
        ->and(BookKeeper::balance('1000'))->toEqualMoney('110.00');
});

test('foreign-currency accounts only accept their own currency', function () {
    BookKeeper::createAccount('1050', 'EUR bank', AccountSubtype::Bank, ['currency' => 'EUR']);

    BookKeeper::transfer('1050', '3000', 100);
})->throws(InvalidEntryException::class, 'held in EUR');
