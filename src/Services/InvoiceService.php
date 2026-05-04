<?php

declare(strict_types=1);

namespace SaudiZATCA\Services;

use SaudiZATCA\Data\InvoiceData;
use SaudiZATCA\Data\SellerData;
use SaudiZATCA\Data\BuyerData;
use SaudiZATCA\Exceptions\InvoiceException;
use SaudiZATCA\Exceptions\APIException;
use Illuminate\Support\Facades\Log;

/**
 * Invoice Service
 *
 * Main orchestrator for invoice operations:
 * - Generate XML invoices
 * - Digitally sign invoices
 * - Generate QR codes
 * - Submit to ZATCA (reporting/clearance)
 */
class InvoiceService
{
    private array $loggingConfig;

    public function __construct(
        private readonly XMLGeneratorService $xmlGenerator,
        private readonly QRCodeService $qrService,
        private readonly CertificateService $certService,
        private readonly ZatcaAPIService $apiService,
        private readonly StorageService $storage,
        private readonly array $config,
        array $loggingConfig = []
    ) {
        $this->loggingConfig = $loggingConfig;
    }

    /**
     * Process complete invoice lifecycle
     *
     * 1. Generate XML
     * 2. Sign invoice
     * 3. Generate QR code
     * 4. Submit to ZATCA
     */
    public function processInvoice(InvoiceData $invoice, SellerData $seller, ?BuyerData $buyer = null): array
    {
        $this->log('Starting invoice processing for: ' . $invoice->invoiceNumber);

        try {
            // Step 1: Generate XML
            $xml = $this->generateXML($invoice, $seller, $buyer);

            // Step 2: Sign invoice
            $signedResult = $this->signInvoice($xml, $invoice);
            $signedXml = $signedResult['signed_xml'];
            $invoiceHash = $signedResult['invoice_hash'];

            // Step 3: Generate QR code
            $qrData = $this->generateQRCode($seller, $invoice, $invoiceHash, $signedResult);
            $signedXml = $this->embedQRCode($signedXml, $qrData);

            // Step 4: Submit to ZATCA
            $submissionResult = $this->submitToZatca($signedXml, $invoiceHash, $invoice);

            $result = [
                'success' => true,
                'invoice_number' => $invoice->invoiceNumber,
                'uuid' => $invoice->getUuid(),
                'invoice_hash' => $invoiceHash,
                'signed_xml' => $signedXml,
                'qr_code' => $qrData,
                'submission' => $submissionResult,
            ];

            $this->log('Invoice processing completed: ' . $invoice->invoiceNumber);

            return $result;
        } catch (\Throwable $e) {
            $this->log('Invoice processing failed: ' . $e->getMessage(), 'error');
            throw new InvoiceException(
                message: 'Invoice processing failed: ' . $e->getMessage(),
                code: $e->getCode(),
                previous: $e
            );
        }
    }

    /**
     * Generate XML invoice
     */
    public function generateXML(InvoiceData $invoice, SellerData $seller, ?BuyerData $buyer = null): string
    {
        $this->log('Generating XML for invoice: ' . $invoice->invoiceNumber);

        $xml = $this->xmlGenerator->generate($invoice, $seller, $buyer);

        // Save debug XML if enabled
        if ($this->config['debug']['save_xml'] ?? false) {
            $debugPath = ($this->config['debug']['path'] ?? 'zatca/debug') . '/' . $invoice->invoiceNumber . '_unsigned.xml';
            $this->storage->put($debugPath, $xml);
        }

        return $xml;
    }

    /**
     * Sign invoice with digital signature
     *
     * @return array{signed_xml: string, invoice_hash: string, signature: string}
     */
    public function signInvoice(string $xml, InvoiceData $invoice): array
    {
        $this->log('Signing invoice: ' . $invoice->invoiceNumber);

        // Load certificate and private key
        $certificate = $this->certService->loadCertificate($this->getCertType());
        $privateKey = $this->certService->loadPrivateKey();

        if (!$certificate || !$privateKey) {
            throw new InvoiceException(
                'Certificate or private key not found. Please complete onboarding first.'
            );
        }

        // Calculate invoice hash
        $invoiceHash = $this->xmlGenerator->calculateHash($xml);

        // Create digital signature
        $signatureResult = $this->createSignature($xml, $privateKey, $certificate, $invoiceHash);
        $signature = $signatureResult['signature'];

        // Embed signature in XML
        $signedXml = $this->embedSignature(
            $xml,
            $signature,
            $certificate,
            $invoiceHash,
            $signatureResult['signed_properties_digest']
        );

        // Save debug signed XML if enabled
        if ($this->config['debug']['save_xml'] ?? false) {
            $debugPath = ($this->config['debug']['path'] ?? 'zatca/debug') . '/' . $invoice->invoiceNumber . '_signed.xml';
            $this->storage->put($debugPath, $signedXml);
        }

        return [
            'signed_xml' => $signedXml,
            'invoice_hash' => $invoiceHash,
            'signature' => $signature,
        ];
    }

    /**
     * Generate QR code for invoice
     */
    public function generateQRCode(SellerData $seller, InvoiceData $invoice, string $invoiceHash, array $signedResult): string
    {
        $this->log('Generating QR code for invoice: ' . $invoice->invoiceNumber);

        $certificate = $this->certService->loadCertificate($this->getCertType());

        if (!$certificate) {
            // Phase 1 QR (no certificate)
            return $this->qrService->generatePhase1QR(
                $seller,
                $invoice->totalAmount(),
                $invoice->totalTax(),
                $invoice->issueDate
            );
        }

        // Phase 2 QR (with digital signature)
        $publicKey = $this->certService->getPublicKey($certificate);
        $certificateSignature = $invoice->needsReporting()
            ? $this->certService->extractCertificateSignature($certificate)
            : null;

        return $this->qrService->generatePhase2QR(
            $seller,
            $invoice,
            $invoiceHash,
            $signedResult['signature'],
            $publicKey,
            $invoice->issueDate,
            $certificateSignature
        );
    }

    /**
     * Submit invoice to ZATCA
     *
     * Automatically determines reporting vs clearance based on invoice type
     */
    public function submitToZatca(string $signedXml, string $invoiceHash, InvoiceData $invoice): array
    {
        $certificate = $this->certService->loadCertificate($this->getCertType());
        $secret = $this->getSecret();

        if (!$certificate || !$secret) {
            throw new InvoiceException(
                'Cannot submit invoice: Missing certificate or API secret. Complete onboarding first.'
            );
        }

        $uuid = $invoice->getUuid();

        if ($invoice->needsClearance()) {
            return $this->clearInvoice($signedXml, $invoiceHash, $uuid, $certificate, $secret);
        }

        return $this->reportInvoice($signedXml, $invoiceHash, $uuid, $certificate, $secret);
    }

    /**
     * Report simplified invoice (B2C)
     */
    public function reportInvoice(
        string $signedXml,
        string $invoiceHash,
        string $uuid,
        string $certificate,
        string $secret
    ): array {
        $this->log('Reporting invoice: ' . $uuid);

        return $this->apiService->reportInvoice($signedXml, $invoiceHash, $uuid, $certificate, $secret);
    }

    /**
     * Clear standard invoice (B2B)
     */
    public function clearInvoice(
        string $signedXml,
        string $invoiceHash,
        string $uuid,
        string $certificate,
        string $secret
    ): array {
        $this->log('Clearing invoice: ' . $uuid);

        return $this->apiService->clearInvoice($signedXml, $invoiceHash, $uuid, $certificate, $secret);
    }

    /**
     * Create digital signature
     */
    /**
     * @return array{signature: string, signed_properties_digest: string}
     */
    private function createSignature(
        string $xml,
        string $privateKey,
        string $certificate,
        string $invoiceHash
    ): array {
        // Load private key
        $key = openssl_pkey_get_private($privateKey);

        if ($key === false) {
            throw new InvoiceException('Failed to load private key for signing');
        }

        $signedProperties = $this->buildSignedPropertiesXML($certificate);
        $signedPropertiesDigest = $this->digestXml($signedProperties);
        $signedInfo = $this->buildSignedInfoXML($invoiceHash, $signedPropertiesDigest);

        $signature = '';
        $result = openssl_sign(
            $this->xmlGenerator->canonicalize($signedInfo),
            $signature,
            $key,
            'sha256'
        );

        if (!$result) {
            throw new InvoiceException('Failed to create digital signature: ' . openssl_error_string());
        }

        return [
            'signature' => base64_encode($signature),
            'signed_properties_digest' => $signedPropertiesDigest,
        ];
    }

    /**
     * Embed signature in XML
     */
    private function embedSignature(
        string $xml,
        string $signature,
        string $certificate,
        string $invoiceHash,
        string $signedPropertiesDigest
    ): string {
        $doc = new \DOMDocument();
        $doc->loadXML($xml);

        // Find UBLExtensions element
        $extensions = $doc->getElementsByTagNameNS(
            'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2',
            'ExtensionContent'
        )->item(0);

        if ($extensions) {
            // Create signature element
            while ($extensions->firstChild) {
                $extensions->removeChild($extensions->firstChild);
            }

            $sigDoc = new \DOMDocument();
            $sigDoc->loadXML($this->buildSignatureXML(
                $signature,
                $certificate,
                $invoiceHash,
                $signedPropertiesDigest
            ));

            $imported = $doc->importNode($sigDoc->documentElement, true);
            $extensions->appendChild($imported);
        }

        return $doc->saveXML();
    }

    /**
     * Embed the generated QR TLV payload in the signed XML.
     */
    public function embedQRCode(string $xml, string $qrData): string
    {
        $doc = new \DOMDocument();
        $doc->loadXML($xml);
        $root = $doc->documentElement;
        $qrRef = $doc->createElementNS(
            'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
            'cac:AdditionalDocumentReference'
        );

        $qrId = $doc->createElementNS(
            'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
            'cbc:ID',
            'QR'
        );
        $qrRef->appendChild($qrId);

        $attachment = $doc->createElementNS(
            'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
            'cac:Attachment'
        );

        $qrData = $doc->createElementNS(
            'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
            'cbc:EmbeddedDocumentBinaryObject',
            $qrData
        );
        $qrData->setAttribute('mimeCode', 'text/plain');
        $attachment->appendChild($qrData);
        $qrRef->appendChild($attachment);

        // Insert after first AdditionalDocumentReference (PIH)
        $pihRefs = $doc->getElementsByTagNameNS(
            'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
            'AdditionalDocumentReference'
        );

        if ($pihRefs->length > 0) {
            $lastPih = $pihRefs->item($pihRefs->length - 1);
            $lastPih?->parentNode?->insertBefore($qrRef, $lastPih->nextSibling);
        }

        return $doc->saveXML();
    }

    /**
     * Build signature XML block
     */
    private function buildSignatureXML(
        string $signature,
        string $certificate,
        string $invoiceHash,
        string $signedPropertiesDigest
    ): string {
        $certBody = $this->certService->extractCertificateBody($certificate);
        $signedInfo = $this->buildSignedInfoXML($invoiceHash, $signedPropertiesDigest);
        $signedProperties = $this->buildSignedPropertiesXML($certificate);

        return <<<XML
<sig:UBLDocumentSignatures
    xmlns:sig="urn:oasis:names:specification:ubl:schema:xsd:CommonSignatureComponents-2"
    xmlns:sac="urn:oasis:names:specification:ubl:schema:xsd:SignatureAggregateComponents-2"
    xmlns:sbc="urn:oasis:names:specification:ubl:schema:xsd:SignatureBasicComponents-2"
    xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2"
    xmlns:ds="http://www.w3.org/2000/09/xmldsig#"
    xmlns:xades="http://uri.etsi.org/01903/v1.3.2#">
    <sac:SignatureInformation>
        <cbc:ID>urn:oasis:names:specification:ubl:signature:1</cbc:ID>
        <sbc:ReferencedSignatureID>urn:oasis:names:specification:ubl:signature:Invoice</sbc:ReferencedSignatureID>
        <ds:Signature Id="signature">
            {$signedInfo}
            <ds:SignatureValue>{$signature}</ds:SignatureValue>
            <ds:KeyInfo>
                <ds:X509Data>
                    <ds:X509Certificate>{$certBody}</ds:X509Certificate>
                </ds:X509Data>
            </ds:KeyInfo>
            <ds:Object>{$signedProperties}</ds:Object>
        </ds:Signature>
    </sac:SignatureInformation>
</sig:UBLDocumentSignatures>
XML;
    }

    private function buildSignedInfoXML(string $invoiceHash, string $signedPropertiesDigest): string
    {
        return <<<XML
<ds:SignedInfo
    xmlns:ds="http://www.w3.org/2000/09/xmldsig#"
    xmlns:ext="urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2"
    xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"
    xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">
    <ds:CanonicalizationMethod Algorithm="http://www.w3.org/2006/12/xml-c14n11"/>
    <ds:SignatureMethod Algorithm="http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha256"/>
    <ds:Reference Id="invoiceSignedData" URI="">
        <ds:Transforms>
            <ds:Transform Algorithm="http://www.w3.org/2000/09/xmldsig#enveloped-signature"/>
            <ds:Transform Algorithm="http://www.w3.org/TR/1999/REC-xpath-19991116">
                <ds:XPath>not(ancestor-or-self::ext:UBLExtensions)</ds:XPath>
            </ds:Transform>
            <ds:Transform Algorithm="http://www.w3.org/TR/1999/REC-xpath-19991116">
                <ds:XPath>not(ancestor-or-self::cac:AdditionalDocumentReference[cbc:ID='QR'])</ds:XPath>
            </ds:Transform>
        </ds:Transforms>
        <ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>
        <ds:DigestValue>{$invoiceHash}</ds:DigestValue>
    </ds:Reference>
    <ds:Reference Type="http://www.w3.org/2000/09/xmldsig#SignatureProperties" URI="#xadesSignedProperties">
        <ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>
        <ds:DigestValue>{$signedPropertiesDigest}</ds:DigestValue>
    </ds:Reference>
</ds:SignedInfo>
XML;
    }

    private function buildSignedPropertiesXML(string $certificate): string
    {
        $certBody = $this->certService->extractCertificateBody($certificate);
        $certDigest = base64_encode(hash('sha256', base64_decode($certBody, true) ?: '', true));
        $certInfo = $this->certService->getCertificateInfo($certificate);
        $issuerParts = [];

        foreach (($certInfo['issuer'] ?? []) as $key => $value) {
            $issuerParts[] = "{$key}={$value}";
        }

        $issuerName = htmlspecialchars(implode(', ', $issuerParts), ENT_XML1);
        $serialNumber = htmlspecialchars((string) ($certInfo['serial_number'] ?? ''), ENT_XML1);
        $signingTime = gmdate('Y-m-d\TH:i:s\Z');

        return <<<XML
<xades:QualifyingProperties xmlns:xades="http://uri.etsi.org/01903/v1.3.2#" Target="#signature">
    <xades:SignedProperties Id="xadesSignedProperties">
        <xades:SignedSignatureProperties>
            <xades:SigningTime>{$signingTime}</xades:SigningTime>
            <xades:SigningCertificate>
                <xades:Cert>
                    <xades:CertDigest>
                        <ds:DigestMethod xmlns:ds="http://www.w3.org/2000/09/xmldsig#" Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>
                        <ds:DigestValue xmlns:ds="http://www.w3.org/2000/09/xmldsig#">{$certDigest}</ds:DigestValue>
                    </xades:CertDigest>
                    <xades:IssuerSerial>
                        <ds:X509IssuerName xmlns:ds="http://www.w3.org/2000/09/xmldsig#">{$issuerName}</ds:X509IssuerName>
                        <ds:X509SerialNumber xmlns:ds="http://www.w3.org/2000/09/xmldsig#">{$serialNumber}</ds:X509SerialNumber>
                    </xades:IssuerSerial>
                </xades:Cert>
            </xades:SigningCertificate>
        </xades:SignedSignatureProperties>
    </xades:SignedProperties>
</xades:QualifyingProperties>
XML;
    }

    private function digestXml(string $xml): string
    {
        return base64_encode(hash('sha256', $this->xmlGenerator->canonicalize($xml), true));
    }

    /**
     * Get certificate type based on environment
     */
    private function getCertType(): string
    {
        return $this->apiService->getEnvironment() === 'production' ? 'production' : 'compliance';
    }

    /**
     * Get API secret from storage
     */
    private function getSecret(): ?string
    {
        $path = $this->config['secret_path'] ?? 'zatca/secret.txt';
        return $this->storage->get($path);
    }

    /**
     * Save API secret
     */
    public function saveSecret(string $secret): void
    {
        $path = $this->config['secret_path'] ?? 'zatca/secret.txt';
        $this->storage->put($path, $secret);
    }

    /**
     * Validate invoice XML against ZATCA schema
     */
    public function validateXML(string $xml): array
    {
        // Enable user error handling
        libxml_use_internal_errors(true);

        $doc = new \DOMDocument();
        $doc->loadXML($xml);

        $errors = [];
        foreach (libxml_get_errors() as $error) {
            $errors[] = [
                'level' => $error->level,
                'message' => trim($error->message),
                'line' => $error->line,
            ];
        }
        libxml_clear_errors();

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
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
        Log::channel($channel)->{$level}('[ZATCA Invoice] ' . $message);
    }
}
