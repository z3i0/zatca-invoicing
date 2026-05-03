<?php

declare(strict_types=1);

namespace SaudiZATCA\Tests\Feature;

use SaudiZATCA\Tests\TestCase;
use SaudiZATCA\Facades\Zatca;
use SaudiZATCA\Data\InvoiceData;
use SaudiZATCA\Data\InvoiceLineData;
use SaudiZATCA\Data\SellerData;
use SaudiZATCA\Data\BuyerData;
use SaudiZATCA\Models\ZatcaInvoice;

class InvoiceSubmissionTest extends TestCase
{
    /** @test */
    public function it_generates_valid_xml_invoice()
    {
        $seller = SellerData::fromConfig(config('zatca.seller'));
        
        $invoice = new InvoiceData(
            'INV-TEST-001',
            new \DateTime(),
            [
                new InvoiceLineData('Laptop', 1, 3000.0, 15.0),
                new InvoiceLineData('Mouse', 2, 150.0, 15.0),
            ],
            type: InvoiceData::TYPE_STANDARD
        );

        $buyer = new BuyerData(
            name: 'Buyer Company',
            vatNumber: '300000000000004',
            city: 'Jeddah'
        );

        $xml = Zatca::xml()->generate($invoice, $seller, $buyer);

        $this->assertStringContainsString('<?xml version="1.0" encoding="UTF-8"?>', $xml);
        $this->assertStringContainsString('<Invoice', $xml);
        $this->assertStringContainsString($invoice->invoiceNumber, $xml);
        $this->assertStringContainsString($seller->vatNumber, $xml);
        $this->assertStringContainsString($buyer->vatNumber, $xml);
    }

    /** @test */
    public function it_generates_simplified_invoice_xml()
    {
        $seller = SellerData::fromConfig(config('zatca.seller'));
        
        $invoice = new InvoiceData(
            'INV-SIMPLE-001',
            new \DateTime(),
            [
                new InvoiceLineData('Coffee', 1, 25.0, 15.0),
            ],
            type: InvoiceData::TYPE_SIMPLIFIED
        );

        $xml = Zatca::xml()->generate($invoice, $seller);

        $this->assertStringContainsString('<?xml version="1.0" encoding="UTF-8"?>', $xml);
        $this->assertStringContainsString($invoice->invoiceNumber, $xml);
    }

    /** @test */
    public function it_generates_credit_note_xml()
    {
        $seller = SellerData::fromConfig(config('zatca.seller'));
        
        $invoice = new InvoiceData(
            'CN-001',
            new \DateTime(),
            [
                new InvoiceLineData('Refund', 1, 100.0, 15.0),
            ],
            type: InvoiceData::TYPE_CREDIT_NOTE,
            referenceInvoiceNumber: 'INV-ORIG-001',
            referenceInvoiceDate: new \DateTime('2024-01-01')
        );

        $xml = Zatca::xml()->generate($invoice, $seller);

        $this->assertStringContainsString('CreditNote', $xml);
        $this->assertStringContainsString('INV-ORIG-001', $xml);
    }

    /** @test */
    public function it_calculates_invoice_hash()
    {
        $xml = '<test><data>value</data></test>';
        
        $hash = Zatca::xml()->calculateHash($xml);
        
        $this->assertNotEmpty($hash);
        $this->assertTrue($this->isValidBase64($hash));
        
        // Same input should produce same hash
        $hash2 = Zatca::xml()->calculateHash($xml);
        $this->assertEquals($hash, $hash2);
    }

    /** @test */
    public function it_stores_invoice_in_database()
    {
        $invoice = ZatcaInvoice::create([
            'invoice_number' => 'INV-DB-001',
            'uuid' => 'test-uuid-123',
            'type' => 'simplified',
            'status' => 'draft',
            'seller_name' => 'Test Company',
            'seller_vat_number' => '300000000000003',
            'sub_total' => 100.00,
            'tax_total' => 15.00,
            'total_amount' => 115.00,
            'currency' => 'SAR',
            'issue_date' => now(),
            'line_items' => [
                ['name' => 'Test Item', 'quantity' => 1, 'price' => 100],
            ],
        ]);

        $this->assertDatabaseHas('zatca_invoices', [
            'invoice_number' => 'INV-DB-001',
            'uuid' => 'test-uuid-123',
        ]);

        $this->assertEquals('draft', $invoice->status);
        $this->assertEquals(100.00, $invoice->sub_total);
    }

    /** @test */
    public function it_can_update_invoice_status()
    {
        $invoice = ZatcaInvoice::create([
            'invoice_number' => 'INV-STATUS-001',
            'uuid' => 'test-uuid-456',
            'type' => 'simplified',
            'status' => 'signed',
            'seller_name' => 'Test Company',
            'seller_vat_number' => '300000000000003',
            'sub_total' => 100.00,
            'tax_total' => 15.00,
            'total_amount' => 115.00,
            'currency' => 'SAR',
            'issue_date' => now(),
            'line_items' => [],
        ]);

        $invoice->markAsSubmitted(['status' => '200']);

        $this->assertEquals('submitted', $invoice->fresh()->status);
        $this->assertNotNull($invoice->fresh()->submitted_at);
    }

    /** @test */
    public function it_can_mark_invoice_as_failed()
    {
        $invoice = ZatcaInvoice::create([
            'invoice_number' => 'INV-FAIL-001',
            'uuid' => 'test-uuid-789',
            'type' => 'simplified',
            'status' => 'signed',
            'seller_name' => 'Test Company',
            'seller_vat_number' => '300000000000003',
            'sub_total' => 100.00,
            'tax_total' => 15.00,
            'total_amount' => 115.00,
            'currency' => 'SAR',
            'issue_date' => now(),
            'line_items' => [],
            'retry_count' => 0,
        ]);

        $invoice->markAsFailed('Network timeout');

        $fresh = $invoice->fresh();
        $this->assertEquals('failed', $fresh->status);
        $this->assertEquals(1, $fresh->retry_count);
    }

    /** @test */
    public function it_checks_retry_eligibility()
    {
        $invoice = ZatcaInvoice::create([
            'invoice_number' => 'INV-RETRY-001',
            'uuid' => 'test-uuid-retry',
            'type' => 'simplified',
            'status' => 'failed',
            'seller_name' => 'Test Company',
            'seller_vat_number' => '300000000000003',
            'sub_total' => 100.00,
            'tax_total' => 15.00,
            'total_amount' => 115.00,
            'currency' => 'SAR',
            'issue_date' => now(),
            'line_items' => [],
            'retry_count' => 2,
        ]);

        $this->assertTrue($invoice->canRetry());

        $invoice->update(['retry_count' => 3]);
        $this->assertFalse($invoice->fresh()->canRetry());
    }

    /** @test */
    public function it_queries_pending_invoices()
    {
        // Create pending invoice
        ZatcaInvoice::create([
            'invoice_number' => 'INV-PENDING-001',
            'uuid' => 'uuid-pending-1',
            'status' => 'signed',
            'seller_name' => 'Test',
            'seller_vat_number' => '300000000000003',
            'sub_total' => 100,
            'tax_total' => 15,
            'total_amount' => 115,
            'currency' => 'SAR',
            'issue_date' => now(),
            'line_items' => [],
            'retry_count' => 0,
        ]);

        // Create already submitted invoice
        ZatcaInvoice::create([
            'invoice_number' => 'INV-DONE-001',
            'uuid' => 'uuid-done-1',
            'status' => 'submitted',
            'seller_name' => 'Test',
            'seller_vat_number' => '300000000000003',
            'sub_total' => 100,
            'tax_total' => 15,
            'total_amount' => 115,
            'currency' => 'SAR',
            'issue_date' => now(),
            'line_items' => [],
        ]);

        $pending = ZatcaInvoice::pending()->get();

        $this->assertCount(1, $pending);
        $this->assertEquals('INV-PENDING-001', $pending->first()->invoice_number);
    }

    /** @test */
    public function it_validates_xml_structure()
    {
        $validXml = '<?xml version="1.0"?><root><child/></root>';
        $result = Zatca::invoice()->validateXML($validXml);

        $this->assertTrue($result['valid']);
    }

    /** @test */
    public function it_detects_invalid_xml()
    {
        $invalidXml = '<?xml version="1.0"?><root><unclosed>';
        $result = Zatca::invoice()->validateXML($invalidXml);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    /** @test */
    public function it_calculates_correct_tax_for_multiple_rates()
    {
        $seller = SellerData::fromConfig(config('zatca.seller'));
        
        $lines = [
            new InvoiceLineData('Standard Item', 1, 100.0, 15.0),
            new InvoiceLineData('Zero-rated Item', 1, 50.0, 0.0),
        ];

        $invoice = new InvoiceData('INV-MIXED-001', new \DateTime(), $lines);

        $this->assertEquals(150.0, $invoice->subTotal());
        $this->assertEquals(15.0, $invoice->totalTax());
        $this->assertEquals(165.0, $invoice->totalAmount());
    }

    private function isValidBase64(string $data): bool
    {
        $decoded = base64_decode($data, true);
        return $decoded !== false && base64_encode($decoded) === $data;
    }
}
