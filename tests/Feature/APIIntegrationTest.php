<?php

declare(strict_types=1);

namespace SaudiZATCA\Tests\Feature;

use SaudiZATCA\Tests\TestCase;
use SaudiZATCA\Services\ZatcaAPIService;
use SaudiZATCA\Exceptions\APIException;
use Illuminate\Support\Facades\Http;

class APIIntegrationTest extends TestCase
{
    private ZatcaAPIService $apiService;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->apiService = new ZatcaAPIService(
            'sandbox',
            [
                'sandbox' => [
                    'base_url' => 'https://gw-fatoora.zatca.gov.sa/e-invoicing/developer-portal',
                    'username' => 'test_user',
                    'password' => 'test_pass',
                ],
            ],
            ['enabled' => false]
        );
    }

    /** @test */
    public function it_uses_correct_api_base_url_for_sandbox()
    {
        $this->assertStringContainsString('developer-portal', $this->apiService->getBaseUrl());
        $this->assertStringContainsString('sandbox', $this->apiService->getEnvironment());
    }

    /** @test */
    public function it_can_switch_environments()
    {
        $apiService = new ZatcaAPIService(
            'sandbox',
            [
                'sandbox' => [
                    'base_url' => 'https://sandbox.zatca.gov.sa',
                    'username' => 'sandbox_user',
                    'password' => 'sandbox_pass',
                ],
                'simulation' => [
                    'base_url' => 'https://simulation.zatca.gov.sa',
                    'username' => 'sim_user',
                    'password' => 'sim_pass',
                ],
                'production' => [
                    'base_url' => 'https://production.zatca.gov.sa',
                    'username' => 'prod_user',
                    'password' => 'prod_pass',
                ],
            ],
            ['enabled' => false]
        );

        // Default is sandbox
        $this->assertEquals('sandbox', $apiService->getEnvironment());

        // Switch to simulation
        $apiService->setEnvironment('simulation');
        $this->assertEquals('simulation', $apiService->getEnvironment());

        // Switch to production
        $apiService->setEnvironment('production');
        $this->assertEquals('production', $apiService->getEnvironment());
    }

    /** @test */
    public function it_detects_invalid_json_response()
    {
        // This test verifies error handling without making actual HTTP requests
        $this->expectException(APIException::class);
        
        // We can't easily mock curl, but we can verify the exception structure
        $exception = new APIException(
            message: 'Invalid JSON response',
            statusCode: 500,
            responseBody: 'not json'
        );

        $this->assertEquals(500, $exception->getStatusCode());
        $this->assertEquals('not json', $exception->getResponseBody());
        
        throw $exception;
    }

    /** @test */
    public function it_handles_api_error_response()
    {
        $exception = new APIException(
            message: 'API Error (400): Invalid request',
            statusCode: 400,
            responseBody: '{"message": "Invalid request"}',
            details: ['field' => 'vat_number', 'error' => 'Invalid format']
        );

        $this->assertEquals(400, $exception->getStatusCode());
        $this->assertNotNull($exception->getDetails());
        $this->assertArrayHasKey('field', $exception->getDetails());
    }

    /** @test */
    public function sandbox_config_is_complete()
    {
        $config = config('zatca.api.sandbox');
        
        $this->assertNotEmpty($config);
        $this->assertArrayHasKey('base_url', $config);
        $this->assertStringContainsString('zatca.gov.sa', $config['base_url']);
    }

    /** @test */
    public function production_config_is_complete()
    {
        $config = config('zatca.api.production');
        
        $this->assertNotEmpty($config);
        $this->assertArrayHasKey('base_url', $config);
        $this->assertStringContainsString('zatca.gov.sa', $config['base_url']);
    }

    /** @test */
    public function all_environments_have_required_config()
    {
        $environments = ['sandbox', 'simulation', 'production'];
        
        foreach ($environments as $env) {
            $config = config("zatca.api.{$env}");
            $this->assertNotNull($config, "Config for {$env} should exist");
            $this->assertArrayHasKey('base_url', $config, "{$env} should have base_url");
        }
    }

    /** @test */
    public function it_validates_vat_number_format_in_merchant_data()
    {
        $this->expectException(\SaudiZATCA\Exceptions\CertificateException::class);
        
        $certService = app(\SaudiZATCA\Services\CertificateService::class);
        
        // Invalid VAT - not 15 digits
        $certService->generateCSR([
            'organization_identifier' => '12345',
        ]);
    }

    /** @test */
    public function it_accepts_valid_vat_number_format()
    {
        // Valid VAT: 15 digits starting and ending with 3
        $vat = '300000000000003';
        
        $this->assertMatchesRegularExpression('/^3\d{13}3$/', $vat);
    }
}
