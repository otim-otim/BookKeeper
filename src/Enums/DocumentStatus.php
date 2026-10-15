<?php

namespace OtimOtim\BookKeeper\Enums;

enum DocumentStatus: string
{
    case Draft = 'draft';
    case Open = 'open';
    case Partial = 'partial';
    case Paid = 'paid';
    case Void = 'void';

    public function isOutstanding(): bool
    {
        return $this === self::Open || $this === self::Partial;
    }
}
