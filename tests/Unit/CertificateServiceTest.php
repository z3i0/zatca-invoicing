<?php

declare(strict_types=1);

namespace SaudiZATCA\Tests\Unit;

use SaudiZATCA\Tests\TestCase;
use SaudiZATCA\Services\CertificateService;
use SaudiZATCA\Services\StorageService;
use SaudiZATCA\Exceptions\CertificateException;

class CertificateServiceTest extends TestCase
{
    private CertificateService $service;
    private StorageService $storage;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->storage = new StorageService(sys_get_temp_dir() . '/zatca_test_' . uniqid());
        $this->service = new CertificateService(
            $this->storage,
            [
                'organization' => 'Test Company',
                'common_name' => 'Test Company',
                'organization_unit' => 'IT Department',
                'street' => 'Test Street',
                'city' => 'Riyadh',
                'invoice_types' => '1100',
            ],
            [
                'ecc_curve' => 'secp256k1',
            ]
        );
    }

    protected function tearDown(): void
    {
        // Cleanup temp files
        $path = $this->storage->fullPath('');
        if (is_dir($path)) {
            array_map('unlink', glob($path . '/*'));
            rmdir($path);
        }
        parent::tearDown();
    }

    /** @test */
    public function it_generates_csr_and_private_key()
    {
        $result = $this->service->generateCSR([
            'organization_identifier' => '300000000000003',
        ]);

        $this->assertArrayHasKey('csr', $result);
        $this->assertArrayHasKey('private_key', $result);
        $this->assertArrayHasKey('csr_path', $result);
        $this->assertArrayHasKey('key_path', $result);
        
        $this->assertStringContainsString('BEGIN CERTIFICATE REQUEST', $result['csr']);
        $this->assertStringContainsString('BEGIN PRIVATE KEY', $result['private_key']);
    }

    /** @test */
    public function it_validates_vat_number_format()
    {
        $this->expectException(CertificateException::class);
        $this->expectExceptionMessage('VAT number must be 15 digits');

        $this->service->generateCSR([
            'organization_identifier' => 'INVALID_VAT',
        ]);
    }

    /** @test */
    public function it_requires_vat_number()
    {
        $this->expectException(CertificateException::class);
        $this->expectExceptionMessage('VAT number');

        $service = new CertificateService(
            $this->storage,
            ['organization' => ''],
            []
        );

        $service->generateCSR([]);
    }

    /** @test */
    public function it_saves_and_loads_certificate()
    {
        $certContent = "-----BEGIN CERTIFICATE-----\nTEST_CERT\n-----END CERTIFICATE-----";
        
        $path = $this->service->saveCertificate($certContent, 'compliance');
        
        $this->assertFileExists($path);
        
        $loaded = $this->service->loadCertificate('compliance');
        $this->assertEquals($certContent, $loaded);
    }

    /** @test */
    public function it_extracts_certificate_body()
    {
        $cert = "-----BEGIN CERTIFICATE-----\nABC123\nDEF456\n-----END CERTIFICATE-----";
        
        $body = $this->service->extractCertificateBody($cert);
        
        $this->assertEquals('ABC123DEF456', $body);
    }

    /** @test */
    public function it_checks_certificate_validity()
    {
        // Invalid certificate
        $this->assertFalse($this->service->isCertificateValid('invalid'));
        
        // Valid certificate (self-signed for test)
        $csrResult = $this->service->generateCSR([
            'organization_identifier' => '300000000000003',
        ]);
        
        $cert = openssl_csr_sign($csrResult['csr'], null, $csrResult['private_key'], 365);
        openssl_x509_export($cert, $certPem);
        
        $this->assertTrue($this->service->isCertificateValid($certPem));
    }

    /** @test */
    public function it_generates_valid_csr_structure()
    {
        $result = $this->service->generateCSR([
            'organization_identifier' => '300000000000003',
            'device_serial' => '0001',
        ]);

        $csrInfo = openssl_csr_get_subject($result['csr']);
        
        $this->assertArrayHasKey('UID', $csrInfo);
        $this->assertEquals('300000000000003', $csrInfo['UID']);
        $this->assertArrayHasKey('C', $csrInfo);
        $this->assertEquals('SA', $csrInfo['C']);
    }

    /** @test */
    public function it_extracts_public_key_from_certificate()
    {
        $csrResult = $this->service->generateCSR([
            'organization_identifier' => '300000000000003',
        ]);
        
        $cert = openssl_csr_sign($csrResult['csr'], null, $csrResult['private_key'], 365);
        openssl_x509_export($cert, $certPem);
        
        $publicKey = $this->service->getPublicKey($certPem);
        
        $this->assertStringContainsString('BEGIN PUBLIC KEY', $publicKey);
    }
}
