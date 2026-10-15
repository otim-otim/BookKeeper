<?php

namespace OtimOtim\BookKeeper\Enums;

/**
 * Finer classification of accounts, used for statement grouping, cash flow
 * classification and prudential ratios (liquidity, core capital, ...).
 */
enum AccountSubtype: string
{
    // Assets
    case Cash = 'cash';
    case Bank = 'bank';
    case MobileMoney = 'mobile_money';
    case ShortTermInvestment = 'short_term_investment';
    case Receivable = 'receivable';
    case LoanPortfolio = 'loan_portfolio';
    case InterestReceivable = 'interest_receivable';
    case LoanLossProvision = 'loan_loss_provision';
    case Inventory = 'inventory';
    case Prepayment = 'prepayment';
    case TaxReceivable = 'tax_receivable';
    case OtherCurrentAsset = 'other_current_asset';
    case Investment = 'investment';
    case FixedAsset = 'fixed_asset';
    case AccumulatedDepreciation = 'accumulated_depreciation';
    case IntangibleAsset = 'intangible_asset';
    case OtherAsset = 'other_asset';

    // Liabilities
    case Payable = 'payable';
    case MemberSavings = 'member_savings';
    case FixedDeposits = 'fixed_deposits';
    case InterestPayable = 'interest_payable';
    case TaxPayable = 'tax_payable';
    case AccruedLiability = 'accrued_liability';
    case DeferredIncome = 'deferred_income';
    case DividendsPayable = 'dividends_payable';
    case ShortTermBorrowing = 'short_term_borrowing';
    case LongTermBorrowing = 'long_term_borrowing';
    case OtherCurrentLiability = 'other_current_liability';
    case OtherLiability = 'other_liability';

    // Equity
    case ShareCapital = 'share_capital';
    case Reserve = 'reserve';
    case RetainedEarnings = 'retained_earnings';
    case OpeningBalanceEquity = 'opening_balance_equity';
    case Drawings = 'drawings';
    case OtherEquity = 'other_equity';

    // Revenue
    case OperatingRevenue = 'operating_revenue';
    case InterestIncome = 'interest_income';
    case FeeIncome = 'fee_income';
    case OtherIncome = 'other_income';

    // Expenses
    case CostOfSales = 'cost_of_sales';
    case InterestExpense = 'interest_expense';
    case OperatingExpense = 'operating_expense';
    case PersonnelExpense = 'personnel_expense';
    case ProvisionExpense = 'provision_expense';
    case DepreciationExpense = 'depreciation_expense';
    case TaxExpense = 'tax_expense';
    case OtherExpense = 'other_expense';

    public function type(): AccountType
    {
        return match ($this) {
            self::Cash, self::Bank, self::MobileMoney, self::ShortTermInvestment, self::Receivable,
            self::LoanPortfolio, self::InterestReceivable, self::LoanLossProvision, self::Inventory,
            self::Prepayment, self::TaxReceivable, self::OtherCurrentAsset, self::Investment,
            self::FixedAsset, self::AccumulatedDepreciation, self::IntangibleAsset, self::OtherAsset => AccountType::Asset,

            self::Payable, self::MemberSavings, self::FixedDeposits, self::InterestPayable, self::TaxPayable,
            self::AccruedLiability, self::DeferredIncome, self::DividendsPayable, self::ShortTermBorrowing,
            self::LongTermBorrowing, self::OtherCurrentLiability, self::OtherLiability => AccountType::Liability,

            self::ShareCapital, self::Reserve, self::RetainedEarnings, self::OpeningBalanceEquity,
            self::Drawings, self::OtherEquity => AccountType::Equity,

            self::OperatingRevenue, self::InterestIncome, self::FeeIncome, self::OtherIncome => AccountType::Revenue,

            self::CostOfSales, self::InterestExpense, self::OperatingExpense, self::PersonnelExpense,
            self::ProvisionExpense, self::DepreciationExpense, self::TaxExpense, self::OtherExpense => AccountType::Expense,
        };
    }

    /**
     * Cash and cash equivalents.
     */
    public function isCashEquivalent(): bool
    {
        return in_array($this, [self::Cash, self::Bank, self::MobileMoney], true);
    }

    /**
     * Liquid assets for liquidity ratios (cash equivalents plus short-term investments).
     */
    public function isLiquid(): bool
    {
        return $this->isCashEquivalent() || $this === self::ShortTermInvestment;
    }

    /**
     * Contra accounts carry a balance opposite to their type's normal balance.
     */
    public function isContra(): bool
    {
        return in_array($this, [self::LoanLossProvision, self::AccumulatedDepreciation, self::Drawings], true);
    }

    /**
     * Monetary items (cash, receivables, payables, borrowings) are restated
     * at the closing rate; non-monetary items (fixed assets, inventory,
     * prepayments, deferred income) stay at their historical rate (IAS 21).
     */
    public function isMonetary(): bool
    {
        return ! in_array($this, [
            self::Inventory, self::Prepayment, self::Investment, self::FixedAsset,
            self::AccumulatedDepreciation, self::IntangibleAsset, self::DeferredIncome,
        ], true) && $this->type()->isBalanceSheet() && $this->type() !== AccountType::Equity;
    }

    public function isCurrent(): bool
    {
        return in_array($this, [
            self::Cash, self::Bank, self::MobileMoney, self::ShortTermInvestment, self::Receivable,
            self::InterestReceivable, self::Inventory, self::Prepayment, self::TaxReceivable, self::OtherCurrentAsset,
            self::Payable, self::MemberSavings, self::InterestPayable, self::TaxPayable, self::AccruedLiability,
            self::DeferredIncome, self::DividendsPayable, self::ShortTermBorrowing, self::OtherCurrentLiability,
        ], true);
    }

    public function cashFlowCategory(): CashFlowCategory
    {
        return match ($this) {
            self::Cash, self::Bank, self::MobileMoney => CashFlowCategory::Cash,
            // Accumulated depreciation stays operating: depreciation is a non-cash add-back.
            self::Investment, self::FixedAsset, self::IntangibleAsset => CashFlowCategory::Investing,
            self::ShortTermBorrowing, self::LongTermBorrowing, self::ShareCapital, self::DividendsPayable,
            self::Drawings => CashFlowCategory::Financing,
            default => CashFlowCategory::Operating,
        };
    }

    public function label(): string
    {
        return ucwords(str_replace('_', ' ', $this->value));
    }
}
