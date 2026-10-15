<?php

namespace OtimOtim\BookKeeper\Close;

use Illuminate\Support\Carbon;
use OtimOtim\BookKeeper\Enums\CloseType;
use OtimOtim\BookKeeper\Models\FiscalYear;
use OtimOtim\BookKeeper\Models\Period;

final class CloseContext
{
    public function __construct(
        public readonly CloseType $type,
        public readonly Carbon $date,
        public readonly ?Period $period = null,
        public readonly ?FiscalYear $fiscalYear = null,
    ) {}

    /**
     * A stable idempotency key for postings made by a task during this close.
     */
    public function key(string $task): string
    {
        return "close:{$this->type->value}:{$this->date->toDateString()}:{$task}";
    }
}
