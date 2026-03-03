<?php

namespace Otimdibossman\BookKeeper\Exceptions;

use RuntimeException;

class AccountNotFoundException extends RuntimeException
{
    public function __construct(int|string $identifier)
    {
        parent::__construct("Account not found: [{$identifier}]");
    }
}
