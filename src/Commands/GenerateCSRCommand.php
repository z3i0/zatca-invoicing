<?php

declare(strict_types=1);

namespace SaudiZATCA\Commands;

use Illuminate\Console\Command;
use SaudiZATCA\Facades\Zatca;
use SaudiZATCA\Exceptions\CertificateException;

/**
 * Command to generate CSR (Certificate Signing Request)
 *
 * php artisan zatca:csr --vat=300000000000003 --org="My Company" --cn="My Company"
 */
class GenerateCSRCommand extends Command
{
    protected $signature = 'zatca:csr
                            {--vat= : VAT Number (15 digits starting/ending with 3)}
                            {--org= : Organization Name}
                            {--ou= : Organization Unit}
                            {--cn= : Common Name}
                            {--street= : Street Address}
                            {--city= : City}
                            {--device=0001 : Device Serial Number}';

    protected $description = 'Generate Certificate Signing Request (CSR) for ZATCA onboarding';

    public function handle(): int
    {
        $this->info('═══════════════════════════════════════');
        $this->info('  ZATCA CSR Generation');
        $this->info('═══════════════════════════════════════');

        try {
            $vatNumber = $this->option('vat') ?: config('zatca.seller.vat_number');
            $orgName = $this->option('org') ?: config('zatca.certificate.organization');
            $orgUnit = $this->option('ou') ?: config('zatca.certificate.organization_unit');
            $commonName = $this->option('cn') ?: config('zatca.certificate.common_name');
            $street = $this->option('street') ?: config('zatca.seller.street');
            $city = $this->option('city') ?: config('zatca.seller.city');
            $deviceSerial = $this->option('device');

            if (empty($vatNumber)) {
                $this->error('VAT Number is required. Provide --vat or set ZATCA_VAT_NUMBER in .env');
                return self::FAILURE;
            }

            $this->info("VAT Number: {$vatNumber}");
            $this->info("Organization: {$orgName}");

            $merchantData = [
                'organization_identifier' => $vatNumber,
                'organization' => $orgName,
                'organization_unit' => $orgUnit,
                'common_name' => $commonName,
                'street' => $street,
                'city' => $city,
                'device_serial' => $deviceSerial,
                'solution_name' => config('zatca.solution_name', 'Laravel'),
            ];

            $this->info('Generating CSR and Private Key...');
            $result = Zatca::certificate()->generateCSR($merchantData);

            $this->info('✅ CSR generated successfully!');
            $this->newLine();
            $this->info('Files saved:');
            $this->info("  CSR: {$result['csr_path']}");
            $this->info("  Private Key: {$result['key_path']}");
            $this->newLine();
            $this->info('Next step: Submit CSR to ZATCA portal to get OTP, then run:');
            $this->warn('  php artisan zatca:compliance-csid --otp=YOUR_OTP');

            return self::SUCCESS;
        } catch (CertificateException $e) {
            $this->error('❌ Certificate Error: ' . $e->getMessage());
            if ($e->getDetails()) {
                $this->error('Details: ' . json_encode($e->getDetails()));
            }
            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error('❌ Error: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
