<?php

/*
|--------------------------------------------------------------------------
| IFRS chart of accounts
|--------------------------------------------------------------------------
|
| A country-neutral chart following the IFRS statement of financial
| position and statement of profit or loss, with current / non-current
| classification, leases (IFRS 16), provisions (IAS 37), deferred tax
| (IAS 12) and other comprehensive income. Add country-specific tax and
| payroll accounts as needed.
|
*/

return [
    'name' => 'IFRS (generic)',
    'accounts' => [
        ['code' => '1000', 'name' => 'Assets', 'type' => 'asset', 'children' => [
            ['code' => '1100', 'name' => 'Current assets', 'type' => 'asset', 'children' => [
                ['code' => '1110', 'name' => 'Cash and cash equivalents', 'subtype' => 'cash', 'role' => 'cash_on_hand', 'children' => [
                    ['code' => '1111', 'name' => 'Petty cash', 'subtype' => 'cash', 'role' => 'cash', 'tags' => ['liquid']],
                    ['code' => '1112', 'name' => 'Bank - operating account', 'subtype' => 'bank', 'role' => 'bank', 'tags' => ['liquid']],
                    ['code' => '1113', 'name' => 'Mobile money and wallets', 'subtype' => 'mobile_money', 'role' => 'mobile_money', 'tags' => ['liquid']],
                    ['code' => '1114', 'name' => 'Short-term deposits', 'subtype' => 'short_term_investment', 'tags' => ['liquid']],
                ]],
                ['code' => '1120', 'name' => 'Trade receivables', 'subtype' => 'receivable', 'role' => 'accounts_receivable', 'control' => true],
                ['code' => '1125', 'name' => 'Allowance for expected credit losses', 'subtype' => 'loan_loss_provision', 'role' => 'credit_loss_allowance'],
                ['code' => '1130', 'name' => 'Other receivables', 'subtype' => 'other_current_asset', 'role' => 'other_receivables'],
                ['code' => '1140', 'name' => 'Inventories', 'subtype' => 'inventory', 'role' => 'inventory'],
                ['code' => '1150', 'name' => 'Prepayments', 'subtype' => 'prepayment', 'role' => 'prepayments'],
                ['code' => '1160', 'name' => 'Input tax recoverable', 'subtype' => 'tax_receivable', 'role' => 'vat_input'],
                ['code' => '1165', 'name' => 'Withholding tax recoverable', 'subtype' => 'tax_receivable', 'role' => 'withholding_tax_receivable'],
                ['code' => '1170', 'name' => 'Current tax asset', 'subtype' => 'tax_receivable', 'role' => 'current_tax_asset'],
            ]],
            ['code' => '1500', 'name' => 'Non-current assets', 'type' => 'asset', 'children' => [
                ['code' => '1510', 'name' => 'Property, plant and equipment', 'subtype' => 'fixed_asset', 'children' => [
                    ['code' => '1511', 'name' => 'Land and buildings', 'subtype' => 'fixed_asset'],
                    ['code' => '1512', 'name' => 'Plant and machinery', 'subtype' => 'fixed_asset'],
                    ['code' => '1513', 'name' => 'Furniture, fittings and equipment', 'subtype' => 'fixed_asset', 'role' => 'fixed_assets'],
                    ['code' => '1514', 'name' => 'Motor vehicles', 'subtype' => 'fixed_asset'],
                    ['code' => '1519', 'name' => 'Accumulated depreciation', 'subtype' => 'accumulated_depreciation', 'role' => 'accumulated_depreciation'],
                ]],
                ['code' => '1520', 'name' => 'Right-of-use assets', 'subtype' => 'fixed_asset', 'role' => 'right_of_use_assets'],
                ['code' => '1530', 'name' => 'Intangible assets', 'subtype' => 'intangible_asset', 'role' => 'intangible_assets'],
                ['code' => '1540', 'name' => 'Investment property', 'subtype' => 'investment'],
                ['code' => '1550', 'name' => 'Financial assets at amortised cost', 'subtype' => 'investment'],
                ['code' => '1560', 'name' => 'Deferred tax asset', 'subtype' => 'other_asset', 'role' => 'deferred_tax_asset'],
            ]],
        ]],
        ['code' => '2000', 'name' => 'Liabilities', 'type' => 'liability', 'children' => [
            ['code' => '2100', 'name' => 'Current liabilities', 'type' => 'liability', 'children' => [
                ['code' => '2110', 'name' => 'Trade payables', 'subtype' => 'payable', 'role' => 'accounts_payable', 'control' => true],
                ['code' => '2120', 'name' => 'Accruals', 'subtype' => 'accrued_liability', 'role' => 'accrued_expenses'],
                ['code' => '2130', 'name' => 'Contract liabilities (deferred revenue)', 'subtype' => 'deferred_income', 'role' => 'deferred_income'],
                ['code' => '2140', 'name' => 'Output tax payable', 'subtype' => 'tax_payable', 'role' => 'vat_output'],
                ['code' => '2145', 'name' => 'Withholding tax payable', 'subtype' => 'tax_payable', 'role' => 'withholding_tax'],
                ['code' => '2150', 'name' => 'Payroll liabilities', 'subtype' => 'accrued_liability', 'role' => 'payroll_liabilities'],
                ['code' => '2160', 'name' => 'Current tax payable', 'subtype' => 'tax_payable', 'role' => 'current_tax_payable'],
                ['code' => '2170', 'name' => 'Short-term borrowings', 'subtype' => 'short_term_borrowing'],
                ['code' => '2175', 'name' => 'Lease liabilities - current', 'subtype' => 'short_term_borrowing', 'role' => 'lease_liabilities_current'],
                ['code' => '2180', 'name' => 'Provisions - current', 'subtype' => 'other_current_liability', 'role' => 'provisions'],
                ['code' => '2190', 'name' => 'Dividends payable', 'subtype' => 'dividends_payable', 'role' => 'dividends_payable'],
            ]],
            ['code' => '2500', 'name' => 'Non-current liabilities', 'type' => 'liability', 'children' => [
                ['code' => '2510', 'name' => 'Long-term borrowings', 'subtype' => 'long_term_borrowing', 'role' => 'borrowings'],
                ['code' => '2520', 'name' => 'Lease liabilities - non-current', 'subtype' => 'long_term_borrowing', 'role' => 'lease_liabilities'],
                ['code' => '2530', 'name' => 'Provisions - non-current', 'subtype' => 'other_liability'],
                ['code' => '2540', 'name' => 'Deferred tax liability', 'subtype' => 'other_liability', 'role' => 'deferred_tax_liability'],
            ]],
        ]],
        ['code' => '3000', 'name' => 'Equity', 'type' => 'equity', 'children' => [
            ['code' => '3100', 'name' => 'Share capital', 'subtype' => 'share_capital', 'role' => 'share_capital'],
            ['code' => '3200', 'name' => 'Share premium', 'subtype' => 'other_equity'],
            ['code' => '3300', 'name' => 'Retained earnings', 'subtype' => 'retained_earnings', 'role' => 'retained_earnings'],
            ['code' => '3400', 'name' => 'Other comprehensive income reserve', 'subtype' => 'reserve', 'role' => 'oci_reserve'],
            ['code' => '3500', 'name' => 'Revaluation surplus', 'subtype' => 'reserve'],
            ['code' => '3900', 'name' => 'Opening balance equity', 'subtype' => 'opening_balance_equity', 'role' => 'opening_balance_equity'],
        ]],
        ['code' => '4000', 'name' => 'Income', 'type' => 'revenue', 'children' => [
            ['code' => '4100', 'name' => 'Revenue from contracts with customers', 'subtype' => 'operating_revenue', 'role' => 'sales'],
            ['code' => '4200', 'name' => 'Service revenue', 'subtype' => 'operating_revenue', 'role' => 'service_income'],
            ['code' => '4500', 'name' => 'Finance income', 'subtype' => 'interest_income', 'role' => 'interest_income'],
            ['code' => '4800', 'name' => 'Gain or loss on disposal of assets', 'subtype' => 'other_income', 'role' => 'asset_disposal'],
            ['code' => '4850', 'name' => 'Foreign exchange gain or loss', 'subtype' => 'other_income', 'role' => 'fx_gain_loss'],
            ['code' => '4900', 'name' => 'Other income', 'subtype' => 'other_income', 'role' => 'other_income'],
        ]],
        ['code' => '5000', 'name' => 'Expenses', 'type' => 'expense', 'children' => [
            ['code' => '5100', 'name' => 'Cost of sales', 'subtype' => 'cost_of_sales', 'role' => 'cost_of_sales'],
            ['code' => '5200', 'name' => 'Employee benefits expense', 'subtype' => 'personnel_expense', 'role' => 'salaries'],
            ['code' => '5300', 'name' => 'Depreciation and amortisation', 'subtype' => 'depreciation_expense', 'role' => 'depreciation_expense'],
            ['code' => '5400', 'name' => 'Expected credit losses', 'subtype' => 'provision_expense', 'role' => 'credit_loss_expense'],
            ['code' => '5500', 'name' => 'Other operating expenses', 'subtype' => 'operating_expense', 'role' => 'operating_expenses'],
            ['code' => '5560', 'name' => 'Bank charges', 'subtype' => 'operating_expense', 'role' => 'bank_charges'],
            ['code' => '5600', 'name' => 'Finance costs', 'subtype' => 'interest_expense', 'role' => 'interest_expense'],
            ['code' => '5700', 'name' => 'Cash over and short', 'subtype' => 'operating_expense', 'role' => 'cash_over_short'],
            ['code' => '5800', 'name' => 'Income tax expense', 'subtype' => 'tax_expense', 'role' => 'income_tax'],
            ['code' => '5950', 'name' => 'Cash rounding differences', 'subtype' => 'other_expense', 'role' => 'cash_rounding'],
        ]],
    ],
];
