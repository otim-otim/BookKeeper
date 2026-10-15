<?php

namespace OtimOtim\BookKeeper\Exceptions;

class AccountNotFoundException extends BookKeeperException
{
    public function __construct(int|string $identifier)
    {
        parent::__construct("Account not found: [{$identifier}]");
    }

    public static function forRole(string $role): self
    {
        $e = new self("role:{$role}");
        $e->message = "No account has the role [{$role}]. Assign it with BookKeeper::accounts()->assignRole() or the bookkeeper.roles config.";

        return $e;
    }
}
