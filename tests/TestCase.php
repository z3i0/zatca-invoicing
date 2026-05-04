<?php

declare(strict_types=1);

namespace SaudiZATCA\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use SaudiZATCA\ZatcaServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        // Fix for Windows OpenSSL EC key generation
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' && !getenv('OPENSSL_CONF')) {
            $opensslConf = 'C:\php\extras\ssl\openssl.cnf';
            if (file_exists($opensslConf)) {
                putenv("OPENSSL_CONF=$opensslConf");
            }
        }

        if (!getenv('RANDFILE')) {
            $randFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'openssl.rnd';
            if (!file_exists($randFile)) {
                file_put_contents($randFile, random_bytes(1024));
            }
            putenv("RANDFILE=$randFile");
        }
    }

    protected function getPackageProviders($app): array
    {
        return [
            ZatcaServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app): array
    {
        return [
            'Zatca' => \SaudiZATCA\Facades\Zatca::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // ZATCA test configuration
        $app['config']->set('zatca.environment', 'sandbox');
        $app['config']->set('zatca.seller.name_en', 'Test Company');
        $app['config']->set('zatca.seller.vat_number', '300000000000003');
        $app['config']->set('zatca.seller.street', 'Test Street');
        $app['config']->set('zatca.seller.city', 'Riyadh');
        $app['config']->set('zatca.certificate.organization', 'Test Company');
        $app['config']->set('zatca.certificate.common_name', 'Test Company');
        $app['config']->set('zatca.invoice.currency', 'SAR');
        $app['config']->set('zatca.invoice.default_tax_rate', 15.0);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}
