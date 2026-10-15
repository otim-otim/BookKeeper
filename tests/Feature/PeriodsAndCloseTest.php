<?php

use OtimOtim\BookKeeper\Close\CloseContext;
use OtimOtim\BookKeeper\Contracts\CloseTask;
use OtimOtim\BookKeeper\Enums\EntryType;
use OtimOtim\BookKeeper\Exceptions\InvalidOperationException;
use OtimOtim\BookKeeper\Exceptions\PeriodClosedException;
use OtimOtim\BookKeeper\Facades\BookKeeper;
use OtimOtim\BookKeeper\Models\JournalEntry;

class AccrueInterestTask implements CloseTask
{
    public static array $ran = [];

    public function handle(CloseContext $context): void
    {
        static::$ran[] = $context->type->value.':'.$context->date->toDateString();

        BookKeeper::transfer('5800', '2200', 10, [
            'date' => $context->date,
            'memo' => 'Interest accrual',
            'idempotency_key' => $context->key('accrue-interest'),
        ]);
    }
}

beforeEach(function () {
    travelToDate('2026-12-31');
    useChart('standard');
    AccrueInterestTask::$ran = [];

    BookKeeper::transfer('1120', '3100', 10000, ['date' => '2026-01-02']);
    BookKeeper::transfer('1120', '4100', 4000, ['date' => '2026-05-01']);
    BookKeeper::transfer('5300', '1120', 1000, ['date' => '2026-06-01']);
});

test('closing a month runs tasks and locks the period', function () {
    config()->set('bookkeeper.close.month', [AccrueInterestTask::class]);

    $run = BookKeeper::close()->closeMonth('2026-06-15');

    expect($run->date->toDateString())->toBe('2026-06-30')
        ->and(AccrueInterestTask::$ran)->toBe(['month:2026-06-30'])
        ->and(BookKeeper::balance('2200'))->toEqualMoney(10)
        ->and(fn () => BookKeeper::transfer('1120', '4100', 1, ['date' => '2026-06-10']))->toThrow(PeriodClosedException::class)
        ->and(fn () => BookKeeper::close()->closeMonth('2026-06-15'))->toThrow(InvalidOperationException::class);
});

test('closing a day runs tasks, reversals and snapshots once', function () {
    config()->set('bookkeeper.close.day', [AccrueInterestTask::class]);
    BookKeeper::record(
        [['account' => '5300', 'debit' => 100], ['account' => '2200', 'credit' => 100]],
        ['date' => '2026-07-31', 'reverse_on' => '2026-08-01'],
    );

    $run = BookKeeper::close()->closeDay('2026-08-01');

    expect($run->metadata['reversals'])->toBe(1)
        ->and($run->metadata['snapshot_accounts'])->toBeGreaterThan(0)
        ->and(AccrueInterestTask::$ran)->toBe(['day:2026-08-01'])
        ->and(fn () => BookKeeper::close()->closeDay('2026-08-01'))->toThrow(InvalidOperationException::class, 'already run');
});

test('closing the year moves profit to retained earnings and opens the next year', function () {
    $run = BookKeeper::close()->closeYear(2026);

    $closing = JournalEntry::where('type', EntryType::Closing->value)->first();

    expect($closing->date->toDateString())->toBe('2026-12-31')
        ->and(BookKeeper::balance('4100'))->toEqualMoney(0)
        ->and(BookKeeper::balance('5300'))->toEqualMoney(0)
        ->and(BookKeeper::balance('3300'))->toEqualMoney(3000)
        ->and(BookKeeper::periods()->fiscalYearFor('2026-06-01')->isOpen())->toBeFalse()
        ->and(BookKeeper::periods()->fiscalYearFor('2027-01-01'))->not->toBeNull()
        ->and($run->metadata['closing_entry_id'])->toBe($closing->id);

    // Reports for the closed year still show its results.
    expect(BookKeeper::incomeStatement('2026-01-01', '2026-12-31')['totals']['net_income'])->toEqualMoney(3000)
        ->and(BookKeeper::balanceSheet('2026-12-31')['balanced'])->toBeTrue()
        ->and(BookKeeper::balanceSheet('2026-12-31')['current_earnings'])->toEqualMoney(0);

    // The new year posts normally; the old one is locked.
    travelToDate('2027-01-05');
    BookKeeper::transfer('1120', '4100', 100);
    expect(fn () => BookKeeper::transfer('1120', '4100', 100, ['date' => '2026-12-30']))->toThrow(PeriodClosedException::class);
});

test('a closed year can be reopened', function () {
    BookKeeper::close()->closeYear(2026);
    BookKeeper::close()->reopenYear(2026);

    expect(BookKeeper::balance('3300'))->toEqualMoney(0)
        ->and(BookKeeper::balance('4100'))->toEqualMoney(4000)
        ->and(BookKeeper::periods()->fiscalYearFor('2026-06-01')->isOpen())->toBeTrue();

    BookKeeper::periods()->reopen('2026-12-01');
    BookKeeper::transfer('1120', '4100', 1, ['date' => '2026-12-31']);

    BookKeeper::close()->closeYear(2026);
    expect(BookKeeper::balance('3300'))->toEqualMoney(3001)
        ->and(BookKeeper::incomeStatement('2026-01-01', '2026-12-31')['totals']['net_income'])->toEqualMoney(3001);
});

test('a close task must implement the contract', function () {
    config()->set('bookkeeper.close.day', [stdClass::class]);

    BookKeeper::close()->closeDay('2026-08-01');
})->throws(InvalidOperationException::class, 'must implement');

test('artisan commands drive the close', function () {
    $this->artisan('bookkeeper:close-day', ['date' => '2026-08-01'])->assertSuccessful();
    $this->artisan('bookkeeper:close-day', ['date' => '2026-08-01'])->assertFailed();
    $this->artisan('bookkeeper:close-month', ['date' => '2026-08-01'])->assertSuccessful();
    $this->artisan('bookkeeper:close-year', ['year' => 'FY2026'])->assertSuccessful();

    expect(BookKeeper::balance('3300'))->toEqualMoney(3000);
});
