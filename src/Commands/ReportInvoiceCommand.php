<?php

declare(strict_types=1);

namespace SaudiZATCA\Commands;

use Illuminate\Console\Command;
use SaudiZATCA\Facades\Zatca;
use SaudiZATCA\Data\InvoiceData;
use SaudiZATCA\Data\InvoiceLineData;
use SaudiZATCA\Data\SellerData;
use SaudiZATCA\Exceptions\InvoiceException;
use SaudiZATCA\Exceptions\APIException;

/**
 * Command to submit a simplified invoice (B2C) for reporting
 * 
 * php artisan zatca:report --number=INV001 --total=100 --vat=15
 */
class ReportInvoiceCommand extends Command
{
    protected $signature = 'zatca:report
                            {--number= : Invoice number}
                            {--total= : Total amount with VAT}
                            {--vat= : VAT amount}
                            {--xml= : Path to pre-generated XML file}
                            {--type=simplified : Invoice type (simplified/standard)}';

    protected $description = 'Submit an invoice to ZATCA (reporting for B2C, clearance for B2B)';

    public function handle(): int
    {
        $this->info('═══════════════════════════════════════');
        $this->info('  ZATCA Invoice Submission');
        $this->info('═══════════════════════════════════════');
        
        try {
            // Check if we have pre-generated XML
            $xmlPath = $this->option('xml');
            if ($xmlPath && file_exists($xmlPath)) {
                return $this->submitExistingInvoice($xmlPath);
            }
            
            // Generate test invoice
            $invoiceNumber = $this->option('number') ?: 'INV-' . time();
            $total = (float) ($this->option('total') ?: 100);
            $vat = (float) ($this->option('vat') ?: 15);
            $type = $this->option('type');
            
            $this->info("Invoice: {$invoiceNumber}");
            $this->info("Total: {$total} SAR");
            $this->info("VAT: {$vat} SAR");
            $this->info("Type: {$type}");
            
            // Create seller from config
            $seller = SellerData::fromConfig(config('zatca.seller', []));
            
            // Create invoice
            $line = new InvoiceLineData(
                name: 'Test Product',
                quantity: 1,
                unitPrice: $total - $vat,
                taxRate: 15
            );
            
            $invoice = new InvoiceData(
                invoiceNumber: $invoiceNumber,
                issueDate: new \DateTime(),
                lines: [$line],
                type: $type
            );
            
            $this->info('Processing invoice...');
            $result = Zatca::processInvoice($invoice, $seller);
            
            $this->info('✅ Invoice submitted successfully!');
            $this->newLine();
            $this->info('Results:');
            $this->info("  UUID: {$result['uuid']}");
            $this->info("  Hash: " . substr($result['invoice_hash'], 0, 50) . '...');
            $this->info("  QR Code: " . substr($result['qr_code'], 0, 50) . '...');
            $this->info("  Submission Status: " . ($result['submission']['is_valid'] ? '✅ Valid' : '⚠️ Has warnings'));
            
            if (!empty($result['submission']['warnings'])) {
                $this->warn('Warnings:');
                foreach ($result['submission']['warnings'] as $warning) {
                    $this->warn("  - {$warning}");
                }
            }
            
            return self::SUCCESS;
            
        } catch (InvoiceException $e) {
            $this->error('❌ Invoice Error: ' . $e->getMessage());
            return self::FAILURE;
        } catch (APIException $e) {
            $this->error('❌ API Error: ' . $e->getMessage());
            if ($e->getResponseBody()) {
                $this->error('Response: ' . $e->getResponseBody());
            }
            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error('❌ Error: ' . $e->getMessage());
            $this->error($e->getTraceAsString());
            return self::FAILURE;
        }
    }

    private function submitExistingInvoice(string $xmlPath): int
    {
        $this->info("Submitting existing XML: {$xmlPath}");
        
        $xml = file_get_contents($xmlPath);
        
        // Load certificate and secret
        $certificate = Zatca::certificate()->loadCertificate('compliance');
        $secret = ''; // Get from storage
        
        if (empty($certificate)) {
            $this->error('Certificate not found. Complete onboarding first.');
            return self::FAILURE;
        }
        
        // For reporting
        $invoiceHash = hash('sha256', $xml);
        $uuid = uniqid('inv-', true);
        
        $result = Zatca::api()->reportInvoice($xml, base64_encode($invoiceHash), $uuid, $certificate, $secret);
        
        $this->info('✅ Invoice submitted!');
        $this->info('Status: ' . $result['status']);
        
        return self::SUCCESS;
    }
}
