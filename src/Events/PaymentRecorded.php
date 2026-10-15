<?php

namespace OtimOtim\BookKeeper\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use OtimOtim\BookKeeper\Models\Payment;

class PaymentRecorded implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly Payment $payment,
    ) {}
}
