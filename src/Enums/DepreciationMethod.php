<?php

namespace OtimOtim\BookKeeper\Enums;

enum DepreciationMethod: string
{
    case StraightLine = 'straight_line';
    case DecliningBalance = 'declining_balance';
    case None = 'none';
}
