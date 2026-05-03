<?php

declare(strict_types=1);

namespace SaudiZATCA\Commands;

use Illuminate\Console\Command;
use SaudiZATCA\Facades\Zatca;
use SaudiZATCA\Exceptions\APIException;

/**
 * Command to submit a standard invoice (B2B) for clearance
 * 
 * php artisan zatca:clear --xml=/path/to/signed.xml
 */
class ClearInvoiceCommand extends Command
{
    protected $signature = 'zatca:clear
                            {--xml= : Path to signed XML file}
                            {--uuid= : Invoice UUID}
                            {--hash= : Invoice hash}';

    protected $description = 'Submit a standard invoice (B2B) for clearance';

    public function handle(): int
    {
        $this->info('═══════════════════════════════════════');
        $this->info('  ZATCA Invoice Clearance');
        $this->info('═══════════════════════════════════════');
        
        try {
            $xmlPath = $this->option('xml');
            
            if (empty($xmlPath) || !file_exists($xmlPath)) {
                $this->error('Valid signed XML file path is required (--xml=)');
                return self::FAILURE;
            }
            
            $signedXml = file_get_contents($xmlPath);
            $uuid = $this->option('uuid') ?: uniqid('inv-', true);
            $hash = $this->option('hash') ?: base64_encode(hash('sha256', $signedXml, true));
            
            $certificate = Zatca::certificate()->loadCertificate('compliance');
            $secret = ''; // Get from storage
            
            if (empty($certificate)) {
                $this->error('Certificate not found. Complete onboarding first.');
                return self::FAILURE;
            }
            
            $this->info('Submitting invoice for clearance...');
            $result = Zatca::api()->clearInvoice($signedXml, $hash, $uuid, $certificate, $secret);
            
            $this->info('✅ Invoice cleared successfully!');
            $this->info('Status: ' . ($result['is_valid'] ? 'Valid' : 'Check warnings'));
            
            if (!empty($result['cleared_invoice'])) {
                $this->info('Cleared invoice received from ZATCA.');
            }
            
            return self::SUCCESS;
            
        } catch (APIException $e) {
            $this->error('❌ API Error: ' . $e->getMessage());
            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error('❌ Error: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
