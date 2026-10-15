<?php

/*
|--------------------------------------------------------------------------
| Uganda SACCO chart of accounts
|--------------------------------------------------------------------------
|
| A standard chart for savings and credit cooperatives regulated by UMRA.
| Control accounts (member savings, shares, loans, receivables, payables)
| require every posting to name the member or contact (subledger), so
| member balances always tie back to the general ledger.
|
| Tags used for prudential ratios:
|   liquid        - liquid assets for the liquidity ratio
|   core_capital  - core capital for capital adequacy
|   non_earning   - non-earning assets
|   short_term    - savings and short-term liabilities (liquidity denominator)
|
*/

return [
    'name' => 'Uganda SACCO',
    'accounts' => [
        ['code' => '1000', 'name' => 'Assets', 'type' => 'asset', 'children' => [
            ['code' => '1100', 'name' => 'Cash and cash equivalents', 'children' => [
                ['code' => '1110', 'name' => 'Cash on hand', 'role' => 'cash_on_hand', 'subtype' => 'cash', 'children' => [
                    ['code' => '1111', 'name' => 'Main vault', 'subtype' => 'cash', 'role' => 'cash', 'tags' => ['liquid', 'non_earning']],
                ]],
                ['code' => '1120', 'name' => 'Bank accounts', 'subtype' => 'bank', 'children' => [
                    ['code' => '1121', 'name' => 'Bank - current account', 'subtype' => 'bank', 'role' => 'bank', 'tags' => ['liquid', 'non_earning']],
                    ['code' => '1122', 'name' => 'Bank - savings account', 'subtype' => 'bank', 'tags' => ['liquid']],
                ]],
                ['code' => '1130', 'name' => 'Mobile money float', 'subtype' => 'mobile_money', 'children' => [
                    ['code' => '1131', 'name' => 'MTN Mobile Money float', 'subtype' => 'mobile_money', 'role' => 'mtn_momo', 'tags' => ['liquid', 'non_earning']],
                    ['code' => '1132', 'name' => 'Airtel Money float', 'subtype' => 'mobile_money', 'role' => 'airtel_money', 'tags' => ['liquid', 'non_earning']],
                ]],
            ]],
            ['code' => '1200', 'name' => 'Short-term investments', 'subtype' => 'short_term_investment', 'children' => [
                ['code' => '1210', 'name' => 'Treasury bills (up to 3 months)', 'subtype' => 'short_term_investment', 'tags' => ['liquid']],
                ['code' => '1220', 'name' => 'Fixed deposits with banks', 'subtype' => 'short_term_investment'],
            ]],
            ['code' => '1300', 'name' => 'Loans to members', 'subtype' => 'loan_portfolio', 'children' => [
                ['code' => '1310', 'name' => 'Loan portfolio', 'subtype' => 'loan_portfolio', 'role' => 'loan_portfolio', 'control' => true],
                ['code' => '1320', 'name' => 'Interest receivable on loans', 'subtype' => 'interest_receivable', 'role' => 'interest_receivable', 'control' => true],
                ['code' => '1330', 'name' => 'Penalties receivable', 'subtype' => 'interest_receivable', 'role' => 'penalty_receivable', 'control' => true],
                ['code' => '1390', 'name' => 'Loan loss provision', 'subtype' => 'loan_loss_provision', 'role' => 'loan_loss_provision'],
            ]],
            ['code' => '1400', 'name' => 'Other receivables', 'subtype' => 'receivable', 'children' => [
                ['code' => '1410', 'name' => 'Accounts receivable', 'subtype' => 'receivable', 'role' => 'accounts_receivable', 'control' => true, 'tags' => ['non_earning']],
                ['code' => '1420', 'name' => 'Prepayments', 'subtype' => 'prepayment', 'role' => 'prepayments', 'tags' => ['non_earning']],
                ['code' => '1430', 'name' => 'Staff advances and teller shortages', 'subtype' => 'other_current_asset', 'role' => 'staff_advances', 'tags' => ['non_earning']],
                ['code' => '1440', 'name' => 'VAT recoverable', 'subtype' => 'tax_receivable', 'role' => 'vat_input', 'tags' => ['non_earning']],
                ['code' => '1450', 'name' => 'Withholding tax recoverable', 'subtype' => 'tax_receivable', 'role' => 'withholding_tax_receivable', 'tags' => ['non_earning']],
            ]],
            ['code' => '1500', 'name' => 'Long-term investments', 'subtype' => 'investment', 'children' => [
                ['code' => '1510', 'name' => 'Investments in shares', 'subtype' => 'investment'],
                ['code' => '1520', 'name' => 'Long-term government securities', 'subtype' => 'investment'],
            ]],
            ['code' => '1600', 'name' => 'Property and equipment', 'subtype' => 'fixed_asset', 'children' => [
                ['code' => '1610', 'name' => 'Land and buildings', 'subtype' => 'fixed_asset', 'tags' => ['non_earning']],
                ['code' => '1620', 'name' => 'Furniture and fittings', 'subtype' => 'fixed_asset', 'role' => 'fixed_assets', 'tags' => ['non_earning']],
                ['code' => '1630', 'name' => 'Office and computer equipment', 'subtype' => 'fixed_asset', 'tags' => ['non_earning']],
                ['code' => '1640', 'name' => 'Motor vehicles', 'subtype' => 'fixed_asset', 'tags' => ['non_earning']],
                ['code' => '1690', 'name' => 'Accumulated depreciation', 'subtype' => 'accumulated_depreciation', 'role' => 'accumulated_depreciation', 'tags' => ['non_earning']],
            ]],
            ['code' => '1700', 'name' => 'Intangible assets', 'subtype' => 'intangible_asset', 'children' => [
                ['code' => '1710', 'name' => 'Computer software', 'subtype' => 'intangible_asset', 'tags' => ['non_earning']],
            ]],
        ]],

        ['code' => '2000', 'name' => 'Liabilities', 'type' => 'liability', 'children' => [
            ['code' => '2100', 'name' => 'Member deposits', 'subtype' => 'member_savings', 'children' => [
                ['code' => '2110', 'name' => 'Voluntary savings', 'subtype' => 'member_savings', 'role' => 'member_savings', 'control' => true, 'tags' => ['short_term']],
                ['code' => '2120', 'name' => 'Compulsory savings', 'subtype' => 'member_savings', 'role' => 'compulsory_savings', 'control' => true, 'tags' => ['short_term']],
                ['code' => '2130', 'name' => 'Fixed deposits', 'subtype' => 'fixed_deposits', 'role' => 'fixed_deposits', 'control' => true, 'tags' => ['short_term']],
                ['code' => '2140', 'name' => 'Target and group savings', 'subtype' => 'member_savings', 'role' => 'target_savings', 'control' => true, 'tags' => ['short_term']],
            ]],
            ['code' => '2200', 'name' => 'Interest payable', 'subtype' => 'interest_payable', 'children' => [
                ['code' => '2210', 'name' => 'Interest payable on savings', 'subtype' => 'interest_payable', 'role' => 'interest_payable', 'control' => true, 'tags' => ['short_term']],
            ]],
            ['code' => '2300', 'name' => 'Payables and accruals', 'subtype' => 'payable', 'children' => [
                ['code' => '2310', 'name' => 'Accounts payable', 'subtype' => 'payable', 'role' => 'accounts_payable', 'control' => true, 'tags' => ['short_term']],
                ['code' => '2320', 'name' => 'Accrued expenses', 'subtype' => 'accrued_liability', 'role' => 'accrued_expenses', 'tags' => ['short_term']],
                ['code' => '2330', 'name' => 'Dividends payable', 'subtype' => 'dividends_payable', 'role' => 'dividends_payable', 'tags' => ['short_term']],
                ['code' => '2340', 'name' => 'Loan insurance payable', 'subtype' => 'other_current_liability', 'role' => 'loan_insurance_payable', 'tags' => ['short_term']],
                ['code' => '2350', 'name' => 'Suspense - unidentified deposits', 'subtype' => 'other_current_liability', 'role' => 'suspense', 'tags' => ['short_term']],
                ['code' => '2360', 'name' => 'Stabilisation Fund levy payable', 'subtype' => 'other_current_liability', 'role' => 'stabilisation_levy_payable', 'tags' => ['short_term']],
            ]],
            ['code' => '2400', 'name' => 'Taxes and statutory deductions', 'subtype' => 'tax_payable', 'children' => [
                ['code' => '2410', 'name' => 'VAT payable', 'subtype' => 'tax_payable', 'role' => 'vat_output', 'tags' => ['short_term']],
                ['code' => '2420', 'name' => 'Withholding tax payable', 'subtype' => 'tax_payable', 'role' => 'withholding_tax', 'tags' => ['short_term']],
                ['code' => '2430', 'name' => 'PAYE payable', 'subtype' => 'tax_payable', 'role' => 'paye', 'tags' => ['short_term']],
                ['code' => '2440', 'name' => 'NSSF payable', 'subtype' => 'accrued_liability', 'role' => 'nssf', 'tags' => ['short_term']],
            ]],
            ['code' => '2500', 'name' => 'Deferred income', 'subtype' => 'deferred_income', 'children' => [
                ['code' => '2510', 'name' => 'Unearned interest and fees', 'subtype' => 'deferred_income', 'role' => 'deferred_income'],
            ]],
            ['code' => '2600', 'name' => 'Borrowings', 'type' => 'liability', 'children' => [
                ['code' => '2610', 'name' => 'Short-term borrowings', 'subtype' => 'short_term_borrowing', 'tags' => ['short_term']],
                ['code' => '2620', 'name' => 'Long-term borrowings', 'subtype' => 'long_term_borrowing', 'role' => 'borrowings'],
            ]],
        ]],

        ['code' => '3000', 'name' => 'Equity', 'type' => 'equity', 'children' => [
            ['code' => '3100', 'name' => 'Share capital', 'subtype' => 'share_capital', 'role' => 'share_capital', 'control' => true, 'tags' => ['core_capital']],
            ['code' => '3200', 'name' => 'Reserves', 'subtype' => 'reserve', 'children' => [
                ['code' => '3210', 'name' => 'Statutory reserve', 'subtype' => 'reserve', 'role' => 'statutory_reserve', 'tags' => ['core_capital']],
                ['code' => '3220', 'name' => 'Share transfer fund', 'subtype' => 'reserve', 'role' => 'share_transfer_fund', 'tags' => ['core_capital']],
                ['code' => '3230', 'name' => 'Capital grants and donations', 'subtype' => 'reserve', 'role' => 'donations', 'tags' => ['core_capital']],
                ['code' => '3240', 'name' => 'Revaluation reserve', 'subtype' => 'reserve'],
            ]],
            ['code' => '3300', 'name' => 'Retained surplus', 'subtype' => 'retained_earnings', 'role' => 'retained_earnings', 'tags' => ['core_capital']],
            ['code' => '3900', 'name' => 'Opening balance equity', 'subtype' => 'opening_balance_equity', 'role' => 'opening_balance_equity'],
        ]],

        ['code' => '4000', 'name' => 'Income', 'type' => 'revenue', 'children' => [
            ['code' => '4100', 'name' => 'Interest on loans', 'subtype' => 'interest_income', 'role' => 'loan_interest_income'],
            ['code' => '4200', 'name' => 'Fees and commissions', 'subtype' => 'fee_income', 'children' => [
                ['code' => '4210', 'name' => 'Loan processing fees', 'subtype' => 'fee_income', 'role' => 'loan_fee_income'],
                ['code' => '4220', 'name' => 'Entrance and membership fees', 'subtype' => 'fee_income', 'role' => 'entrance_fee_income'],
                ['code' => '4230', 'name' => 'Account and transaction fees', 'subtype' => 'fee_income', 'role' => 'transaction_fee_income'],
                ['code' => '4240', 'name' => 'Penalties on loans', 'subtype' => 'fee_income', 'role' => 'penalty_income'],
            ]],
            ['code' => '4300', 'name' => 'Investment income', 'subtype' => 'interest_income', 'children' => [
                ['code' => '4310', 'name' => 'Interest on bank deposits and T-bills', 'subtype' => 'interest_income', 'role' => 'investment_income'],
            ]],
            ['code' => '4400', 'name' => 'Other income', 'subtype' => 'other_income', 'children' => [
                ['code' => '4410', 'name' => 'Recoveries of loans written off', 'subtype' => 'other_income', 'role' => 'loan_recoveries'],
                ['code' => '4420', 'name' => 'Gain or loss on disposal of assets', 'subtype' => 'other_income', 'role' => 'asset_disposal'],
                ['code' => '4430', 'name' => 'Foreign exchange gain or loss', 'subtype' => 'other_income', 'role' => 'fx_gain_loss'],
                ['code' => '4490', 'name' => 'Sundry income', 'subtype' => 'other_income', 'role' => 'other_income'],
            ]],
        ]],

        ['code' => '5000', 'name' => 'Expenses', 'type' => 'expense', 'children' => [
            ['code' => '5100', 'name' => 'Interest expense', 'subtype' => 'interest_expense', 'children' => [
                ['code' => '5110', 'name' => 'Interest on member savings', 'subtype' => 'interest_expense', 'role' => 'savings_interest_expense'],
                ['code' => '5120', 'name' => 'Interest on fixed deposits', 'subtype' => 'interest_expense', 'role' => 'fixed_deposit_interest_expense'],
                ['code' => '5130', 'name' => 'Interest on borrowings', 'subtype' => 'interest_expense', 'role' => 'borrowing_interest_expense'],
            ]],
            ['code' => '5200', 'name' => 'Loan loss provision expense', 'subtype' => 'provision_expense', 'role' => 'provision_expense'],
            ['code' => '5300', 'name' => 'Personnel costs', 'subtype' => 'personnel_expense', 'children' => [
                ['code' => '5310', 'name' => 'Salaries and wages', 'subtype' => 'personnel_expense', 'role' => 'salaries'],
                ['code' => '5320', 'name' => 'NSSF employer contribution', 'subtype' => 'personnel_expense', 'role' => 'nssf_expense'],
                ['code' => '5330', 'name' => 'Staff welfare and training', 'subtype' => 'personnel_expense'],
            ]],
            ['code' => '5400', 'name' => 'Governance', 'subtype' => 'operating_expense', 'children' => [
                ['code' => '5410', 'name' => 'Board and committee allowances', 'subtype' => 'operating_expense'],
                ['code' => '5420', 'name' => 'AGM and general meetings', 'subtype' => 'operating_expense'],
                ['code' => '5430', 'name' => 'Audit fees', 'subtype' => 'operating_expense'],
                ['code' => '5440', 'name' => 'Regulatory fees and levies', 'subtype' => 'operating_expense'],
            ]],
            ['code' => '5500', 'name' => 'Administrative expenses', 'subtype' => 'operating_expense', 'children' => [
                ['code' => '5510', 'name' => 'Rent and rates', 'subtype' => 'operating_expense'],
                ['code' => '5520', 'name' => 'Utilities', 'subtype' => 'operating_expense'],
                ['code' => '5530', 'name' => 'Stationery and printing', 'subtype' => 'operating_expense'],
                ['code' => '5540', 'name' => 'Communication and SMS', 'subtype' => 'operating_expense'],
                ['code' => '5550', 'name' => 'Transport and travel', 'subtype' => 'operating_expense'],
                ['code' => '5560', 'name' => 'Bank charges', 'subtype' => 'operating_expense', 'role' => 'bank_charges'],
                ['code' => '5570', 'name' => 'Mobile money charges', 'subtype' => 'operating_expense', 'role' => 'mobile_money_charges'],
                ['code' => '5580', 'name' => 'Insurance', 'subtype' => 'operating_expense'],
                ['code' => '5590', 'name' => 'Repairs and maintenance', 'subtype' => 'operating_expense'],
                ['code' => '5595', 'name' => 'Software and IT services', 'subtype' => 'operating_expense'],
            ]],
            ['code' => '5600', 'name' => 'Depreciation and amortisation', 'subtype' => 'depreciation_expense', 'role' => 'depreciation_expense'],
            ['code' => '5700', 'name' => 'Cash shortages and overages', 'subtype' => 'operating_expense', 'role' => 'cash_over_short'],
            ['code' => '5800', 'name' => 'Income tax expense', 'subtype' => 'tax_expense', 'role' => 'income_tax'],
            ['code' => '5900', 'name' => 'Other expenses', 'subtype' => 'other_expense', 'role' => 'other_expense'],
        ]],
    ],
];
