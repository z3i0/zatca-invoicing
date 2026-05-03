<?php

declare(strict_types=1);

namespace SaudiZATCA\Services;

use SaudiZATCA\Exceptions\APIException;
use Illuminate\Support\Facades\Log;

/**
 * ZATCA API Service
 * 
 * Handles all communication with ZATCA APIs across all environments:
 * - Sandbox: Development/testing
 * - Simulation: Pre-production testing
 * - Production: Live environment
 */
class ZatcaAPIService
{
    private const API_VERSION = 'V2';
    private const CONTENT_TYPE = 'application/json';
    
    private string $baseUrl;
    private array $credentials;
    
    public function __construct(
        private string $environment,
        private readonly array $apiConfig,
        private readonly array $loggingConfig = []
    ) {
        $this->baseUrl = rtrim($this->apiConfig[$environment]['base_url'] ?? '', '/');
        $this->credentials = $this->apiConfig[$environment] ?? [];
    }

    /**
     * Request Compliance CSID (Certificate Signing ID)
     * 
     * Step 1 in ZATCA onboarding process
     */
    public function requestComplianceCSID(string $csrContent, string $otp): array
    {
        $this->log('Requesting Compliance CSID...');
        
        $response = $this->request(
            method: 'POST',
            endpoint: '/compliance',
            body: [
                'csr' => base64_encode($csrContent),
            ],
            headers: [
                'OTP: ' . $otp,
            ],
            useBasicAuth: true
        );
        
        $this->log('Compliance CSID received successfully');
        
        return $this->parseComplianceResponse($response);
    }

    /**
     * Check invoice compliance
     * 
     * Step 2: Submit test invoices for compliance validation
     */
    public function checkInvoiceCompliance(
        string $signedInvoice,
        string $invoiceHash,
        string $uuid,
        string $certificate,
        string $secret
    ): array {
        $this->log('Checking invoice compliance...');
        
        $response = $this->request(
            method: 'POST',
            endpoint: '/compliance/invoices',
            body: [
                'invoiceHash' => $invoiceHash,
                'uuid' => $uuid,
                'invoice' => base64_encode($signedInvoice),
            ],
            headers: $this->buildAuthHeaders($certificate, $secret)
        );
        
        return $this->parseApiResponse($response);
    }

    /**
     * Request Production CSID
     * 
     * Step 3: After passing compliance tests
     */
    public function requestProductionCSID(
        string $complianceRequestId,
        string $certificate,
        string $secret
    ): array {
        $this->log('Requesting Production CSID...');
        
        $response = $this->request(
            method: 'POST',
            endpoint: '/production/csids',
            body: [
                'compliance_request_id' => $complianceRequestId,
            ],
            headers: $this->buildAuthHeaders($certificate, $secret)
        );
        
        $this->log('Production CSID received successfully');
        
        return $this->parseComplianceResponse($response);
    }

    /**
     * Renew CSID (Compliance or Production)
     */
    public function renewCSID(
        string $csrContent,
        string $certificate,
        string $secret
    ): array {
        $this->log('Renewing CSID...');
        
        $response = $this->request(
            method: 'PATCH',
            endpoint: '/csids',
            body: [
                'csr' => base64_encode($csrContent),
            ],
            headers: $this->buildAuthHeaders($certificate, $secret)
        );
        
        return $this->parseComplianceResponse($response);
    }

    /**
     * Report Invoice (for simplified/B2C invoices)
     * 
     * Submit invoice to ZATCA reporting endpoint
     */
    public function reportInvoice(
        string $signedInvoice,
        string $invoiceHash,
        string $uuid,
        string $certificate,
        string $secret,
        ?string $clearanceStatus = null
    ): array {
        $this->log("Reporting invoice {$uuid}...");
        
        $body = [
            'invoiceHash' => $invoiceHash,
            'uuid' => $uuid,
            'invoice' => base64_encode($signedInvoice),
        ];
        
        if ($clearanceStatus) {
            $body['clearanceStatus'] = $clearanceStatus;
        }
        
        $response = $this->request(
            method: 'POST',
            endpoint: '/invoices/reporting/' . self::API_VERSION,
            body: $body,
            headers: $this->buildAuthHeaders($certificate, $secret)
        );
        
        return $this->parseApiResponse($response);
    }

    /**
     * Clear Invoice (for standard/B2B invoices)
     * 
     * Submit invoice to ZATCA clearance endpoint
     */
    public function clearInvoice(
        string $signedInvoice,
        string $invoiceHash,
        string $uuid,
        string $certificate,
        string $secret
    ): array {
        $this->log("Clearing invoice {$uuid}...");
        
        $response = $this->request(
            method: 'POST',
            endpoint: '/invoices/clearance/' . self::API_VERSION,
            body: [
                'invoiceHash' => $invoiceHash,
                'uuid' => $uuid,
                'invoice' => base64_encode($signedInvoice),
            ],
            headers: $this->buildAuthHeaders($certificate, $secret)
        );
        
        return $this->parseApiResponse($response);
    }

    /**
     * Get invoice status
     */
    public function getInvoiceStatus(string $uuid, string $certificate, string $secret): array
    {
        $this->log("Getting invoice status for {$uuid}...");
        
        return $this->request(
            method: 'GET',
            endpoint: '/invoices/' . $uuid . '/status',
            headers: $this->buildAuthHeaders($certificate, $secret)
        );
    }

    /**
     * Get API status/health check
     */
    public function getStatus(): array
    {
        return $this->request(
            method: 'GET',
            endpoint: '/status',
            useBasicAuth: true
        );
    }

    /**
     * Make HTTP request to ZATCA API
     * 
     * @param array<string, mixed>|null $body
     * @param string[] $headers
     */
    private function request(
        string $method,
        string $endpoint,
        ?array $body = null,
        array $headers = [],
        bool $useBasicAuth = false
    ): array {
        $url = $this->baseUrl . $endpoint;
        
        $ch = curl_init();
        
        // Set basic options
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->environment === 'production');
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->apiConfig['timeout'] ?? 30);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        
        // Build headers
        $requestHeaders = [
            'Content-Type: ' . self::CONTENT_TYPE,
            'Accept: application/json',
            'Accept-Version: ' . self::API_VERSION,
        ];
        
        // Add authentication
        if ($useBasicAuth && !empty($this->credentials['username']) && !empty($this->credentials['password'])) {
            curl_setopt($ch, CURLOPT_USERPWD, $this->credentials['username'] . ':' . $this->credentials['password']);
        }
        
        // Add custom headers
        foreach ($headers as $header) {
            $requestHeaders[] = $header;
        }
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, $requestHeaders);
        
        // Set method and body
        match (strtoupper($method)) {
            'POST' => $this->setPostOptions($ch, $body),
            'PATCH' => $this->setPatchOptions($ch, $body),
            'PUT' => $this->setPutOptions($ch, $body),
            'DELETE' => curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE'),
            default => curl_setopt($ch, CURLOPT_HTTPGET, true),
        };
        
        // Log request if enabled
        if ($this->shouldLog('requests')) {
            $this->logRequest($method, $url, $body);
        }
        
        // Execute request
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($response === false) {
            throw new APIException(
                message: 'CURL Error: ' . $error,
                statusCode: 0
            );
        }
        
        // Log response if enabled
        if ($this->shouldLog('responses')) {
            $this->logResponse($httpCode, $response);
        }
        
        // Parse response
        $decoded = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Try to handle XML responses
            if (str_starts_with(trim($response), '<')) {
                return [
                    'status' => $httpCode,
                    'body' => $response,
                    'headers' => [],
                ];
            }
            
            throw new APIException(
                message: 'Invalid JSON response from ZATCA API',
                statusCode: $httpCode,
                responseBody: $response
            );
        }
        
        // Check for API errors
        if ($httpCode >= 400) {
            $errorMessage = $decoded['message'] ?? $decoded['error'] ?? 'Unknown API error';
            $errors = $decoded['errors'] ?? $decoded['validationResults'] ?? null;
            
            throw new APIException(
                message: "ZATCA API Error ({$httpCode}): {$errorMessage}",
                statusCode: $httpCode,
                responseBody: $response,
                details: is_array($errors) ? $errors : null
            );
        }
        
        return [
            'status' => $httpCode,
            'data' => $decoded,
            'raw' => $response,
        ];
    }

    /**
     * Set POST options
     * 
     * @param array<string, mixed>|null $body
     */
    private function setPostOptions(\CurlHandle $ch, ?array $body): void
    {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
    }

    /**
     * Set PATCH options
     * 
     * @param array<string, mixed>|null $body
     */
    private function setPatchOptions(\CurlHandle $ch, ?array $body): void
    {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
    }

    /**
     * Set PUT options
     * 
     * @param array<string, mixed>|null $body
     */
    private function setPutOptions(\CurlHandle $ch, ?array $body): void
    {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
    }

    /**
     * Build authentication headers using certificate token
     */
    private function buildAuthHeaders(string $certificate, string $secret): array
    {
        $certBody = $this->extractCertBody($certificate);
        
        return [
            'Authorization: Basic ' . base64_encode($certBody . ':' . $secret),
            'Content-Type: ' . self::CONTENT_TYPE,
        ];
    }

    /**
     * Extract certificate body without PEM headers
     */
    private function extractCertBody(string $certificate): string
    {
        $cleaned = preg_replace('/-----BEGIN CERTIFICATE-----/', '', $certificate);
        $cleaned = preg_replace('/-----END CERTIFICATE-----/', '', $cleaned ?? '');
        $cleaned = preg_replace('/\s+/', '', $cleaned ?? '');
        
        return trim($cleaned ?? '');
    }

    /**
     * Parse compliance response
     */
    private function parseComplianceResponse(array $response): array
    {
        $data = $response['data'] ?? [];
        
        return [
            'certificate' => $data['binarySecurityToken'] ?? $data['certificate'] ?? '',
            'secret' => $data['secret'] ?? '',
            'request_id' => $data['requestID'] ?? '',
            'token_type' => $data['tokenType'] ?? 'Basic',
            'status' => $response['status'],
            'raw' => $response['raw'] ?? null,
        ];
    }

    /**
     * Parse general API response
     */
    private function parseApiResponse(array $response): array
    {
        $data = $response['data'] ?? [];
        
        return [
            'status' => $response['status'],
            'is_valid' => ($response['status'] === 200 || $response['status'] === 202),
            'warnings' => $data['warningMessages'] ?? [],
            'errors' => $data['errorMessages'] ?? [],
            'validation_results' => $data['validationResults'] ?? null,
            'cleared_invoice' => $data['clearedInvoice'] ?? null,
            'raw' => $response['raw'] ?? null,
        ];
    }

    /**
     * Check if logging is enabled for specific type
     */
    private function shouldLog(string $type): bool
    {
        if (!($this->loggingConfig['enabled'] ?? false)) {
            return false;
        }
        
        return match ($type) {
            'requests' => $this->loggingConfig['log_api_requests'] ?? true,
            'responses' => $this->loggingConfig['log_api_responses'] ?? true,
            default => true,
        };
    }

    /**
     * Log message
     */
    private function log(string $message, string $level = 'debug'): void
    {
        if (!($this->loggingConfig['enabled'] ?? false)) {
            return;
        }
        
        $channel = $this->loggingConfig['channel'] ?? 'zatca';
        Log::channel($channel)->{$level}('[ZATCA] ' . $message);
    }

    /**
     * Log request details
     * 
     * @param array<string, mixed>|null $body
     */
    private function logRequest(string $method, string $url, ?array $body): void
    {
        $logData = [
            'method' => $method,
            'url' => $url,
        ];
        
        if ($body !== null && ($this->loggingConfig['log_sensitive_data'] ?? false)) {
            $logData['body'] = $body;
        }
        
        $this->log('Request: ' . json_encode($logData));
    }

    /**
     * Log response details
     */
    private function logResponse(int $statusCode, string $response): void
    {
        $this->log("Response ({$statusCode}): " . substr($response, 0, 1000));
    }

    /**
     * Get current environment
     */
    public function getEnvironment(): string
    {
        return $this->environment;
    }

    /**
     * Set environment dynamically
     */
    public function setEnvironment(string $environment): void
    {
        $this->environment = $environment;
        $this->baseUrl = rtrim($this->apiConfig[$environment]['base_url'] ?? '', '/');
        $this->credentials = $this->apiConfig[$environment] ?? [];
    }

    /**
     * Get base URL
     */
    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }
}
