<?php

declare(strict_types=1);

namespace SaudiZATCA\Services;

use SaudiZATCA\Exceptions\CertificateException;
use OpenSSLAsymmetricKey;

/**
 * Certificate Service
 *
 * Handles CSR generation, private key creation, and certificate management
 * according to ZATCA specifications (secp256k1, ECDSA).
 */
class CertificateService
{
    private const DEFAULT_CURVE = 'secp256k1';
    private const DEFAULT_INVOICE_TYPES = '1100';
    private const COUNTRY_CODE = 'SA';
    private const INDUSTRY = 'Industry';

    public function __construct(
        private readonly StorageService $storage,
        private readonly array $config,
        private readonly array $securityConfig
    ) {
    }

    /**
     * Generate CSR (Certificate Signing Request) and Private Key
     *
     * @param array<string, mixed> $merchantData
     * @return array{csr: string, private_key: string, csr_path: string, key_path: string}
     */
    public function generateCSR(array $merchantData = []): array
    {
        $this->validateMerchantData($merchantData);

        $curve = $this->securityConfig['ecc_curve'] ?? self::DEFAULT_CURVE;
        $invoiceTypes = $merchantData['invoice_types'] ?? $this->config['invoice_types'] ?? self::DEFAULT_INVOICE_TYPES;

        // Generate private key using secp256k1
        $privateKey = $this->generatePrivateKey($curve);

        // Extract private key PEM
        openssl_pkey_export($privateKey, $privateKeyPem);

        // Generate CSR with ZATCA-specific distinguished name
        $csr = $this->createCSR($privateKey, $merchantData, $invoiceTypes);

        // Extract CSR PEM
        openssl_csr_export($csr, $csrPem);

        // Save files
        $csrPath = $merchantData['csr_path'] ?? $this->getCertificatePath('csr');
        $keyPath = $merchantData['private_key_path'] ?? $this->getCertificatePath('private_key');

        $this->storage->put($csrPath, $csrPem);
        $this->storage->put($keyPath, $privateKeyPem);

        return [
            'csr' => $csrPem,
            'private_key' => $privateKeyPem,
            'csr_path' => $this->storage->fullPath($csrPath),
            'key_path' => $this->storage->fullPath($keyPath),
        ];
    }

    /**
     * Generate private key
     */
    private function generatePrivateKey(string $curve): OpenSSLAsymmetricKey
    {
        $keyPair = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => $curve,
        ]);

        if ($keyPair === false) {
            throw new CertificateException(
                'Failed to generate private key. Ensure OpenSSL supports EC keys with curve: ' . $curve
            );
        }

        return $keyPair;
    }

    /**
     * Create CSR with ZATCA-specific requirements
     *
     * @param OpenSSLAsymmetricKey $privateKey
     * @param array<string, mixed> $data
     * @param string $invoiceTypes
     */
    private function createCSR(OpenSSLAsymmetricKey $privateKey, array $data, string $invoiceTypes): mixed
    {
        $orgIdentifier = $data['organization_identifier'] ?? $data['vat_number'] ?? $this->config['vat_number'] ?? '';
        $serialNumber = $this->buildSerialNumber($data);
        $commonName = $data['common_name'] ?? $this->config['common_name'] ?? $orgIdentifier;
        $orgName = $data['organization'] ?? $this->config['organization'] ?? '';
        $orgUnit = $data['organization_unit'] ?? $this->config['organization_unit'] ?? '';
        $city = $data['city'] ?? $this->config['city'] ?? '';
        $state = $data['country_subdivision'] ?? $this->config['country_subdivision'] ?? '';
        $address = $this->buildAddress($data);
        $industry = $data['industry'] ?? $this->config['industry'] ?? self::INDUSTRY;

        // Build DN according to ZATCA specification using OIDs for maximum compatibility
        // ZATCA requires specific OIDs for organizationIdentifier and businessCategory
        $dn = [
            'CN' => $commonName,
            'C' => self::COUNTRY_CODE,
            'OU' => $orgUnit,
            'O' => $orgName,
            '2.5.4.15' => $industry, // businessCategory
            '2.5.4.97' => $orgIdentifier, // organizationIdentifier
            '2.5.4.5' => $serialNumber, // serialNumber
            'L' => $city,
            'ST' => $state,
            '2.5.4.26' => $address, // registeredAddress
        ];

        // Filter out empty values to avoid OpenSSL errors
        $dn = array_filter($dn, fn($value) => !empty($value));

        $config = [
            'digest_alg' => 'sha256',
            'req_extensions' => 'v3_req',
            'config' => $this->createTempConfig($invoiceTypes),
        ];

        $csr = openssl_csr_new($dn, $privateKey, $config);

        if ($csr === false) {
            throw new CertificateException(
                'Failed to generate CSR: ' . openssl_error_string()
            );
        }

        return $csr;
    }

    /**
     * Build serial number for CSR
     *
     * @param array<string, mixed> $data
     */
    private function buildSerialNumber(array $data): string
    {
        $solutionName = $data['solution_name'] ?? $this->config['solution_name'] ?? 'Laravel';
        $model = $data['model'] ?? '1';
        $deviceSerial = $data['device_serial'] ?? $data['serial_number'] ?? '0001';

        return sprintf('%s-%s-%s', $solutionName, $model, $deviceSerial);
    }

    /**
     * Build address string
     *
     * @param array<string, mixed> $data
     */
    private function buildAddress(array $data): string
    {
        $parts = array_filter([
            $data['street'] ?? $this->config['street'] ?? null,
            $data['building'] ?? $this->config['building'] ?? null,
            $data['city'] ?? $this->config['city'] ?? null,
            $data['district'] ?? $this->config['district'] ?? null,
            $data['postal_code'] ?? $this->config['postal_code'] ?? null,
        ]);

        return implode(', ', $parts);
    }

    /**
     * Create temporary OpenSSL config for CSR extensions
     */
    private function createTempConfig(string $invoiceTypes): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'zatca_csr_');

        $config = <<<CONFIG
[req]
distinguished_name = req_distinguished_name
req_extensions = v3_req
prompt = no

[req_distinguished_name]
C = SA
CN = dummy

[v3_req]
basicConstraints = CA:FALSE
keyUsage = digitalSignature, nonRepudiation, keyEncipherment
subjectAltName = @alt_names

[alt_names]
otherName = 1.3.6.1.4.1.311.20.2.3;UTF8:{$invoiceTypes}
CONFIG;

        file_put_contents($tempFile, $config);

        return $tempFile;
    }

    /**
     * Load certificate from storage
     */
    public function loadCertificate(string $type = 'compliance'): ?string
    {
        return $this->storage->get($this->getCertificatePath($type));
    }

    /**
     * Load private key from storage
     */
    public function loadPrivateKey(): ?string
    {
        return $this->storage->get($this->getCertificatePath('private_key'));
    }

    /**
     * Save certificate
     */
    public function saveCertificate(string $certificate, string $type = 'compliance'): string
    {
        $path = $this->getCertificatePath($type);

        // Ensure extension
        if (!str_ends_with(strtolower($path), '.pem')) {
            $path .= '.pem';
        }

        $this->storage->put($path, $certificate);

        return $this->storage->fullPath($path);
    }

    /**
     * Save private key
     */
    public function savePrivateKey(string $privateKey): string
    {
        $path = $this->getCertificatePath('private_key');
        $this->storage->put($path, $privateKey);

        return $this->storage->fullPath($path);
    }

    /**
     * Get the path for a certificate or key from configuration
     *
     * @param string $type The type (csr, private_key, compliance, production)
     * @return string Relative path from storage directory
     */
    public function getCertificatePath(string $type): string
    {
        return match ($type) {
            'csr' => $this->config['csr_path'] ?? 'zatca/certificates/csr.pem',
            'private_key', 'key' => $this->config['private_key_path'] ?? 'zatca/certificates/private.pem',
            'compliance' => $this->config['compliance_cert_path'] ?? 'zatca/certificates/compliance.pem',
            'production' => $this->config['production_cert_path'] ?? 'zatca/certificates/production.pem',
            default => $type,
        };
    }

    /**
     * Get certificate info
     */
    public function getCertificateInfo(string $certificate): array
    {
        $info = openssl_x509_parse($certificate);

        if ($info === false) {
            throw new CertificateException('Invalid certificate format');
        }

        return [
            'subject' => $info['subject'] ?? [],
            'issuer' => $info['issuer'] ?? [],
            'valid_from' => $info['validFrom_time_t'] ?? null,
            'valid_to' => $info['validTo_time_t'] ?? null,
            'serial_number' => $info['serialNumber'] ?? '',
            'fingerprint' => $info['fingerprint'] ?? [],
        ];
    }

    /**
     * Check if certificate is valid
     */
    public function isCertificateValid(string $certificate): bool
    {
        $info = openssl_x509_parse($certificate);

        if ($info === false) {
            return false;
        }

        $now = time();
        $validFrom = $info['validFrom_time_t'] ?? 0;
        $validTo = $info['validTo_time_t'] ?? 0;

        return $now >= $validFrom && $now <= $validTo;
    }

    /**
     * Get public key from certificate
     */
    public function getPublicKey(string $certificate): string
    {
        $pubKey = openssl_pkey_get_public($certificate);

        if ($pubKey === false) {
            throw new CertificateException('Failed to extract public key from certificate');
        }

        $pubKeyDetails = openssl_pkey_get_details($pubKey);

        if ($pubKeyDetails === false || !isset($pubKeyDetails['key'])) {
            throw new CertificateException('Failed to get public key details');
        }

        return $pubKeyDetails['key'];
    }

    /**
     * Extract the raw certificate signature for ZATCA QR tag 9.
     */
    public function extractCertificateSignature(string $certificate): string
    {
        $der = $this->pemCertificateToDer($certificate);
        $offset = 0;

        $certificateSequence = $this->readAsn1Element($der, $offset);
        $certificateContent = $certificateSequence['value'];
        $contentOffset = 0;

        // Certificate ::= SEQUENCE { tbsCertificate, signatureAlgorithm, signatureValue }
        $this->readAsn1Element($certificateContent, $contentOffset);
        $this->readAsn1Element($certificateContent, $contentOffset);
        $signatureValue = $this->readAsn1Element($certificateContent, $contentOffset);

        if ($signatureValue['tag'] !== 0x03 || $signatureValue['value'] === '') {
            throw new CertificateException('Failed to extract certificate signature');
        }

        // BIT STRING starts with an "unused bits" byte. X.509 signatures should use 0.
        return base64_encode(substr($signatureValue['value'], 1));
    }

    /**
     * Extract certificate body (without PEM headers)
     */
    public function extractCertificateBody(string $certificate): string
    {
        $cleaned = preg_replace('/-----BEGIN CERTIFICATE-----/', '', $certificate);
        $cleaned = preg_replace('/-----END CERTIFICATE-----/', '', $cleaned ?? '');
        $cleaned = preg_replace('/\s+/', '', $cleaned ?? '');

        return trim($cleaned ?? '');
    }

    private function pemCertificateToDer(string $certificate): string
    {
        $body = $this->extractCertificateBody($certificate);
        $der = base64_decode($body, true);

        if ($der === false) {
            throw new CertificateException('Invalid certificate body');
        }

        return $der;
    }

    /**
     * @return array{tag: int, value: string}
     */
    private function readAsn1Element(string $der, int &$offset): array
    {
        if ($offset + 2 > strlen($der)) {
            throw new CertificateException('Invalid ASN.1 certificate structure');
        }

        $tag = ord($der[$offset++]);
        $lengthByte = ord($der[$offset++]);

        if (($lengthByte & 0x80) === 0) {
            $length = $lengthByte;
        } else {
            $lengthBytes = $lengthByte & 0x7f;
            if ($lengthBytes === 0 || $offset + $lengthBytes > strlen($der)) {
                throw new CertificateException('Invalid ASN.1 length');
            }

            $length = 0;
            for ($i = 0; $i < $lengthBytes; $i++) {
                $length = ($length << 8) | ord($der[$offset++]);
            }
        }

        if ($offset + $length > strlen($der)) {
            throw new CertificateException('ASN.1 element exceeds certificate length');
        }

        $value = substr($der, $offset, $length);
        $offset += $length;

        return ['tag' => $tag, 'value' => $value];
    }

    /**
     * Extract private key body (without PEM headers)
     */
    public function extractPrivateKeyBody(string $privateKey): string
    {
        $cleaned = preg_replace('/-----BEGIN (?:EC )?PRIVATE KEY-----/', '', $privateKey);
        $cleaned = preg_replace('/-----END (?:EC )?PRIVATE KEY-----/', '', $cleaned ?? '');
        $cleaned = preg_replace('/\s+/', '', $cleaned ?? '');

        return trim($cleaned ?? '');
    }

    /**
     * Validate merchant data for CSR generation
     *
     * @param array<string, mixed> $data
     */
    private function validateMerchantData(array $data): void
    {
        $orgIdentifier = $data['organization_identifier'] ?? $data['vat_number'] ?? $this->config['organization'] ?? '';

        if (empty($orgIdentifier)) {
            throw new CertificateException('Organization identifier (VAT number) is required');
        }

        // VAT number must be 15 digits starting and ending with 3
        if (!preg_match('/^3\d{13}3$/', $orgIdentifier)) {
            throw new CertificateException(
                'VAT number must be 15 digits starting and ending with 3. Provided: ' . $orgIdentifier
            );
        }

        $commonName = $data['common_name'] ?? $this->config['common_name'] ?? '';
        if (empty($commonName)) {
            throw new CertificateException('Common name is required');
        }
    }

    /**
     * Clean up temporary files
     */
    public function __destruct()
    {
        // Clean up temp config files if needed
    }
}
