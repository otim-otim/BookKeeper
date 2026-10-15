<?php

namespace Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use OtimOtim\BookKeeper\BookKeeperServiceProvider;
use OtimOtim\BookKeeper\Facades\BookKeeper;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [BookKeeperServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['BookKeeper' => BookKeeper::class];
    }

    protected function defineEnvironment($app): void
    {
        // Run against MySQL or PostgreSQL when DB_CONNECTION says so (see docker-compose.yml).
        $connection = env('DB_CONNECTION', 'testing');
        $app['config']->set('database.default', $connection === 'sqlite' ? 'testing' : $connection);

        $app['config']->set('bookkeeper.currency', 'USD');
        $app['config']->set('app.timezone', 'UTC');
    }
}
