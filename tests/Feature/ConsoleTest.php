<?php

use OtimOtim\BookKeeper\Facades\BookKeeper;
use OtimOtim\BookKeeper\Models\Account;

test('install imports a chart and opens the fiscal year', function () {
    travelToDate('2026-10-15');

    $this->artisan('bookkeeper:install', ['--list' => true])
        ->expectsOutputToContain('sacco-ug')
        ->assertSuccessful();

    $this->artisan('bookkeeper:install', ['--chart' => 'sacco-ug'])->assertSuccessful();

    expect(Account::where('role', 'member_savings')->exists())->toBeTrue()
        ->and(BookKeeper::periods()->fiscalYearFor('2026-10-15')->name)->toBe('FY2026');

    // Running it again is harmless.
    $this->artisan('bookkeeper:install', ['--chart' => 'sacco-ug'])
        ->expectsOutputToContain('0 account(s) created')
        ->assertSuccessful();
});

test('depreciation, snapshot and reversal commands run', function () {
    travelToDate('2026-10-15');
    useChart('standard');

    $this->artisan('bookkeeper:depreciate')->assertSuccessful();
    $this->artisan('bookkeeper:snapshot', ['date' => '2026-10-14'])->assertSuccessful();
    $this->artisan('bookkeeper:reversals')->assertSuccessful();
});
