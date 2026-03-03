<?php

namespace Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Otimdibossman\BookKeeper\BookKeeperServiceProvider;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [BookKeeperServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return [
            'BookKeeper' => \Otimdibossman\BookKeeper\Facades\BookKeeper::class,
        ];
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}
