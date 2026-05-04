<?php

declare(strict_types=1);

namespace SaudiZATCA\Commands;

use Illuminate\Console\Command;
use SaudiZATCA\Facades\Zatca;
use SaudiZATCA\Exceptions\APIException;

/**
 * Command to request Production CSID
 *
 * php artisan zatca:production-csid
 */
class ProductionCSIDCommand extends Command
{
    protected $signature = 'zatca:production-csid
                            {--compliance-cert= : Path to compliance certificate}
                            {--request-id= : Compliance request ID}';

    protected $description = 'Request Production CSID from ZATCA';

    public function handle(): int
    {
        $this->info('═══════════════════════════════════════');
        $this->info('  ZATCA Production CSID Request');
        $this->info('═══════════════════════════════════════');

        try {
            // Load compliance certificate
            $complianceCert = $this->option('compliance-cert');
            if ($complianceCert && file_exists($complianceCert)) {
                $cert = file_get_contents($complianceCert);
            } else {
                $cert = Zatca::certificate()->loadCertificate('compliance');
            }

            if (empty($cert)) {
                $this->error('Compliance certificate not found. Run compliance CSID first.');
                return self::FAILURE;
            }

            // Get request ID
            $requestId = $this->option('request-id');
            if (empty($requestId)) {
                $this->warn('Request ID not provided. Using from stored data if available.');
                // You might want to store this in DB or file during compliance step
            }

            // Get secret (from storage or prompt)
            $secret = Zatca::invoice()->getSecret ?? null;
            if (empty($secret)) {
                $secret = $this->secret('Enter compliance API secret');
            }

            $this->info('Requesting Production CSID from ZATCA...');
            $result = Zatca::api()->requestProductionCSID($requestId ?? '', $cert, $secret);

            // Save production certificate
            $certPath = Zatca::certificate()->saveCertificate($result['certificate'], 'production');

            // Update secret
            Zatca::invoice()->saveSecret($result['secret']);

            $this->info('✅ Production CSID received successfully!');
            $this->newLine();
            $this->info('Details:');
            $this->info("  Certificate saved: {$certPath}");
            $this->info("  Request ID: {$result['request_id']}");
            $this->newLine();
            $this->warn('⚠️  You are now in PRODUCTION mode. All invoices will be submitted to ZATCA.');

            return self::SUCCESS;
        } catch (APIException $e) {
            $this->error('❌ API Error: ' . $e->getMessage());
            if ($e->getResponseBody()) {
                $this->error('Response: ' . $e->getResponseBody());
            }
            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error('❌ Error: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
