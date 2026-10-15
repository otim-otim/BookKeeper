<?php

/*
|--------------------------------------------------------------------------
| Standard chart of accounts
|--------------------------------------------------------------------------
|
| A general-purpose chart for trading and service businesses.
|
*/

return [
    'name' => 'Standard',
    'accounts' => [
        ['code' => '1000', 'name' => 'Assets', 'type' => 'asset', 'children' => [
            ['code' => '1100', 'name' => 'Cash and cash equivalents', 'subtype' => 'cash', 'children' => [
                ['code' => '1110', 'name' => 'Cash on hand', 'subtype' => 'cash', 'role' => 'cash'],
                ['code' => '1120', 'name' => 'Bank', 'subtype' => 'bank', 'role' => 'bank'],
                ['code' => '1130', 'name' => 'Mobile money', 'subtype' => 'mobile_money', 'role' => 'mobile_money'],
            ]],
            ['code' => '1200', 'name' => 'Accounts receivable', 'subtype' => 'receivable', 'role' => 'accounts_receivable', 'control' => true],
            ['code' => '1300', 'name' => 'Inventory', 'subtype' => 'inventory', 'role' => 'inventory'],
            ['code' => '1400', 'name' => 'Prepayments', 'subtype' => 'prepayment', 'role' => 'prepayments'],
            ['code' => '1410', 'name' => 'Input tax recoverable', 'subtype' => 'tax_receivable', 'role' => 'vat_input'],
            ['code' => '1420', 'name' => 'Withholding tax recoverable', 'subtype' => 'tax_receivable', 'role' => 'withholding_tax_receivable'],
            ['code' => '1500', 'name' => 'Property, plant and equipment', 'subtype' => 'fixed_asset', 'children' => [
                ['code' => '1510', 'name' => 'Furniture and equipment', 'subtype' => 'fixed_asset', 'role' => 'fixed_assets'],
                ['code' => '1520', 'name' => 'Motor vehicles', 'subtype' => 'fixed_asset'],
                ['code' => '1590', 'name' => 'Accumulated depreciation', 'subtype' => 'accumulated_depreciation', 'role' => 'accumulated_depreciation'],
            ]],
        ]],
        ['code' => '2000', 'name' => 'Liabilities', 'type' => 'liability', 'children' => [
            ['code' => '2100', 'name' => 'Accounts payable', 'subtype' => 'payable', 'role' => 'accounts_payable', 'control' => true],
            ['code' => '2200', 'name' => 'Accrued expenses', 'subtype' => 'accrued_liability', 'role' => 'accrued_expenses'],
            ['code' => '2300', 'name' => 'Output tax payable', 'subtype' => 'tax_payable', 'role' => 'vat_output'],
            ['code' => '2310', 'name' => 'Withholding tax payable', 'subtype' => 'tax_payable', 'role' => 'withholding_tax'],
            ['code' => '2320', 'name' => 'Payroll liabilities', 'subtype' => 'accrued_liability', 'role' => 'payroll_liabilities'],
            ['code' => '2400', 'name' => 'Customer deposits', 'subtype' => 'deferred_income', 'role' => 'deferred_income'],
            ['code' => '2500', 'name' => 'Dividends payable', 'subtype' => 'dividends_payable', 'role' => 'dividends_payable'],
            ['code' => '2600', 'name' => 'Loans payable', 'subtype' => 'long_term_borrowing', 'role' => 'borrowings'],
        ]],
        ['code' => '3000', 'name' => 'Equity', 'type' => 'equity', 'children' => [
            ['code' => '3100', 'name' => 'Owner capital', 'subtype' => 'share_capital', 'role' => 'share_capital'],
            ['code' => '3200', 'name' => 'Drawings', 'subtype' => 'drawings', 'role' => 'drawings'],
            ['code' => '3300', 'name' => 'Retained earnings', 'subtype' => 'retained_earnings', 'role' => 'retained_earnings'],
            ['code' => '3900', 'name' => 'Opening balance equity', 'subtype' => 'opening_balance_equity', 'role' => 'opening_balance_equity'],
        ]],
        ['code' => '4000', 'name' => 'Revenue', 'type' => 'revenue', 'children' => [
            ['code' => '4100', 'name' => 'Sales', 'subtype' => 'operating_revenue', 'role' => 'sales'],
            ['code' => '4200', 'name' => 'Service income', 'subtype' => 'operating_revenue', 'role' => 'service_income'],
            ['code' => '4300', 'name' => 'Interest income', 'subtype' => 'interest_income', 'role' => 'interest_income'],
            ['code' => '4800', 'name' => 'Gain or loss on disposal of assets', 'subtype' => 'other_income', 'role' => 'asset_disposal'],
            ['code' => '4850', 'name' => 'Foreign exchange gain or loss', 'subtype' => 'other_income', 'role' => 'fx_gain_loss'],
            ['code' => '4900', 'name' => 'Other income', 'subtype' => 'other_income', 'role' => 'other_income'],
        ]],
        ['code' => '5000', 'name' => 'Expenses', 'type' => 'expense', 'children' => [
            ['code' => '5100', 'name' => 'Cost of goods sold', 'subtype' => 'cost_of_sales', 'role' => 'cost_of_sales'],
            ['code' => '5200', 'name' => 'Salaries and wages', 'subtype' => 'personnel_expense', 'role' => 'salaries'],
            ['code' => '5300', 'name' => 'Rent', 'subtype' => 'operating_expense', 'role' => 'rent'],
            ['code' => '5400', 'name' => 'Utilities', 'subtype' => 'operating_expense', 'role' => 'utilities'],
            ['code' => '5500', 'name' => 'Office expenses', 'subtype' => 'operating_expense', 'role' => 'office_expenses'],
            ['code' => '5600', 'name' => 'Bank charges', 'subtype' => 'operating_expense', 'role' => 'bank_charges'],
            ['code' => '5700', 'name' => 'Depreciation', 'subtype' => 'depreciation_expense', 'role' => 'depreciation_expense'],
            ['code' => '5750', 'name' => 'Cash over and short', 'subtype' => 'operating_expense', 'role' => 'cash_over_short'],
            ['code' => '5800', 'name' => 'Interest expense', 'subtype' => 'interest_expense', 'role' => 'interest_expense'],
            ['code' => '5900', 'name' => 'Income tax', 'subtype' => 'tax_expense', 'role' => 'income_tax'],
        ]],
    ],
];
