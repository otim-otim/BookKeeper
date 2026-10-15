<?php

namespace OtimOtim\BookKeeper\Enums;

enum TaxType: string
{
    /** Output tax charged on sales (e.g. VAT). */
    case Sales = 'sales';

    /** Input tax paid on purchases. */
    case Purchase = 'purchase';

    /** Tax withheld from a payment (e.g. withholding tax on services, interest or dividends). */
    case Withholding = 'withholding';
}
