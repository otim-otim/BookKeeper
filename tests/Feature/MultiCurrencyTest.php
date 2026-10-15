<?php

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use OtimOtim\BookKeeper\Contracts\ExchangeRateProvider;
use OtimOtim\BookKeeper\Enums\AccountSubtype;
use OtimOtim\BookKeeper\Enums\EntryType;
use OtimOtim\BookKeeper\Exceptions\InvalidOperationException;
use OtimOtim\BookKeeper\Facades\BookKeeper;
use OtimOtim\BookKeeper\Models\JournalEntry;
use OtimOtim\BookKeeper\RateProviders\FixedRateProvider;
use OtimOtim\BookKeeper\RateProviders\OpenExchangeRatesApiProvider;
use OtimOtim\BookKeeper\Support\Currency;
use OtimOtim\BookKeeper\Support\Money;

class StaticRateProvider implements ExchangeRateProvider
{
    public function rates(string $base, array $currencies, CarbonInterface $date): array
    {
        return array_intersect_key(['USD' => '3700', 'KES' => '28.5'], array_flip($currencies));
    }
}

beforeEach(function () {
    travelToDate('2026-03-10');
});

// ---------------------------------------------------------------------------
// 1. Base currency per set of books
// ---------------------------------------------------------------------------

test('the base currency is locked in when the first entry posts', function () {
    useChart('standard', 'USD');
    BookKeeper::transfer('@bank', '@sales', 100);

    config()->set('bookkeeper.currency', 'EUR');

    expect(Currency::base())->toBe('USD')
        ->and(BookKeeper::settings()->isBaseCurrencyLocked())->toBeTrue()
        ->and(fn () => BookKeeper::settings()->setBaseCurrency('KES'))->toThrow(InvalidOperationException::class, 'cannot change');
});

test('the base currency can be chosen per set of books before posting', function () {
    BookKeeper::settings()->setBaseCurrency('kes');
    BookKeeper::chart()->import('standard');
    BookKeeper::transfer('@bank', '@sales', '150.25');

    expect(Currency::base())->toBe('KES')
        ->and(BookKeeper::balance('@bank')->currency())->toBe('KES')
        ->and(BookKeeper::balance('@bank')->amount())->toBe('150.25');
});

test('install takes the currency from the option or the chart', function () {
    $this->artisan('bookkeeper:install', ['--chart' => 'sacco-ke'])->expectsOutputToContain('Base currency: KES')->assertSuccessful();

    expect(Currency::base())->toBe('KES');
});

// ---------------------------------------------------------------------------
// 3. Balances in an account's own currency
// ---------------------------------------------------------------------------

test('foreign-currency accounts report balances in their own currency too', function () {
    useChart('sacco-ug', 'UGX');
    BookKeeper::createAccount('1125', 'Bank - USD account', AccountSubtype::Bank, ['currency' => 'USD', 'parent' => '1120']);

    BookKeeper::record([['account' => '1125', 'debit' => '100.00', 'currency' => 'USD', 'exchange_rate' => '3700'], ['account' => '@share_capital', 'credit' => 370000, 'subledger' => BookKeeper::contacts()->customer('A')]], ['date' => '2026-03-01']);
    BookKeeper::record([['account' => '1125', 'debit' => '50.00', 'currency' => 'USD', 'exchange_rate' => '3800'], ['account' => '@share_capital', 'credit' => 190000, 'subledger' => BookKeeper::contacts()->customer('B')]], ['date' => '2026-03-05']);

    $account = BookKeeper::account('1125');
    $gl = BookKeeper::generalLedger('1125');
    $tb = collect(BookKeeper::trialBalance()['accounts'])->firstWhere('code', '1125');

    expect($account->currencyBalance())->toEqualMoney(Money::of('150.00', 'USD'))
        ->and(BookKeeper::balance('1125'))->toEqualMoney(560000)
        ->and($account->currencyBalance('2026-03-02')->amount())->toBe('100.00')
        ->and($gl['currency'])->toBe('USD')
        ->and($gl['currency_closing_balance']->amount())->toBe('150.00')
        ->and($gl['transactions'][1]['currency_balance']->amount())->toBe('150.00')
        ->and($tb['currency_balance']->amount())->toBe('150.00');
});

// ---------------------------------------------------------------------------
// 4. Unrealised exchange revaluation
// ---------------------------------------------------------------------------

test('month end revalues foreign balances at the closing rate and reverses next day', function () {
    useChart('sacco-ug', 'UGX');
    BookKeeper::createAccount('1125', 'Bank - USD account', AccountSubtype::Bank, ['currency' => 'USD', 'parent' => '1120']);
    BookKeeper::exchangeRates()->set('USD', '3700', '2026-03-01');
    BookKeeper::record([['account' => '1125', 'debit' => '100.00', 'currency' => 'USD'], ['account' => '@donations', 'credit' => 370000]], ['date' => '2026-03-02']);

    BookKeeper::exchangeRates()->set('USD', '3800', '2026-03-31');

    $preview = BookKeeper::revaluation()->calculate('2026-03-31');
    expect($preview)->toHaveCount(1)
        ->and($preview[0]['adjustment'])->toEqualMoney(10000);

    BookKeeper::close()->closeMonth('2026-03-31');

    $revaluation = JournalEntry::where('type', EntryType::Adjustment->value)->first();

    expect(BookKeeper::balanceAsOf('1125', '2026-03-31'))->toEqualMoney(380000)
        ->and(BookKeeper::balanceAsOf('@fx_gain_loss', '2026-03-31'))->toEqualMoney(10000)
        ->and(BookKeeper::account('1125')->currencyBalance('2026-03-31')->amount())->toBe('100.00')
        ->and($revaluation->reverse_on->toDateString())->toBe('2026-04-01');

    BookKeeper::ledger()->processScheduledReversals('2026-04-01');

    expect(BookKeeper::balanceAsOf('1125', '2026-04-01'))->toEqualMoney(370000);
});

test('foreign receivables are revalued per customer; non-monetary items are not', function () {
    useChart('standard', 'USD');
    BookKeeper::exchangeRates()->set('EUR', '1.10', '2026-03-01');
    $customer = BookKeeper::contacts()->customer('Euro Buyer');
    BookKeeper::invoice($customer, [['account' => '@sales', 'amount' => '1000.00']], ['currency' => 'EUR', 'issue_date' => '2026-03-01']);
    BookKeeper::record([['account' => '@fixed_assets', 'debit' => '500.00', 'currency' => 'EUR'], ['account' => '@bank', 'credit' => '550.00']], ['date' => '2026-03-01']);

    BookKeeper::exchangeRates()->set('EUR', '1.05', '2026-03-31');
    $entry = BookKeeper::revaluation()->revalue('2026-03-31', ['reverse' => false]);

    expect($entry->lines)->toHaveCount(2)
        ->and(BookKeeper::subledgers()->balance('@accounts_receivable', $customer))->toEqualMoney('1050.00')
        ->and(BookKeeper::balance('@fx_gain_loss'))->toEqualMoney('-50.00')
        ->and(BookKeeper::balance('@fixed_assets'))->toEqualMoney('550.00')
        ->and(BookKeeper::revaluation()->revalue('2026-03-31')->id)->toBe($entry->id);
});

// ---------------------------------------------------------------------------
// 5. Exchange-rate providers and conversion
// ---------------------------------------------------------------------------

test('money converts between any two currencies through the base', function () {
    config()->set('bookkeeper.currency', 'UGX');
    BookKeeper::exchangeRates()->set('USD', '3700', '2026-03-01');
    BookKeeper::exchangeRates()->set('KES', '28.5', '2026-03-01');

    $rates = BookKeeper::exchangeRates();

    expect($rates->convert(Money::of('100.00', 'USD'), 'UGX')->amount())->toBe('370000')
        ->and($rates->convert(Money::of('370000', 'UGX'), 'USD')->amount())->toBe('100.00')
        ->and($rates->convert(Money::of('100.00', 'USD'), 'KES')->amount())->toBe('12982.46')
        ->and($rates->crossRate('USD', 'KES'))->toStartWith('129.824561');
});

test('rates are fetched from the configured provider', function () {
    config()->set('bookkeeper.currency', 'UGX');
    config()->set('bookkeeper.exchange_rates.provider', StaticRateProvider::class);
    config()->set('bookkeeper.exchange_rates.currencies', ['USD', 'KES', 'EUR']);

    $this->artisan('bookkeeper:rates')->expectsOutputToContain('Stored 2 rate(s)')->assertSuccessful();

    expect(BookKeeper::exchangeRates()->rate('USD'))->toStartWith('3700')
        ->and(BookKeeper::exchangeRates()->rate('KES'))->toStartWith('28.5');
});

test('the fixed and open API providers return base units per foreign unit', function () {
    config()->set('bookkeeper.exchange_rates.fixed', ['usd' => '3650']);
    Http::fake(['open.er-api.com/*' => Http::response(['result' => 'success', 'rates' => ['UGX' => 1, 'USD' => 0.00025, 'KES' => 0.035]])]);

    $fixed = (new FixedRateProvider)->rates('UGX', ['USD', 'KES'], now());
    $api = (new OpenExchangeRatesApiProvider)->rates('UGX', ['USD', 'KES', 'ZZZ'], now());

    expect($fixed)->toBe(['USD' => '3650'])
        ->and(array_keys($api))->toBe(['USD', 'KES'])
        ->and($api['USD'])->toStartWith('4000')
        ->and($api['KES'])->toStartWith('28.5714285714');
});

test('rates without a provider fail clearly', function () {
    BookKeeper::exchangeRates()->fetch(null, ['USD']);
})->throws(InvalidOperationException::class, 'No exchange rate provider');

// ---------------------------------------------------------------------------
// 3 (cont). Foreign-currency bank reconciliation
// ---------------------------------------------------------------------------

test('a USD bank account reconciles in USD', function () {
    useChart('standard', 'UGX');
    BookKeeper::createAccount('1125', 'Bank - USD', AccountSubtype::Bank, ['currency' => 'USD', 'parent' => '1100']);
    BookKeeper::exchangeRates()->set('USD', '3700', '2026-03-01');
    BookKeeper::record([['account' => '1125', 'debit' => '200.00', 'currency' => 'USD'], ['account' => '@sales', 'credit' => 740000]], ['date' => '2026-03-03', 'reference' => 'W-1']);

    $statement = BookKeeper::bank()->import('1125', ['start_date' => '2026-03-01', 'end_date' => '2026-03-31', 'closing_balance' => '195.00'], [
        ['2026-03-03', '200.00', 'Wire in', 'W-1'],
        ['2026-03-31', '-5.00', 'Bank fee'],
    ]);

    expect($statement->closing_balance->currency())->toBe('USD')
        ->and(BookKeeper::bank()->autoMatch($statement))->toBe(1);

    BookKeeper::bank()->adjust($statement->lines()->whereNull('journal_line_id')->first(), '@bank_charges');
    BookKeeper::bank()->reconcile($statement->refresh());

    expect(BookKeeper::balance('@bank_charges'))->toEqualMoney(18500)
        ->and(BookKeeper::account('1125')->currencyBalance()->amount())->toBe('195.00');
});

// ---------------------------------------------------------------------------
// 7. Cash rounding on documents
// ---------------------------------------------------------------------------

test('document totals can round to the cash denomination', function () {
    useChart('standard', 'CHF');
    $customer = BookKeeper::contacts()->customer('Zurich Café');

    $invoice = BookKeeper::invoice($customer, [['account' => '@sales', 'amount' => '10.03']], ['cash_rounding' => true]);

    expect($invoice->total)->toEqualMoney('10.05')
        ->and($invoice->rounding)->toEqualMoney('0.02')
        ->and(BookKeeper::balance('@accounts_receivable'))->toEqualMoney('10.05')
        ->and(BookKeeper::balance('@cash_rounding'))->toEqualMoney('-0.02');

    config()->set('bookkeeper.documents.cash_rounding', true);
    $down = BookKeeper::invoice($customer, [['account' => '@sales', 'amount' => '10.02']]);

    expect($down->total)->toEqualMoney('10.00')
        ->and(BookKeeper::trialBalance()['balanced'])->toBeTrue();
});
