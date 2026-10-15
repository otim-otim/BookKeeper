<?php

namespace OtimOtim\BookKeeper\Enums;

enum CashFlowCategory: string
{
    case Cash = 'cash';
    case Operating = 'operating';
    case Investing = 'investing';
    case Financing = 'financing';
}
