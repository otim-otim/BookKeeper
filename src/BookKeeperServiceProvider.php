<?php

namespace OtimOtim\BookKeeper;

use Illuminate\Support\ServiceProvider;
use OtimOtim\BookKeeper\Console\CloseDayCommand;
use OtimOtim\BookKeeper\Console\CloseMonthCommand;
use OtimOtim\BookKeeper\Console\CloseYearCommand;
use OtimOtim\BookKeeper\Console\DepreciateCommand;
use OtimOtim\BookKeeper\Console\InstallCommand;
use OtimOtim\BookKeeper\Console\ProcessReversalsCommand;
use OtimOtim\BookKeeper\Console\SnapshotCommand;

class BookKeeperServiceProvider extends ServiceProvider
{
    /**
     * Stateless services, shared for the life of the application.
     */
    public const SERVICES = [
        Services\AccountService::class,
        Services\AgingService::class,
        Services\AppropriationService::class,
        Services\BalanceService::class,
        Services\BankReconciliationService::class,
        Services\BudgetService::class,
        Services\ChartService::class,
        Services\CloseService::class,
        Services\ContactService::class,
        Services\DividendService::class,
        Services\DocumentService::class,
        Services\ExchangeRateService::class,
        Services\FixedAssetService::class,
        Services\LedgerService::class,
        Services\PaymentService::class,
        Services\PeriodService::class,
        Services\SequenceService::class,
        Services\StatementService::class,
        Services\SubledgerService::class,
        Services\TaxService::class,
        Services\TemplateService::class,
        Services\TillService::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/bookkeeper.php', 'bookkeeper');

        foreach (self::SERVICES as $service) {
            $this->app->singleton($service);
        }

        $this->app->singleton(BookKeeperManager::class);
        $this->app->alias(BookKeeperManager::class, 'bookkeeper');
    }

    public function boot(): void
    {
        if (config('bookkeeper.run_migrations', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/bookkeeper.php' => config_path('bookkeeper.php'),
            ], 'bookkeeper-config');

            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'bookkeeper-migrations');

            // Multi-tenant apps (e.g. stancl/tenancy): give each tenant its own books.
            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => database_path('migrations/tenant'),
            ], 'bookkeeper-tenant-migrations');

            $this->publishes([
                __DIR__.'/../resources/charts' => resource_path('bookkeeper/charts'),
            ], 'bookkeeper-charts');

            $this->commands([
                InstallCommand::class,
                CloseDayCommand::class,
                CloseMonthCommand::class,
                CloseYearCommand::class,
                DepreciateCommand::class,
                SnapshotCommand::class,
                ProcessReversalsCommand::class,
            ]);
        }
    }
}
