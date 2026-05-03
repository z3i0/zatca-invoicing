<?php

declare(strict_types=1);

namespace SaudiZATCA\Tests\Unit;

use SaudiZATCA\Tests\TestCase;
use SaudiZATCA\Services\InvoiceService;
use SaudiZATCA\Data\InvoiceData;
use SaudiZATCA\Data\InvoiceLineData;
use SaudiZATCA\Data\SellerData;
use SaudiZATCA\Data\BuyerData;

class InvoiceServiceTest extends TestCase
{
    /** @test */
    public function it_calculates_invoice_totals_correctly()
    {
        $lines = [
            new InvoiceLineData('Product A', 2, 50.0, 15.0),
            new InvoiceLineData('Product B', 1, 100.0, 15.0),
        ];

        $invoice = new InvoiceData('INV-001', new \DateTime(), $lines);

        // Subtotal: 2*50 + 1*100 = 200
        $this->assertEquals(200.0, $invoice->subTotal());
        
        // Tax: 200 * 0.15 = 30
        $this->assertEquals(30.0, $invoice->totalTax());
        
        // Total: 200 + 30 = 230
        $this->assertEquals(230.0, $invoice->totalAmount());
    }

    /** @test */
    public function it_calculates_line_totals()
    {
        $line = new InvoiceLineData('Product', 3, 33.33, 15.0);

        // Net: 3 * 33.33 = 99.99
        $this->assertEquals(99.99, $line->netTotal());
        
        // Tax: 99.99 * 0.15 = 15.00 (rounded)
        $this->assertEquals(15.0, $line->calculateTax());
    }

    /** @test */
    public function it_calculates_line_with_discount()
    {
        $line = new InvoiceLineData('Product', 1, 100.0, 15.0, discount: 10.0);

        // Net: 100 - 10 = 90
        $this->assertEquals(90.0, $line->netTotal());
        
        // Tax: 90 * 0.15 = 13.50
        $this->assertEquals(13.50, $line->calculateTax());
    }

    /** @test */
    public function it_creates_seller_data_from_array()
    {
        $data = [
            'name_en' => 'Test Company',
            'vat_number' => '300000000000003',
            'city' => 'Riyadh',
        ];

        $seller = SellerData::fromArray($data);

        $this->assertEquals('Test Company', $seller->nameEn);
        $this->assertEquals('300000000000003', $seller->vatNumber);
        $this->assertEquals('Riyadh', $seller->city);
        $this->assertEquals('SA', $seller->country);
    }

    /** @test */
    public function it_creates_buyer_data_from_array()
    {
        $data = [
            'name' => 'Buyer Co',
            'vat_number' => '300000000000004',
            'city' => 'Jeddah',
        ];

        $buyer = BuyerData::fromArray($data);

        $this->assertEquals('Buyer Co', $buyer->name);
        $this->assertEquals('300000000000004', $buyer->vatNumber);
        $this->assertTrue($buyer->isB2B());
    }

    /** @test */
    public function buyer_without_vat_is_not_b2b()
    {
        $buyer = new BuyerData(name: 'Consumer');

        $this->assertFalse($buyer->isB2B());
    }

    /** @test */
    public function it_determines_invoice_submission_type()
    {
        $line = new InvoiceLineData('Test', 1, 100.0);

        $standardInvoice = new InvoiceData('INV-001', new \DateTime(), [$line], InvoiceData::TYPE_STANDARD);
        $this->assertTrue($standardInvoice->needsClearance());
        $this->assertFalse($standardInvoice->needsReporting());

        $simplifiedInvoice = new InvoiceData('INV-002', new \DateTime(), [$line], InvoiceData::TYPE_SIMPLIFIED);
        $this->assertFalse($simplifiedInvoice->needsClearance());
        $this->assertTrue($simplifiedInvoice->needsReporting());
    }

    /** @test */
    public function it_generates_uuid()
    {
        $invoice = new InvoiceData(
            'INV-001',
            new \DateTime(),
            [new InvoiceLineData('Test', 1, 100.0)]
        );

        $uuid = $invoice->getUuid();

        $this->assertNotEmpty($uuid);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $uuid
        );
    }

    /** @test */
    public function it_creates_invoice_from_array()
    {
        $data = [
            'invoice_number' => 'INV-003',
            'type' => 'standard',
            'lines' => [
                ['name' => 'Item 1', 'quantity' => 2, 'unit_price' => 50],
                ['name' => 'Item 2', 'quantity' => 1, 'unit_price' => 100],
            ],
        ];

        $invoice = InvoiceData::fromArray($data);

        $this->assertEquals('INV-003', $invoice->invoiceNumber);
        $this->assertCount(2, $invoice->lines);
        $this->assertEquals(200.0, $invoice->subTotal());
    }

    /** @test */
    public function it_requires_at_least_one_line()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one line item');

        new InvoiceData('INV-001', new \DateTime(), []);
    }

    /** @test */
    public function it_returns_correct_subtype_code()
    {
        $line = new InvoiceLineData('Test', 1, 100.0);

        $standard = new InvoiceData('INV-1', new \DateTime(), [$line], InvoiceData::TYPE_STANDARD);
        $this->assertEquals(InvoiceData::SUBTYPE_TAX_INVOICE, $standard->subTypeCode());

        $simplified = new InvoiceData('INV-2', new \DateTime(), [$line], InvoiceData::TYPE_SIMPLIFIED);
        $this->assertEquals(InvoiceData::SUBTYPE_SIMPLIFIED, $simplified->subTypeCode());

        $credit = new InvoiceData('INV-3', new \DateTime(), [$line], InvoiceData::TYPE_CREDIT_NOTE);
        $this->assertEquals(InvoiceData::SUBTYPE_CREDIT_NOTE, $credit->subTypeCode());
    }

    /** @test */
    public function it_converts_to_array()
    {
        $line = new InvoiceLineData('Test Product', 2, 50.0, 15.0);
        $invoice = new InvoiceData('INV-001', new \DateTime('2024-01-15 10:30:00'), [$line]);

        $array = $invoice->toArray();

        $this->assertArrayHasKey('invoice_number', $array);
        $this->assertArrayHasKey('sub_total', $array);
        $this->assertArrayHasKey('total_tax', $array);
        $this->assertArrayHasKey('total_amount', $array);
        $this->assertArrayHasKey('lines', $array);
        $this->assertEquals(100.0, $array['sub_total']);
        $this->assertEquals(15.0, $array['total_tax']);
    }

    /** @test */
    public function it_calculates_total_with_discount()
    {
        $lines = [
            new InvoiceLineData('Product A', 1, 100.0, 15.0),
        ];

        $invoice = new InvoiceData(
            'INV-001',
            new \DateTime(),
            $lines,
            totalDiscount: 10.0
        );

        // Subtotal: 100, Tax: 15, Total with discount: 100 + 15 - 10 = 105
        $this->assertEquals(105.0, $invoice->totalAmount());
    }

    /** @test */
    public function it_calculates_total_with_charges()
    {
        $lines = [
            new InvoiceLineData('Product A', 1, 100.0, 15.0),
        ];

        $invoice = new InvoiceData(
            'INV-001',
            new \DateTime(),
            $lines,
            totalCharges: 5.0
        );

        // Subtotal: 100, Tax: 15, Total with charges: 100 + 15 + 5 = 120
        $this->assertEquals(120.0, $invoice->totalAmount());
    }
}
