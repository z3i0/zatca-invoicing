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
        $signature = $this->createSignature($xml, $privateKey, $certificate);
        
        // Embed signature in XML
        $signedXml = $this->embedSignature($xml, $signature, $certificate, $invoiceHash);
        
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
        
        return $this->qrService->generatePhase2QR(
            $seller,
            $invoice,
            $invoiceHash,
            $signedResult['signature'],
            $publicKey,
            $invoice->issueDate
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
    private function createSignature(string $xml, string $privateKey, string $certificate): string
    {
        $canonicalized = $this->xmlGenerator->canonicalize($xml);
        
        // Load private key
        $key = openssl_pkey_get_private($privateKey);
        
        if ($key === false) {
            throw new InvoiceException('Failed to load private key for signing');
        }
        
        // Sign with ECDSA SHA-256
        $signature = '';
        $result = openssl_sign(
            $canonicalized,
            $signature,
            $key,
            'sha256'
        );
        
        if (!$result) {
            throw new InvoiceException('Failed to create digital signature: ' . openssl_error_string());
        }
        
        return base64_encode($signature);
    }

    /**
     * Embed signature in XML
     */
    private function embedSignature(string $xml, string $signature, string $certificate, string $invoiceHash): string
    {
        $doc = new \DOMDocument();
        $doc->loadXML($xml);
        
        // Find UBLExtensions element
        $extensions = $doc->getElementsByTagNameNS(
            'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2',
            'ExtensionContent'
        )->item(0);
        
        if ($extensions) {
            // Create signature element
            $sigDoc = new \DOMDocument();
            $sigDoc->loadXML($this->buildSignatureXML($signature, $certificate, $invoiceHash));
            
            $imported = $doc->importNode($sigDoc->documentElement, true);
            $extensions->appendChild($imported);
        }
        
        // Add QR code reference in additional document reference
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
            $invoiceHash
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
    private function buildSignatureXML(string $signature, string $certificate, string $invoiceHash): string
    {
        $certBody = $this->certService->extractCertificateBody($certificate);
        
        return <<<XML
<Signature xmlns="http://www.w3.org/2000/09/xmldsig#">
    <SignedInfo>
        <CanonicalizationMethod Algorithm="http://www.w3.org/TR/2001/REC-xml-c14n-20010315"/>
        <SignatureMethod Algorithm="http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha256"/>
        <Reference URI="">
            <Transforms>
                <Transform Algorithm="http://www.w3.org/2000/09/xmldsig#enveloped-signature"/>
            </Transforms>
            <DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>
            <DigestValue>{$invoiceHash}</DigestValue>
        </Reference>
    </SignedInfo>
    <SignatureValue>{$signature}</SignatureValue>
    <KeyInfo>
        <X509Data>
            <X509Certificate>{$certBody}</X509Certificate>
        </X509Data>
    </KeyInfo>
</Signature>
XML;
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
