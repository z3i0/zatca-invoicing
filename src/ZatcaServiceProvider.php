<?php

declare(strict_types=1);

namespace SaudiZATCA;

use Illuminate\Support\ServiceProvider;
use SaudiZATCA\Commands\{
    GenerateCSRCommand,
    ComplianceCSIDCommand,
    ProductionCSIDCommand,
    ReportInvoiceCommand,
    ClearInvoiceCommand,
    ValidateInvoiceCommand,
    ZatcaStatusCommand
};
use SaudiZATCA\Services\{
    CertificateService,
    ZatcaAPIService,
    InvoiceService,
    QRCodeService,
    XMLGeneratorService,
    StorageService
};

class ZatcaServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Merge configuration
        $this->mergeConfigFrom(
            __DIR__ . '/../config/zatca.php',
            'zatca'
        );

        // Register services as singletons
        $this->app->singleton(StorageService::class, function ($app) {
            return new StorageService(
                storage_path(config('zatca.certificate.storage_path', 'zatca/certificates'))
            );
        });

        $this->app->singleton(CertificateService::class, function ($app) {
            return new CertificateService(
                $app->make(StorageService::class),
                config('zatca.certificate', []),
                config('zatca.security', [])
            );
        });

        $this->app->singleton(ZatcaAPIService::class, function ($app) {
            return new ZatcaAPIService(
                config('zatca.environment', 'sandbox'),
                config('zatca.api', []),
                config('zatca.logging', [])
            );
        });

        $this->app->singleton(XMLGeneratorService::class, function ($app) {
            return new XMLGeneratorService(
                config('zatca.invoice', [])
            );
        });

        $this->app->singleton(QRCodeService::class, function ($app) {
            return new QRCodeService(
                config('zatca.invoice', [])
            );
        });

        $this->app->singleton(InvoiceService::class, function ($app) {
            return new InvoiceService(
                $app->make(XMLGeneratorService::class),
                $app->make(QRCodeService::class),
                $app->make(CertificateService::class),
                $app->make(ZatcaAPIService::class),
                $app->make(StorageService::class),
                config('zatca.invoice', []),
                config('zatca.logging', [])
            );
        });

        // Register facade accessor
        $this->app->bind('zatca', function ($app) {
            return new ZatcaManager($app);
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            // Publish configuration
            $this->publishes([
                __DIR__ . '/../config/zatca.php' => config_path('zatca.php'),
            ], 'zatca-config');

            // Publish migrations
            $this->publishes([
                __DIR__ . '/../database/migrations' => database_path('migrations'),
            ], 'zatca-migrations');

            // Register commands
            $this->commands([
                GenerateCSRCommand::class,
                ComplianceCSIDCommand::class,
                ProductionCSIDCommand::class,
                ReportInvoiceCommand::class,
                ClearInvoiceCommand::class,
                ValidateInvoiceCommand::class,
                ZatcaStatusCommand::class,
            ]);
        }

        // Load migrations
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}
