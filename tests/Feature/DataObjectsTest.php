<?php

use OtimOtim\BookKeeper\Data\AccountData;
use OtimOtim\BookKeeper\Data\AppropriationRuleData;
use OtimOtim\BookKeeper\Data\DocumentData;
use OtimOtim\BookKeeper\Data\DocumentLineData;
use OtimOtim\BookKeeper\Data\EntryData;
use OtimOtim\BookKeeper\Data\FixedAssetData;
use OtimOtim\BookKeeper\Data\LineData;
use OtimOtim\BookKeeper\Data\PaymentData;
use OtimOtim\BookKeeper\Enums\AccountType;
use OtimOtim\BookKeeper\Enums\DocumentType;
use OtimOtim\BookKeeper\Enums\EntryType;
use OtimOtim\BookKeeper\Enums\PaymentDirection;
use OtimOtim\BookKeeper\Enums\Side;
use OtimOtim\BookKeeper\Exceptions\InvalidEntryException;
use OtimOtim\BookKeeper\Exceptions\InvalidOperationException;
use OtimOtim\BookKeeper\Facades\BookKeeper;
use OtimOtim\BookKeeper\Support\Money;

beforeEach(function () {
    travelToDate('2026-05-01');
    useChart('standard');
});

test('journal entries can be recorded from typed data objects', function () {
    $entry = BookKeeper::record(
        [LineData::debit('@bank', Money::of('250.00')), LineData::credit('@sales', '250.00', memo: 'Consulting')],
        new EntryData(memo: 'Typed entry', reference: 'T-1', type: EntryType::Adjustment),
    );

    expect($entry->type)->toBe(EntryType::Adjustment)
        ->and($entry->lines[1]->memo)->toBe('Consulting')
        ->and(BookKeeper::balance('@bank'))->toEqualMoney('250.00');
});

test('array lines and data objects are interchangeable', function () {
    expect(LineData::fromArray(['account' => '1120', 'type' => 'credit', 'amount' => 5]))
        ->toEqual(new LineData('1120', Side::Credit, 5))
        ->and(LineData::fromArray(['role' => 'cash', 'debit' => 5])->account)->toBe('@cash')
        ->and(fn () => LineData::fromArray(['account' => '1', 'debit' => 1, 'credit' => 1]))->toThrow(InvalidEntryException::class);
});

test('data objects are immutable; with() returns a changed copy', function () {
    $entry = new EntryData(memo: 'Original');
    $copy = $entry->with(memo: 'Changed', draft: true);

    expect($entry->memo)->toBe('Original')
        ->and($copy->memo)->toBe('Changed')
        ->and($copy->draft)->toBeTrue();
});

test('documents and payments can be created from data objects', function () {
    $customer = BookKeeper::contacts()->customer('Typed Customer');

    $invoice = BookKeeper::documents()->createFrom(new DocumentData(
        type: DocumentType::Invoice,
        contact: $customer,
        lines: [new DocumentLineData('@sales', '40.00', quantity: '3', description: 'Hours')],
    ));

    $payment = BookKeeper::payments()->recordFrom(PaymentData::fromArray([
        'direction' => PaymentDirection::Received,
        'contact' => $customer,
        'amount' => '120.00',
        'account' => '@bank',
        'allocations' => [$invoice],
    ]));

    expect($invoice->total)->toEqualMoney('120.00')
        ->and($invoice->refresh()->amountDue())->toEqualMoney(0)
        ->and($payment->allocations)->toHaveCount(1);
});

test('data objects validate on construction', function () {
    expect(fn () => AccountData::make('9', 'Odd', AccountType::Liability, ['subtype' => 'bank']))->toThrow(InvalidOperationException::class)
        ->and(fn () => new AppropriationRuleData(credit: 'statutory_reserve', base: 'gross'))->toThrow(InvalidOperationException::class, 'Unknown appropriation base')
        ->and(fn () => new AppropriationRuleData(credit: 'statutory_reserve'))->toThrow(InvalidOperationException::class, 'needs a percent')
        ->and(fn () => FixedAssetData::fromArray(['code' => 'A', 'name' => 'A', 'cost' => 100]))->toThrow(InvalidOperationException::class, 'useful_life_months')
        ->and(fn () => new DocumentData(DocumentType::Bill, 1, []))->toThrow(InvalidOperationException::class, 'at least one line');
});
