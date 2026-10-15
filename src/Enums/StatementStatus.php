<?php

namespace OtimOtim\BookKeeper\Enums;

enum StatementStatus: string
{
    case Draft = 'draft';
    case Reconciled = 'reconciled';
}
