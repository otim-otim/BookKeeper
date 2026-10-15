<?php

namespace OtimOtim\BookKeeper\Support;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Access to the connection BookKeeper's tables live on.
 */
final class Database
{
    public static function connection(): ConnectionInterface
    {
        return DB::connection(config('bookkeeper.connection'));
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function transaction(callable $callback): mixed
    {
        return self::connection()->transaction($callback);
    }
}
