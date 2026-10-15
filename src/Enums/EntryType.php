<?php

namespace OtimOtim\BookKeeper\Enums;

enum EntryType: string
{
    case Standard = 'standard';
    case Opening = 'opening';
    case Adjustment = 'adjustment';
    case Reversal = 'reversal';
    case Closing = 'closing';
    case Appropriation = 'appropriation';
    case System = 'system';
}
