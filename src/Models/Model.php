<?php

namespace OtimOtim\BookKeeper\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

/**
 * Base model: every BookKeeper table lives on the configured connection.
 */
abstract class Model extends Eloquent
{
    protected $guarded = ['id'];

    public function getConnectionName(): ?string
    {
        return config('bookkeeper.connection') ?? parent::getConnectionName();
    }
}
