<?php

namespace OtimOtim\BookKeeper\Exceptions;

class TemplateNotFoundException extends BookKeeperException
{
    public function __construct(string $name)
    {
        parent::__construct("Posting template [{$name}] is not defined.");
    }
}
