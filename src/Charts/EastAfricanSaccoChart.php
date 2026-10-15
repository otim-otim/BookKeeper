<?php

namespace OtimOtim\BookKeeper\Charts;

use InvalidArgumentException;

/**
 * Chart of accounts for savings and credit cooperatives in Uganda, Kenya
 * and Tanzania.
 *
 * The three countries share one structure; this builder only varies what
 * differs: currency, mobile-money providers, statutory deductions and the
 * regulator's levy. Account names come in English and Swahili.
 *
 * Control accounts (member savings, shares, loans, receivables, payables)
 * require every posting to name the member or contact (subledger).
 *
 * Tags for prudential ratios: liquid, core_capital, non_earning,
 * short_term (savings and short-term liabilities).
 */
final class EastAfricanSaccoChart
{
    private const COUNTRIES = [
        'UG' => [
            'name' => 'Uganda SACCO',
            'currency' => 'UGX',
            'mobile_money' => [
                ['1131', 'MTN Mobile Money float', 'Salio la MTN Mobile Money', 'mtn_momo'],
                ['1132', 'Airtel Money float', 'Salio la Airtel Money', 'airtel_money'],
            ],
            'levy' => ['Stabilisation Fund levy payable', 'Tozo ya Mfuko wa Uimarishaji inayolipwa', 'stabilisation_levy_payable'],
            'compulsory_savings' => ['Compulsory savings', 'Akiba za lazima'],
            'voluntary_savings' => ['Voluntary savings', 'Akiba za hiari'],
            'statutory' => [],
        ],
        'KE' => [
            'name' => 'Kenya SACCO',
            'currency' => 'KES',
            'mobile_money' => [
                ['1131', 'M-Pesa float', 'Salio la M-Pesa', 'mpesa'],
                ['1132', 'Airtel Money float', 'Salio la Airtel Money', 'airtel_money'],
            ],
            'levy' => ['SASRA levy payable', 'Tozo ya SASRA inayolipwa', 'regulator_levy_payable'],
            'compulsory_savings' => ['Non-withdrawable member deposits (BOSA)', 'Amana za wanachama zisizotolewa (BOSA)'],
            'voluntary_savings' => ['FOSA savings accounts', 'Akaunti za akiba za FOSA'],
            'statutory' => [
                ['2450', 'Excise duty payable', 'Ushuru wa bidhaa unaolipwa', 'excise_duty'],
                ['2460', 'SHIF contributions payable', 'Michango ya SHIF inayolipwa', 'shif'],
                ['2470', 'Housing levy payable', 'Tozo ya nyumba inayolipwa', 'housing_levy'],
            ],
        ],
        'TZ' => [
            'name' => 'Tanzania SACCOS',
            'currency' => 'TZS',
            'mobile_money' => [
                ['1131', 'M-Pesa float', 'Salio la M-Pesa', 'mpesa'],
                ['1132', 'Mixx by Yas float', 'Salio la Mixx by Yas', 'mixx_by_yas'],
                ['1133', 'Airtel Money float', 'Salio la Airtel Money', 'airtel_money'],
                ['1134', 'HaloPesa float', 'Salio la HaloPesa', 'halopesa'],
            ],
            'levy' => ['TCDC levy payable', 'Tozo ya TCDC inayolipwa', 'regulator_levy_payable'],
            'compulsory_savings' => ['Compulsory savings', 'Akiba za lazima'],
            'voluntary_savings' => ['Voluntary savings', 'Akiba za hiari'],
            'statutory' => [
                ['2450', 'Skills Development Levy payable', 'Tozo ya Uendelezaji Ujuzi (SDL) inayolipwa', 'sdl'],
                ['2460', 'WCF contributions payable', 'Michango ya WCF inayolipwa', 'wcf'],
            ],
        ],
    ];

    /**
     * @param  string  $country  ISO 3166 alpha-2 code: UG, KE or TZ
     */
    public static function build(string $country): array
    {
        $c = self::COUNTRIES[strtoupper($country)]
            ?? throw new InvalidArgumentException("No SACCO chart for [{$country}]; available: ".implode(', ', array_keys(self::COUNTRIES)).'.');

        $leaf = fn (array $row, array $extra = []) => ['code' => $row[0], 'name' => self::n($row[1], $row[2])] + (isset($row[3]) ? ['role' => $row[3]] : []) + $extra;

        return [
            'name' => $c['name'],
            'currency' => $c['currency'],
            'accounts' => [
                ['code' => '1000', 'name' => self::n('Assets', 'Mali'), 'type' => 'asset', 'children' => [
                    ['code' => '1100', 'name' => self::n('Cash and cash equivalents', 'Fedha taslimu na zinazolingana nazo'), 'children' => [
                        ['code' => '1110', 'name' => self::n('Cash on hand', 'Fedha taslimu mkononi'), 'role' => 'cash_on_hand', 'subtype' => 'cash', 'children' => [
                            ['code' => '1111', 'name' => self::n('Main vault', 'Sefu kuu'), 'subtype' => 'cash', 'role' => 'cash', 'tags' => ['liquid', 'non_earning']],
                        ]],
                        ['code' => '1120', 'name' => self::n('Bank accounts', 'Akaunti za benki'), 'subtype' => 'bank', 'children' => [
                            ['code' => '1121', 'name' => self::n('Bank - current account', 'Benki - akaunti ya hundi'), 'subtype' => 'bank', 'role' => 'bank', 'tags' => ['liquid', 'non_earning']],
                            ['code' => '1122', 'name' => self::n('Bank - savings account', 'Benki - akaunti ya akiba'), 'subtype' => 'bank', 'tags' => ['liquid']],
                        ]],
                        ['code' => '1130', 'name' => self::n('Mobile money float', 'Salio la pesa kwa simu'), 'subtype' => 'mobile_money', 'children' => array_map(
                            fn (array $row) => $leaf($row, ['subtype' => 'mobile_money', 'tags' => ['liquid', 'non_earning']]),
                            $c['mobile_money'],
                        )],
                    ]],
                    ['code' => '1200', 'name' => self::n('Short-term investments', 'Uwekezaji wa muda mfupi'), 'subtype' => 'short_term_investment', 'children' => [
                        ['code' => '1210', 'name' => self::n('Treasury bills (up to 3 months)', 'Hati fungani za Hazina (hadi miezi 3)'), 'subtype' => 'short_term_investment', 'tags' => ['liquid']],
                        ['code' => '1220', 'name' => self::n('Fixed deposits with banks', 'Amana za muda maalum benki'), 'subtype' => 'short_term_investment'],
                    ]],
                    ['code' => '1300', 'name' => self::n('Loans to members', 'Mikopo kwa wanachama'), 'subtype' => 'loan_portfolio', 'children' => [
                        ['code' => '1310', 'name' => self::n('Loan portfolio', 'Jalada la mikopo'), 'subtype' => 'loan_portfolio', 'role' => 'loan_portfolio', 'control' => true],
                        ['code' => '1320', 'name' => self::n('Interest receivable on loans', 'Riba inayodaiwa kwenye mikopo'), 'subtype' => 'interest_receivable', 'role' => 'interest_receivable', 'control' => true],
                        ['code' => '1330', 'name' => self::n('Penalties receivable', 'Faini zinazodaiwa'), 'subtype' => 'interest_receivable', 'role' => 'penalty_receivable', 'control' => true],
                        ['code' => '1390', 'name' => self::n('Loan loss provision', 'Tahadhari ya hasara ya mikopo'), 'subtype' => 'loan_loss_provision', 'role' => 'loan_loss_provision'],
                    ]],
                    ['code' => '1400', 'name' => self::n('Other receivables', 'Madai mengine'), 'subtype' => 'receivable', 'children' => [
                        ['code' => '1410', 'name' => self::n('Accounts receivable', 'Wadaiwa'), 'subtype' => 'receivable', 'role' => 'accounts_receivable', 'control' => true, 'tags' => ['non_earning']],
                        ['code' => '1420', 'name' => self::n('Prepayments', 'Malipo ya awali'), 'subtype' => 'prepayment', 'role' => 'prepayments', 'tags' => ['non_earning']],
                        ['code' => '1430', 'name' => self::n('Staff advances and teller shortages', 'Masurufu ya wafanyakazi na upungufu wa watunza fedha'), 'subtype' => 'other_current_asset', 'role' => 'staff_advances', 'tags' => ['non_earning']],
                        ['code' => '1440', 'name' => self::n('VAT recoverable', 'VAT inayorejeshwa'), 'subtype' => 'tax_receivable', 'role' => 'vat_input', 'tags' => ['non_earning']],
                        ['code' => '1450', 'name' => self::n('Withholding tax recoverable', 'Kodi ya zuio inayorejeshwa'), 'subtype' => 'tax_receivable', 'role' => 'withholding_tax_receivable', 'tags' => ['non_earning']],
                    ]],
                    ['code' => '1500', 'name' => self::n('Long-term investments', 'Uwekezaji wa muda mrefu'), 'subtype' => 'investment', 'children' => [
                        ['code' => '1510', 'name' => self::n('Investments in shares', 'Uwekezaji katika hisa'), 'subtype' => 'investment'],
                        ['code' => '1520', 'name' => self::n('Long-term government securities', 'Dhamana za serikali za muda mrefu'), 'subtype' => 'investment'],
                    ]],
                    ['code' => '1600', 'name' => self::n('Property and equipment', 'Mali za kudumu na vifaa'), 'subtype' => 'fixed_asset', 'children' => [
                        ['code' => '1610', 'name' => self::n('Land and buildings', 'Ardhi na majengo'), 'subtype' => 'fixed_asset', 'tags' => ['non_earning']],
                        ['code' => '1620', 'name' => self::n('Furniture and fittings', 'Samani'), 'subtype' => 'fixed_asset', 'role' => 'fixed_assets', 'tags' => ['non_earning']],
                        ['code' => '1630', 'name' => self::n('Office and computer equipment', 'Vifaa vya ofisi na kompyuta'), 'subtype' => 'fixed_asset', 'tags' => ['non_earning']],
                        ['code' => '1640', 'name' => self::n('Motor vehicles', 'Magari'), 'subtype' => 'fixed_asset', 'tags' => ['non_earning']],
                        ['code' => '1690', 'name' => self::n('Accumulated depreciation', 'Uchakavu uliolimbikizwa'), 'subtype' => 'accumulated_depreciation', 'role' => 'accumulated_depreciation', 'tags' => ['non_earning']],
                    ]],
                    ['code' => '1700', 'name' => self::n('Intangible assets', 'Mali zisizoshikika'), 'subtype' => 'intangible_asset', 'children' => [
                        ['code' => '1710', 'name' => self::n('Computer software', 'Programu za kompyuta'), 'subtype' => 'intangible_asset', 'tags' => ['non_earning']],
                    ]],
                ]],

                ['code' => '2000', 'name' => self::n('Liabilities', 'Madeni'), 'type' => 'liability', 'children' => [
                    ['code' => '2100', 'name' => self::n('Member deposits', 'Amana za wanachama'), 'subtype' => 'member_savings', 'children' => [
                        ['code' => '2110', 'name' => self::n(...$c['voluntary_savings']), 'subtype' => 'member_savings', 'role' => 'member_savings', 'control' => true, 'tags' => ['short_term']],
                        ['code' => '2120', 'name' => self::n(...$c['compulsory_savings']), 'subtype' => 'member_savings', 'role' => 'compulsory_savings', 'control' => true, 'tags' => ['short_term']],
                        ['code' => '2130', 'name' => self::n('Fixed deposits', 'Amana za muda maalum'), 'subtype' => 'fixed_deposits', 'role' => 'fixed_deposits', 'control' => true, 'tags' => ['short_term']],
                        ['code' => '2140', 'name' => self::n('Target and group savings', 'Akiba za malengo na za vikundi'), 'subtype' => 'member_savings', 'role' => 'target_savings', 'control' => true, 'tags' => ['short_term']],
                    ]],
                    ['code' => '2200', 'name' => self::n('Interest payable', 'Riba inayolipwa'), 'subtype' => 'interest_payable', 'children' => [
                        ['code' => '2210', 'name' => self::n('Interest payable on savings', 'Riba inayolipwa kwenye akiba'), 'subtype' => 'interest_payable', 'role' => 'interest_payable', 'control' => true, 'tags' => ['short_term']],
                    ]],
                    ['code' => '2300', 'name' => self::n('Payables and accruals', 'Wadai na gharama zilizolimbikizwa'), 'subtype' => 'payable', 'children' => [
                        ['code' => '2310', 'name' => self::n('Accounts payable', 'Wadai'), 'subtype' => 'payable', 'role' => 'accounts_payable', 'control' => true, 'tags' => ['short_term']],
                        ['code' => '2320', 'name' => self::n('Accrued expenses', 'Gharama zilizolimbikizwa'), 'subtype' => 'accrued_liability', 'role' => 'accrued_expenses', 'tags' => ['short_term']],
                        ['code' => '2330', 'name' => self::n('Dividends payable', 'Gawio linalolipwa'), 'subtype' => 'dividends_payable', 'role' => 'dividends_payable', 'tags' => ['short_term']],
                        ['code' => '2340', 'name' => self::n('Loan insurance payable', 'Bima ya mikopo inayolipwa'), 'subtype' => 'other_current_liability', 'role' => 'loan_insurance_payable', 'tags' => ['short_term']],
                        ['code' => '2350', 'name' => self::n('Suspense - unidentified deposits', 'Akaunti ya muda - amana zisizotambuliwa'), 'subtype' => 'other_current_liability', 'role' => 'suspense', 'tags' => ['short_term']],
                        $leaf(['2360', ...$c['levy']], ['subtype' => 'other_current_liability', 'tags' => ['short_term']]),
                    ]],
                    ['code' => '2400', 'name' => self::n('Taxes and statutory deductions', 'Kodi na makato ya kisheria'), 'subtype' => 'tax_payable', 'children' => [
                        ['code' => '2410', 'name' => self::n('VAT payable', 'VAT inayolipwa'), 'subtype' => 'tax_payable', 'role' => 'vat_output', 'tags' => ['short_term']],
                        ['code' => '2420', 'name' => self::n('Withholding tax payable', 'Kodi ya zuio inayolipwa'), 'subtype' => 'tax_payable', 'role' => 'withholding_tax', 'tags' => ['short_term']],
                        ['code' => '2430', 'name' => self::n('PAYE payable', 'PAYE inayolipwa'), 'subtype' => 'tax_payable', 'role' => 'paye', 'tags' => ['short_term']],
                        ['code' => '2440', 'name' => self::n('NSSF payable', 'Michango ya NSSF inayolipwa'), 'subtype' => 'accrued_liability', 'role' => 'nssf', 'tags' => ['short_term']],
                        ...array_map(fn (array $row) => $leaf($row, ['subtype' => 'tax_payable', 'tags' => ['short_term']]), $c['statutory']),
                    ]],
                    ['code' => '2500', 'name' => self::n('Deferred income', 'Mapato yaliyoahirishwa'), 'subtype' => 'deferred_income', 'children' => [
                        ['code' => '2510', 'name' => self::n('Unearned interest and fees', 'Riba na ada ambazo hazijapatikana'), 'subtype' => 'deferred_income', 'role' => 'deferred_income'],
                    ]],
                    ['code' => '2600', 'name' => self::n('Borrowings', 'Mikopo iliyochukuliwa'), 'type' => 'liability', 'children' => [
                        ['code' => '2610', 'name' => self::n('Short-term borrowings', 'Mikopo ya muda mfupi'), 'subtype' => 'short_term_borrowing', 'tags' => ['short_term']],
                        ['code' => '2620', 'name' => self::n('Long-term borrowings', 'Mikopo ya muda mrefu'), 'subtype' => 'long_term_borrowing', 'role' => 'borrowings'],
                    ]],
                ]],

                ['code' => '3000', 'name' => self::n('Equity', 'Mtaji'), 'type' => 'equity', 'children' => [
                    ['code' => '3100', 'name' => self::n('Share capital', 'Mtaji wa hisa'), 'subtype' => 'share_capital', 'role' => 'share_capital', 'control' => true, 'tags' => ['core_capital']],
                    ['code' => '3200', 'name' => self::n('Reserves', 'Hifadhi'), 'subtype' => 'reserve', 'children' => [
                        ['code' => '3210', 'name' => self::n('Statutory reserve', 'Hifadhi ya kisheria'), 'subtype' => 'reserve', 'role' => 'statutory_reserve', 'tags' => ['core_capital']],
                        ['code' => '3220', 'name' => self::n('Share transfer fund', 'Mfuko wa uhamisho wa hisa'), 'subtype' => 'reserve', 'role' => 'share_transfer_fund', 'tags' => ['core_capital']],
                        ['code' => '3230', 'name' => self::n('Capital grants and donations', 'Ruzuku na misaada ya mtaji'), 'subtype' => 'reserve', 'role' => 'donations', 'tags' => ['core_capital']],
                        ['code' => '3240', 'name' => self::n('Revaluation reserve', 'Hifadhi ya uthamini upya'), 'subtype' => 'reserve'],
                    ]],
                    ['code' => '3300', 'name' => self::n('Retained surplus', 'Ziada iliyobakizwa'), 'subtype' => 'retained_earnings', 'role' => 'retained_earnings', 'tags' => ['core_capital']],
                    ['code' => '3900', 'name' => self::n('Opening balance equity', 'Mtaji wa salio la kuanzia'), 'subtype' => 'opening_balance_equity', 'role' => 'opening_balance_equity'],
                ]],

                ['code' => '4000', 'name' => self::n('Income', 'Mapato'), 'type' => 'revenue', 'children' => [
                    ['code' => '4100', 'name' => self::n('Interest on loans', 'Riba ya mikopo'), 'subtype' => 'interest_income', 'role' => 'loan_interest_income'],
                    ['code' => '4200', 'name' => self::n('Fees and commissions', 'Ada na kamisheni'), 'subtype' => 'fee_income', 'children' => [
                        ['code' => '4210', 'name' => self::n('Loan processing fees', 'Ada za maombi ya mikopo'), 'subtype' => 'fee_income', 'role' => 'loan_fee_income'],
                        ['code' => '4220', 'name' => self::n('Entrance and membership fees', 'Ada za kiingilio na uanachama'), 'subtype' => 'fee_income', 'role' => 'entrance_fee_income'],
                        ['code' => '4230', 'name' => self::n('Account and transaction fees', 'Ada za akaunti na miamala'), 'subtype' => 'fee_income', 'role' => 'transaction_fee_income'],
                        ['code' => '4240', 'name' => self::n('Penalties on loans', 'Faini za mikopo'), 'subtype' => 'fee_income', 'role' => 'penalty_income'],
                    ]],
                    ['code' => '4300', 'name' => self::n('Investment income', 'Mapato ya uwekezaji'), 'subtype' => 'interest_income', 'children' => [
                        ['code' => '4310', 'name' => self::n('Interest on bank deposits and T-bills', 'Riba ya amana za benki na hati fungani'), 'subtype' => 'interest_income', 'role' => 'investment_income'],
                    ]],
                    ['code' => '4400', 'name' => self::n('Other income', 'Mapato mengine'), 'subtype' => 'other_income', 'children' => [
                        ['code' => '4410', 'name' => self::n('Recoveries of loans written off', 'Mikopo iliyofutwa iliyorejeshwa'), 'subtype' => 'other_income', 'role' => 'loan_recoveries'],
                        ['code' => '4420', 'name' => self::n('Gain or loss on disposal of assets', 'Faida au hasara ya kuuza mali'), 'subtype' => 'other_income', 'role' => 'asset_disposal'],
                        ['code' => '4430', 'name' => self::n('Foreign exchange gain or loss', 'Faida au hasara ya ubadilishaji fedha'), 'subtype' => 'other_income', 'role' => 'fx_gain_loss'],
                        ['code' => '4490', 'name' => self::n('Sundry income', 'Mapato mbalimbali'), 'subtype' => 'other_income', 'role' => 'other_income'],
                    ]],
                ]],

                ['code' => '5000', 'name' => self::n('Expenses', 'Matumizi'), 'type' => 'expense', 'children' => [
                    ['code' => '5100', 'name' => self::n('Interest expense', 'Gharama za riba'), 'subtype' => 'interest_expense', 'children' => [
                        ['code' => '5110', 'name' => self::n('Interest on member savings', 'Riba kwa akiba za wanachama'), 'subtype' => 'interest_expense', 'role' => 'savings_interest_expense'],
                        ['code' => '5120', 'name' => self::n('Interest on fixed deposits', 'Riba kwa amana za muda maalum'), 'subtype' => 'interest_expense', 'role' => 'fixed_deposit_interest_expense'],
                        ['code' => '5130', 'name' => self::n('Interest on borrowings', 'Riba ya mikopo iliyochukuliwa'), 'subtype' => 'interest_expense', 'role' => 'borrowing_interest_expense'],
                    ]],
                    ['code' => '5200', 'name' => self::n('Loan loss provision expense', 'Gharama ya tahadhari ya hasara ya mikopo'), 'subtype' => 'provision_expense', 'role' => 'provision_expense'],
                    ['code' => '5300', 'name' => self::n('Personnel costs', 'Gharama za wafanyakazi'), 'subtype' => 'personnel_expense', 'children' => [
                        ['code' => '5310', 'name' => self::n('Salaries and wages', 'Mishahara na ujira'), 'subtype' => 'personnel_expense', 'role' => 'salaries'],
                        ['code' => '5320', 'name' => self::n('NSSF employer contribution', 'Mchango wa mwajiri NSSF'), 'subtype' => 'personnel_expense', 'role' => 'nssf_expense'],
                        ['code' => '5330', 'name' => self::n('Staff welfare and training', 'Ustawi na mafunzo ya wafanyakazi'), 'subtype' => 'personnel_expense'],
                    ]],
                    ['code' => '5400', 'name' => self::n('Governance', 'Utawala'), 'subtype' => 'operating_expense', 'children' => [
                        ['code' => '5410', 'name' => self::n('Board and committee allowances', 'Posho za bodi na kamati'), 'subtype' => 'operating_expense'],
                        ['code' => '5420', 'name' => self::n('AGM and general meetings', 'Mkutano mkuu na mikutano mingine'), 'subtype' => 'operating_expense'],
                        ['code' => '5430', 'name' => self::n('Audit fees', 'Ada za ukaguzi'), 'subtype' => 'operating_expense'],
                        ['code' => '5440', 'name' => self::n('Regulatory fees and levies', 'Ada na tozo za mamlaka ya udhibiti'), 'subtype' => 'operating_expense'],
                    ]],
                    ['code' => '5500', 'name' => self::n('Administrative expenses', 'Gharama za utawala'), 'subtype' => 'operating_expense', 'children' => [
                        ['code' => '5510', 'name' => self::n('Rent and rates', 'Kodi ya pango na ushuru'), 'subtype' => 'operating_expense'],
                        ['code' => '5520', 'name' => self::n('Utilities', 'Huduma za maji na umeme'), 'subtype' => 'operating_expense'],
                        ['code' => '5530', 'name' => self::n('Stationery and printing', 'Vifaa vya ofisi na uchapishaji'), 'subtype' => 'operating_expense'],
                        ['code' => '5540', 'name' => self::n('Communication and SMS', 'Mawasiliano na SMS'), 'subtype' => 'operating_expense'],
                        ['code' => '5550', 'name' => self::n('Transport and travel', 'Usafiri na safari'), 'subtype' => 'operating_expense'],
                        ['code' => '5560', 'name' => self::n('Bank charges', 'Gharama za benki'), 'subtype' => 'operating_expense', 'role' => 'bank_charges'],
                        ['code' => '5570', 'name' => self::n('Mobile money charges', 'Gharama za pesa kwa simu'), 'subtype' => 'operating_expense', 'role' => 'mobile_money_charges'],
                        ['code' => '5580', 'name' => self::n('Insurance', 'Bima'), 'subtype' => 'operating_expense'],
                        ['code' => '5590', 'name' => self::n('Repairs and maintenance', 'Matengenezo'), 'subtype' => 'operating_expense'],
                        ['code' => '5595', 'name' => self::n('Software and IT services', 'Programu na huduma za TEHAMA'), 'subtype' => 'operating_expense'],
                    ]],
                    ['code' => '5600', 'name' => self::n('Depreciation and amortisation', 'Uchakavu'), 'subtype' => 'depreciation_expense', 'role' => 'depreciation_expense'],
                    ['code' => '5700', 'name' => self::n('Cash shortages and overages', 'Upungufu na ziada ya fedha taslimu'), 'subtype' => 'operating_expense', 'role' => 'cash_over_short'],
                    ['code' => '5800', 'name' => self::n('Income tax expense', 'Kodi ya mapato'), 'subtype' => 'tax_expense', 'role' => 'income_tax'],
                    ['code' => '5900', 'name' => self::n('Other expenses', 'Matumizi mengine'), 'subtype' => 'other_expense', 'role' => 'other_expense'],
                    ['code' => '5950', 'name' => self::n('Cash rounding differences', 'Tofauti za ukadiriaji wa fedha taslimu'), 'subtype' => 'other_expense', 'role' => 'cash_rounding'],
                ]],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function countries(): array
    {
        return array_keys(self::COUNTRIES);
    }

    /**
     * @return array{en: string, sw: string}
     */
    private static function n(string $english, string $swahili): array
    {
        return ['en' => $english, 'sw' => $swahili];
    }
}
