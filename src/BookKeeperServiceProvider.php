<?php

namespace Otimdibossman\BookKeeper;

use Illuminate\Support\ServiceProvider;
use Otimdibossman\BookKeeper\BookKeeperManager;
use Otimdibossman\BookKeeper\Services\AccountService;
use Otimdibossman\BookKeeper\Services\LedgerService;
use Otimdibossman\BookKeeper\Services\StatementService;

class BookKeeperServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind the services
        $this->app->singleton(AccountService::class);

        $this->app->singleton(LedgerService::class, function ($app) {
            return new LedgerService($app->make(AccountService::class));
        });

        $this->app->singleton(StatementService::class, function ($app) {
            return new StatementService($app->make(AccountService::class));
        });

        // Bind the main BookKeeperManager class (registered as the facade accessor)
        $this->app->singleton(BookKeeperManager::class, function ($app) {
            return new BookKeeperManager(
                $app->make(AccountService::class),
                $app->make(LedgerService::class),
                $app->make(StatementService::class),
            );
        });
    }

    public function boot(): void
    {
        // Publish migrations so the host application can run them
        $this->publishes([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ], 'bookkeeper-migrations');

        // Optionally load migrations directly without publishing
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}
