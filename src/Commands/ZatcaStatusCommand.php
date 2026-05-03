<?php

declare(strict_types=1);

namespace SaudiZATCA\Commands;

use Illuminate\Console\Command;
use SaudiZATCA\Facades\Zatca;

/**
 * Command to check ZATCA integration status
 * 
 * php artisan zatca:status
 */
class ZatcaStatusCommand extends Command
{
    protected $signature = 'zatca:status';

    protected $description = 'Check ZATCA integration status and configuration';

    public function handle(): int
    {
        $this->info('═══════════════════════════════════════');
        $this->info('  ZATCA Integration Status');
        $this->info('═══════════════════════════════════════');
        
        // Environment
        $environment = config('zatca.environment', 'not set');
        $envColor = match ($environment) {
            'production' => 'bg=red;fg=white',
            'simulation' => 'bg=yellow;fg=black',
            'sandbox' => 'bg=green;fg=black',
            default => 'bg=red;fg=white',
        };
        
        $this->newLine();
        $this->components->twoColumnDetail('Environment', $environment);
        $this->newLine();
        
        // Configuration
        $this->info('Configuration:');
        $this->components->twoColumnDetail('Seller Name', config('zatca.seller.name_en', '❌ Not set'));
        $this->components->twoColumnDetail('VAT Number', config('zatca.seller.vat_number', '❌ Not set'));
        $this->components->twoColumnDetail('API Base URL', Zatca::api()->getBaseUrl());
        
        // Certificates
        $this->newLine();
        $this->info('Certificates:');
        
        $csrExists = Zatca::certificate()->loadCertificate('csr') !== null;
        $complianceExists = Zatca::certificate()->loadCertificate('compliance') !== null;
        $productionExists = Zatca::certificate()->loadCertificate('production') !== null;
        $privateKeyExists = Zatca::certificate()->loadPrivateKey() !== null;
        
        $this->components->twoColumnDetail('CSR', $csrExists ? '✅ Found' : '❌ Not found');
        $this->components->twoColumnDetail('Private Key', $privateKeyExists ? '✅ Found' : '❌ Not found');
        $this->components->twoColumnDetail('Compliance Cert', $complianceExists ? '✅ Found' : '❌ Not found');
        $this->components->twoColumnDetail('Production Cert', $productionExists ? '✅ Found' : '❌ Not found');
        
        // Status
        $this->newLine();
        if ($productionExists) {
            $this->components->twoColumnDetail('Status', '<fg=green;options=bold>PRODUCTION READY</>');
            $this->warn('⚠️  You are configured for PRODUCTION. Invoices will be submitted to ZATCA.');
        } elseif ($complianceExists) {
            $this->components->twoColumnDetail('Status', '<fg=yellow;options=bold>COMPLIANCE MODE</>');
            $this->info('Next step: Request Production CSID with "php artisan zatca:production-csid"');
        } elseif ($csrExists) {
            $this->components->twoColumnDetail('Status', '<fg=blue;options=bold>CSR GENERATED</>');
            $this->info('Next step: Request Compliance CSID with "php artisan zatca:compliance-csid --otp=YOUR_OTP"');
        } else {
            $this->components->twoColumnDetail('Status', '<fg=red;options=bold>NOT CONFIGURED</>');
            $this->info('Next step: Generate CSR with "php artisan zatca:csr --vat=YOUR_VAT"');
        }
        
        // API Health Check (optional)
        $this->newLine();
        $this->info('API Status:');
        try {
            $status = Zatca::api()->getStatus();
            $this->components->twoColumnDetail('ZATCA API', '✅ Reachable');
        } catch (\Throwable $e) {
            $this->components->twoColumnDetail('ZATCA API', '⚠️  ' . $e->getMessage());
        }
        
        return self::SUCCESS;
    }
}
