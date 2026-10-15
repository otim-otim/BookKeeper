<?php

namespace OtimOtim\BookKeeper\Enums;

enum PaymentDirection: string
{
    /** Money received from a customer. */
    case Received = 'received';

    /** Money paid to a vendor. */
    case Made = 'made';

    public function controlRole(): string
    {
        return $this === self::Received ? 'accounts_receivable' : 'accounts_payable';
    }

    public function settles(): DocumentType
    {
        return $this === self::Received ? DocumentType::Invoice : DocumentType::Bill;
    }
}
