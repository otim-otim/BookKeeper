<?php

namespace OtimOtim\BookKeeper\Enums;

enum DocumentType: string
{
    /** Sales invoice: increases what a customer owes. */
    case Invoice = 'invoice';

    /** Sales credit note: reduces what a customer owes. */
    case CreditNote = 'credit_note';

    /** Supplier bill: increases what we owe a vendor. */
    case Bill = 'bill';

    /** Supplier debit note (vendor credit): reduces what we owe a vendor. */
    case DebitNote = 'debit_note';

    public function isSales(): bool
    {
        return $this === self::Invoice || $this === self::CreditNote;
    }

    public function isPurchase(): bool
    {
        return ! $this->isSales();
    }

    /**
     * Credit-type documents reduce the balance on the contact's account.
     */
    public function isCredit(): bool
    {
        return $this === self::CreditNote || $this === self::DebitNote;
    }

    /**
     * The document type a credit-type document is applied against.
     */
    public function appliesTo(): ?self
    {
        return match ($this) {
            self::CreditNote => self::Invoice,
            self::DebitNote => self::Bill,
            default => null,
        };
    }

    /**
     * Role of the control account the document posts to.
     */
    public function controlRole(): string
    {
        return $this->isSales() ? 'accounts_receivable' : 'accounts_payable';
    }

    public function taxType(): TaxType
    {
        return $this->isSales() ? TaxType::Sales : TaxType::Purchase;
    }
}
