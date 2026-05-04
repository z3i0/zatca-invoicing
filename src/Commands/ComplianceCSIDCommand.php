<?php

declare(strict_types=1);

namespace SaudiZATCA\Commands;

use Illuminate\Console\Command;
use SaudiZATCA\Facades\Zatca;
use SaudiZATCA\Exceptions\APIException;

/**
 * Command to request Compliance CSID
 *
 * php artisan zatca:compliance-csid --otp=123456
 */
class ComplianceCSIDCommand extends Command
{
    protected $signature = 'zatca:compliance-csid
                            {--otp= : OTP from ZATCA portal}
                            {--csr= : Path to CSR file (optional)}';

    protected $description = 'Request Compliance CSID from ZATCA';

    public function handle(): int
    {
        $this->info('═══════════════════════════════════════');
        $this->info('  ZATCA Compliance CSID Request');
        $this->info('═══════════════════════════════════════');

        try {
            $otp = $this->option('otp');

            if (empty($otp)) {
                $otp = config('zatca.certificate.otp');
            }

            if (empty($otp)) {
                $this->error('OTP is required. Provide --otp or set ZATCA_OTP in .env');
                return self::FAILURE;
            }

            $this->info("Environment: " . config('zatca.environment'));
            $this->info("OTP: {$otp}");

            // Load CSR
            $csrPath = $this->option('csr');
            if ($csrPath && file_exists($csrPath)) {
                $csr = file_get_contents($csrPath);
            } else {
                $csr = Zatca::certificate()->loadCertificate('csr');
            }

            if (empty($csr)) {
                $this->error('CSR not found. Run "php artisan zatca:csr" first.');
                return self::FAILURE;
            }

            $this->info('Requesting Compliance CSID from ZATCA...');
            $result = Zatca::api()->requestComplianceCSID($csr, $otp);

            // Save certificate
            $certPath = Zatca::certificate()->saveCertificate($result['certificate'], 'compliance');

            // Save secret
            Zatca::invoice()->saveSecret($result['secret']);

            $this->info('✅ Compliance CSID received successfully!');
            $this->newLine();
            $this->info('Details:');
            $this->info("  Certificate saved: {$certPath}");
            $this->info("  Request ID: {$result['request_id']}");
            $this->newLine();
            $this->info('Next steps:');
            $this->info('1. Test compliance with sample invoices');
            $this->info('2. Request Production CSID:');
            $this->warn('   php artisan zatca:production-csid');

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
