<?php

use OtimOtim\BookKeeper\Charts\EastAfricanSaccoChart;
use OtimOtim\BookKeeper\Facades\BookKeeper;
use OtimOtim\BookKeeper\Models\Account;

test('every bundled chart imports and carries the roles the package relies on', function (string $chart) {
    BookKeeper::chart()->import($chart);

    foreach (['retained_earnings', 'opening_balance_equity', 'accounts_receivable', 'accounts_payable', 'fx_gain_loss', 'cash_over_short', 'cash_rounding', 'accumulated_depreciation', 'depreciation_expense', 'asset_disposal', 'dividends_payable', 'withholding_tax'] as $role) {
        expect(BookKeeper::accounts()->hasRole($role))->toBeTrue("{$chart} lacks role {$role}");
    }

    expect(BookKeeper::chart()->import($chart))->toBe(0);
})->with(['standard', 'ifrs', 'sacco-ug', 'sacco-ke', 'sacco-tz']);

test('the East African SACCO charts differ only where the countries do', function () {
    $roles = function (array $chart) {
        $out = [];
        $walk = function ($nodes) use (&$walk, &$out) {
            foreach ($nodes as $n) {
                isset($n['role']) && $out[] = $n['role'];
                $walk($n['children'] ?? []);
            }
        };
        $walk($chart['accounts']);

        return $out;
    };

    $ke = EastAfricanSaccoChart::build('KE');
    $tz = EastAfricanSaccoChart::build('TZ');

    expect($ke['currency'])->toBe('KES')
        ->and($tz['currency'])->toBe('TZS')
        ->and($roles($ke))->toContain('mpesa', 'excise_duty', 'shif', 'housing_levy', 'regulator_levy_payable')
        ->and($roles($tz))->toContain('mpesa', 'mixx_by_yas', 'halopesa', 'sdl', 'wcf')
        ->and($roles($ke))->not->toContain('mtn_momo');
});

test('account names follow the chosen locale and keep their translations', function () {
    BookKeeper::chart()->import('sacco-tz', 'sw');

    $vault = Account::where('code', '1111')->first();

    expect($vault->name)->toBe('Sefu kuu')
        ->and($vault->localizedName('en'))->toBe('Main vault')
        ->and($vault->localizedName('sw_TZ'))->toBe('Sefu kuu');
});

test('install accepts a locale', function () {
    $this->artisan('bookkeeper:install', ['--chart' => 'sacco-ke', '--locale' => 'sw'])->assertSuccessful();

    expect(Account::where('code', '2110')->value('name'))->toBe('Akaunti za akiba za FOSA');
});
