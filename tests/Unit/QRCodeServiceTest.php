<?php

declare(strict_types=1);

namespace SaudiZATCA\Tests\Unit;

use SaudiZATCA\Tests\TestCase;
use SaudiZATCA\Services\QRCodeService;
use SaudiZATCA\Data\SellerData;
use SaudiZATCA\Data\InvoiceData;
use SaudiZATCA\Data\InvoiceLineData;

class QRCodeServiceTest extends TestCase
{
    private QRCodeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new QRCodeService();
    }

    /** @test */
    public function it_generates_phase1_qr_code()
    {
        $seller = new SellerData('Test Company', '300000000000003');
        
        $qrData = $this->service->generatePhase1QR(
            $seller,
            100.0,
            15.0,
            new \DateTime('2024-01-15 10:30:00')
        );

        $this->assertNotEmpty($qrData);
        $this->assertTrue($this->isValidBase64($qrData));
    }

    /** @test */
    public function it_generates_phase2_qr_code()
    {
        $seller = new SellerData('Test Company', '300000000000003');
        $invoice = new InvoiceData(
            'INV-001',
            new \DateTime('2024-01-15 10:30:00'),
            [new InvoiceLineData('Product', 1, 100.0)]
        );
        
        $qrData = $this->service->generatePhase2QR(
            $seller,
            $invoice,
            'test_hash_123',
            'test_signature_456',
            'test_public_key_789',
            new \DateTime('2024-01-15 10:30:00')
        );

        $this->assertNotEmpty($qrData);
        $this->assertTrue($this->isValidBase64($qrData));
    }

    /** @test */
    public function it_decodes_qr_code_data()
    {
        $seller = new SellerData('Test Company', '300000000000003');
        
        $qrData = $this->service->generatePhase1QR(
            $seller,
            115.0,
            15.0,
            new \DateTime('2024-01-15 10:30:00')
        );

        $decoded = $this->service->decode($qrData);

        $this->assertArrayHasKey(1, $decoded); // Seller name
        $this->assertArrayHasKey(2, $decoded); // VAT number
        $this->assertArrayHasKey(3, $decoded); // Timestamp
        $this->assertArrayHasKey(4, $decoded); // Total
        $this->assertArrayHasKey(5, $decoded); // VAT
        
        $this->assertEquals('Test Company', $decoded[1]);
        $this->assertEquals('300000000000003', $decoded[2]);
    }

    /** @test */
    public function it_validates_correct_qr_code()
    {
        $seller = new SellerData('Test Company', '300000000000003');
        
        $qrData = $this->service->generatePhase1QR(
            $seller,
            115.0,
            15.0,
            new \DateTime('2024-01-15 10:30:00')
        );

        $this->assertTrue($this->service->validate($qrData));
    }

    /** @test */
    public function it_rejects_invalid_qr_code()
    {
        $this->assertFalse($this->service->validate('invalid_data'));
        $this->assertFalse($this->service->validate(base64_encode('invalid')));
    }

    /** @test */
    public function it_returns_formatted_data()
    {
        $seller = new SellerData('Test Company', '300000000000003');
        
        $qrData = $this->service->generatePhase1QR(
            $seller,
            115.0,
            15.0,
            new \DateTime('2024-01-15 10:30:00')
        );

        $formatted = $this->service->getFormattedData($qrData);

        $this->assertArrayHasKey('seller_name', $formatted);
        $this->assertArrayHasKey('vat_number', $formatted);
        $this->assertArrayHasKey('timestamp', $formatted);
        $this->assertArrayHasKey('total_amount', $formatted);
        $this->assertArrayHasKey('vat_amount', $formatted);
        
        $this->assertEquals('Test Company', $formatted['seller_name']);
        $this->assertEquals('300000000000003', $formatted['vat_number']);
        $this->assertEquals('115', $formatted['total_amount']);
        $this->assertEquals('15', $formatted['vat_amount']);
    }

    /** @test */
    public function it_generates_consistent_qr_data()
    {
        $seller = new SellerData('Test Company', '300000000000003');
        $timestamp = new \DateTime('2024-01-15 10:30:00');
        
        $qr1 = $this->service->generatePhase1QR($seller, 100.0, 15.0, $timestamp);
        $qr2 = $this->service->generatePhase1QR($seller, 100.0, 15.0, $timestamp);

        $this->assertEquals($qr1, $qr2, 'Same inputs should produce same QR data');
    }

    /** @test */
    public function it_decodes_arabic_seller_name()
    {
        $seller = new SellerData('شركة اختبار', '300000000000003', nameAr: 'Test Company');
        
        $qrData = $this->service->generatePhase1QR($seller, 100.0, 15.0);

        $decoded = $this->service->decode($qrData);
        
        $this->assertEquals('شركة اختبار', $decoded[1]);
    }

    /** @test */
    public function it_validates_timestamp_format()
    {
        $seller = new SellerData('Test', '300000000000003');
        
        // Create QR with invalid timestamp
        $invalidTlv = "\x01\x04Test\x02\x0f300000000000003\x03\x0binvalid\x04\x03100\x05\x0215";
        $invalidQr = base64_encode($invalidTlv);
        
        $this->assertFalse($this->service->validate($invalidQr));
    }

    /** @test */
    public function it_validates_numeric_amounts()
    {
        $seller = new SellerData('Test', '300000000000003');
        
        // Create QR with non-numeric total
        $invalidTlv = "\x01\x04Test\x02\x0f300000000000003\x03\x172024-01-15T10:30:00+00:00\x04\x05abc\x05\x0215";
        $invalidQr = base64_encode($invalidTlv);
        
        $this->assertFalse($this->service->validate($invalidQr));
    }

    /** @test */
    public function phase2_qr_contains_all_tags()
    {
        $seller = new SellerData('Test Company', '300000000000003');
        $invoice = new InvoiceData(
            'INV-001',
            new \DateTime('2024-01-15 10:30:00'),
            [new InvoiceLineData('Product', 1, 100.0)]
        );
        
        $qrData = $this->service->generatePhase2QR(
            $seller,
            $invoice,
            'test_hash',
            'test_signature',
            'test_public_key'
        );

        $decoded = $this->service->decode($qrData);

        // Phase 2 has 8 tags (1-5 + 6,7,8)
        $this->assertArrayHasKey(6, $decoded); // Hash
        $this->assertArrayHasKey(7, $decoded); // Signature
        $this->assertArrayHasKey(8, $decoded); // Public Key
        
        $this->assertEquals('test_hash', $decoded[6]);
        $this->assertEquals('test_signature', $decoded[7]);
        $this->assertEquals('test_public_key', $decoded[8]);
    }

    /** @test */
    public function it_returns_tag_names()
    {
        $this->assertEquals('Seller Name', $this->service->getTagName(1));
        $this->assertEquals('VAT Number', $this->service->getTagName(2));
        $this->assertEquals('Timestamp', $this->service->getTagName(3));
        $this->assertEquals('Total Amount', $this->service->getTagName(4));
        $this->assertEquals('VAT Total', $this->service->getTagName(5));
        $this->assertEquals('Unknown', $this->service->getTagName(99));
    }

    private function isValidBase64(string $data): bool
    {
        $decoded = base64_decode($data, true);
        return $decoded !== false && base64_encode($decoded) === $data;
    }
}
