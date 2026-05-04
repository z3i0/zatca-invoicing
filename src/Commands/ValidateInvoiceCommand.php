<?php

declare(strict_types=1);

namespace SaudiZATCA\Commands;

use Illuminate\Console\Command;
use SaudiZATCA\Facades\Zatca;

/**
 * Command to validate invoice XML
 *
 * php artisan zatca:validate --xml=/path/to/invoice.xml
 */
class ValidateInvoiceCommand extends Command
{
    protected $signature = 'zatca:validate
                            {--xml= : Path to XML file}
                            {--qr= : QR code data to validate}';

    protected $description = 'Validate invoice XML or QR code';

    public function handle(): int
    {
        $this->info('═══════════════════════════════════════');
        $this->info('  ZATCA Invoice Validation');
        $this->info('═══════════════════════════════════════');

        $xmlPath = $this->option('xml');
        $qrData = $this->option('qr');

        if ($xmlPath) {
            return $this->validateXML($xmlPath);
        }

        if ($qrData) {
            return $this->validateQR($qrData);
        }

        $this->error('Please provide --xml= or --qr=');
        return self::FAILURE;
    }

    private function validateXML(string $xmlPath): int
    {
        if (!file_exists($xmlPath)) {
            $this->error("File not found: {$xmlPath}");
            return self::FAILURE;
        }

        $xml = file_get_contents($xmlPath);
        $this->info("Validating XML: {$xmlPath}");

        $result = Zatca::invoice()->validateXML($xml);

        if ($result['valid']) {
            $this->info('✅ XML is valid');
            return self::SUCCESS;
        }

        $this->warn('⚠️  XML has validation issues:');
        foreach ($result['errors'] as $error) {
            $this->error("  [Line {$error['line']}] {$error['message']}");
        }

        return self::FAILURE;
    }

    private function validateQR(string $qrData): int
    {
        $this->info('Validating QR code...');

        $isValid = Zatca::qr()->validate($qrData);

        if ($isValid) {
            $this->info('✅ QR code is valid');

            $decoded = Zatca::qr()->getFormattedData($qrData);
            $this->newLine();
            $this->info('Decoded Data:');
            foreach ($decoded as $key => $value) {
                $this->info("  {$key}: {$value}");
            }

            return self::SUCCESS;
        }

        $this->error('❌ QR code is invalid');
        return self::FAILURE;
    }
}
