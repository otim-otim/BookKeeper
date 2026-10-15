<?php

use OtimOtim\BookKeeper\Facades\BookKeeper;

beforeEach(function () {
    travelToDate('2026-06-30');
    useChart('standard');

    // A small year of trading.
    BookKeeper::transfer('1120', '3100', 10000, ['date' => '2026-01-02', 'memo' => 'Owner invests']);
    BookKeeper::transfer('1510', '1120', 2400, ['date' => '2026-01-10', 'memo' => 'Buy equipment']);
    BookKeeper::transfer('1120', '4100', 7000, ['date' => '2026-03-01', 'memo' => 'Sales']);
    BookKeeper::transfer('5300', '1120', 1500, ['date' => '2026-03-05', 'memo' => 'Rent']);
    BookKeeper::transfer('5700', '1590', 200, ['date' => '2026-03-31', 'memo' => 'Depreciation']);
    BookKeeper::transfer('1120', '2600', 3000, ['date' => '2026-04-01', 'memo' => 'Bank loan']);
    BookKeeper::transfer('3200', '1120', 500, ['date' => '2026-05-01', 'memo' => 'Owner drawings']);
});

test('trial balance lists net balances and balances', function () {
    $tb = BookKeeper::trialBalance('2026-06-30');

    $bank = collect($tb['accounts'])->firstWhere('code', '1120');

    expect($tb['balanced'])->toBeTrue()
        ->and($tb['totals']['debits'])->toEqualMoney($tb['totals']['credits'])
        ->and($bank['debit'])->toEqualMoney(15600)
        ->and($bank['credit'])->toEqualMoney(0);
});

test('balance sheet includes unclosed earnings and balances', function () {
    $sheet = BookKeeper::balanceSheet('2026-06-30');

    expect($sheet['balanced'])->toBeTrue()
        ->and($sheet['totals']['assets'])->toEqualMoney(15600 + 2400 - 200)
        ->and($sheet['totals']['liabilities'])->toEqualMoney(3000)
        ->and($sheet['current_earnings'])->toEqualMoney(5300)
        ->and($sheet['totals']['equity'])->toEqualMoney(10000 - 500 + 5300)
        ->and($sheet['classification']['current_assets'])->toEqualMoney(15600)
        ->and($sheet['classification']['liquid_assets'])->toEqualMoney(15600);
});

test('balance sheet rows are a tree with roll-ups', function () {
    $assets = BookKeeper::balanceSheet('2026-06-30')['assets'];

    expect($assets[0])->toMatchArray(['code' => '1000', 'depth' => 0, 'is_group' => true])
        ->and($assets[0]['balance'])->toEqualMoney(17800)
        ->and(collect($assets)->firstWhere('code', '1590')['balance'])->toEqualMoney(-200)
        ->and(collect($assets)->firstWhere('code', '1590')['depth'])->toBe(2);
});

test('balance sheet as of an earlier date', function () {
    $sheet = BookKeeper::balanceSheet('2026-01-31');

    expect($sheet['balanced'])->toBeTrue()
        ->and($sheet['totals']['assets'])->toEqualMoney(10000);
});

test('income statement for a range', function () {
    $pl = BookKeeper::incomeStatement('2026-01-01', '2026-06-30');

    expect($pl['totals']['revenue'])->toEqualMoney(7000)
        ->and($pl['totals']['expenses'])->toEqualMoney(1700)
        ->and($pl['totals']['net_income'])->toEqualMoney(5300)
        ->and($pl['by_subtype']['depreciation_expense'])->toEqualMoney(200)
        ->and(BookKeeper::incomeStatement('2026-04-01', '2026-06-30')['totals']['net_income'])->toEqualMoney(0);
});

test('cash flow statement reconciles to the change in cash', function () {
    $cf = BookKeeper::cashFlow('2026-01-01', '2026-06-30');

    expect($cf['reconciled'])->toBeTrue()
        ->and($cf['opening_cash'])->toEqualMoney(0)
        ->and($cf['closing_cash'])->toEqualMoney(15600)
        ->and($cf['operating']['total'])->toEqualMoney(5300 + 200)
        ->and($cf['investing']['total'])->toEqualMoney(-2400)
        ->and($cf['financing']['total'])->toEqualMoney(10000 + 3000 - 500);
});

test('statement of changes in equity', function () {
    $soce = BookKeeper::changesInEquity('2026-01-01', '2026-06-30');

    expect($soce['totals']['closing'])->toEqualMoney(10000 - 500 + 5300)
        ->and(collect($soce['rows'])->firstWhere('name', 'Current earnings')['increases'])->toEqualMoney(5300);
});

test('general ledger has opening and running balances', function () {
    $gl = BookKeeper::generalLedger('1120', '2026-03-01', '2026-06-30');

    expect($gl['opening_balance'])->toEqualMoney(7600)
        ->and($gl['transactions'])->toHaveCount(4)
        ->and($gl['transactions'][0]['balance'])->toEqualMoney(14600)
        ->and($gl['closing_balance'])->toEqualMoney(15600)
        ->and($gl['transactions'][0]['number'])->not->toBeNull();
});

test('balances by tag', function () {
    BookKeeper::accounts()->update('1120', ['tags' => ['liquid']]);

    expect(BookKeeper::statements()->balanceByTag('liquid', '2026-06-30'))->toEqualMoney(15600);
});

test('balances read from snapshots match balances summed from lines', function () {
    $before = BookKeeper::trialBalance('2026-06-30');

    BookKeeper::balances()->snapshot('2026-03-31');
    BookKeeper::transfer('1120', '4100', 50, ['date' => '2026-06-01']);

    expect(BookKeeper::balance('1120'))->toEqualMoney(15650)
        ->and(BookKeeper::balanceAsOf('1120', '2026-03-31'))->toEqualMoney(13100)
        ->and(BookKeeper::balanceAsOf('1120', '2026-02-01'))->toEqualMoney(7600);

    // A back-dated posting invalidates later snapshots.
    BookKeeper::transfer('1120', '4100', 25, ['date' => '2026-02-15']);

    expect(BookKeeper::balanceAsOf('1120', '2026-03-31'))->toEqualMoney(13125)
        ->and($before['balanced'])->toBeTrue();
});

test('reports serialise to JSON', function () {
    $json = json_encode(BookKeeper::balanceSheet('2026-06-30'));

    expect($json)->toContain('"assets":"17800.00"');
});
