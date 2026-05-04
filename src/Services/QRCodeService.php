<?php

declare(strict_types=1);

namespace SaudiZATCA\Services;

use SaudiZATCA\Data\SellerData;
use SaudiZATCA\Data\InvoiceData;
use DateTime;
use DateTimeInterface;

/**
 * QR Code Service
 *
 * Generates ZATCA-compliant QR codes using TLV (Tag-Length-Value) format.
 * Supports both Phase 1 (basic) and Phase 2 (digital signature) QR codes.
 */
class QRCodeService
{
    // TLV Tags as per ZATCA specification
    private const TAG_SELLER_NAME = 1;
    private const TAG_VAT_NUMBER = 2;
    private const TAG_TIMESTAMP = 3;
    private const TAG_TOTAL = 4;
    private const TAG_VAT_TOTAL = 5;
    private const TAG_HASH = 6;       // Phase 2
    private const TAG_SIGNATURE = 7;   // Phase 2
    private const TAG_PUBLIC_KEY = 8;  // Phase 2
    private const TAG_SIGNATURE_ECDSA = 9; // ECDSA signature

    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * Generate Phase 1 QR code (basic TLV)
     *
     * @return string Base64 encoded TLV data
     */
    public function generatePhase1QR(
        SellerData $seller,
        float $total,
        float $vat,
        ?DateTimeInterface $timestamp = null
    ): string {
        $timestamp = $timestamp ?? new DateTime();

        $tlv = '';
        $tlv .= $this->encodeTLV(self::TAG_SELLER_NAME, $seller->nameEn);
        $tlv .= $this->encodeTLV(self::TAG_VAT_NUMBER, $seller->vatNumber);
        $tlv .= $this->encodeTLV(self::TAG_TIMESTAMP, $timestamp->format('Y-m-d\TH:i:sP'));
        $tlv .= $this->encodeTLV(self::TAG_TOTAL, (string) $total);
        $tlv .= $this->encodeTLV(self::TAG_VAT_TOTAL, (string) $vat);

        return base64_encode($tlv);
    }

    /**
     * Generate Phase 2 QR code (with digital signature)
     *
     * @return string Base64 encoded TLV data with signature
     */
    public function generatePhase2QR(
        SellerData $seller,
        InvoiceData $invoice,
        string $invoiceHash,
        string $signature,
        string $publicKey,
        ?DateTimeInterface $timestamp = null,
        ?string $certificateSignature = null
    ): string {
        $timestamp = $timestamp ?? new DateTime();

        $tlv = '';
        $tlv .= $this->encodeTLV(self::TAG_SELLER_NAME, $seller->nameEn);
        $tlv .= $this->encodeTLV(self::TAG_VAT_NUMBER, $seller->vatNumber);
        $tlv .= $this->encodeTLV(self::TAG_TIMESTAMP, $timestamp->format('Y-m-d\TH:i:sP'));
        $tlv .= $this->encodeTLV(self::TAG_TOTAL, (string) $invoice->totalAmount());
        $tlv .= $this->encodeTLV(self::TAG_VAT_TOTAL, (string) $invoice->totalTax());
        $tlv .= $this->encodeTLV(self::TAG_HASH, $invoiceHash);
        $tlv .= $this->encodeTLV(self::TAG_SIGNATURE, $signature);
        $tlv .= $this->encodeTLV(self::TAG_PUBLIC_KEY, $publicKey);

        if ($certificateSignature !== null && $certificateSignature !== '') {
            $tlv .= $this->encodeTLV(self::TAG_SIGNATURE_ECDSA, $certificateSignature);
        }

        return base64_encode($tlv);
    }

    /**
     * Decode QR code data
     *
     * @return array<int, string>
     */
    public function decode(string $base64Data): array
    {
        $tlv = base64_decode($base64Data);

        if ($tlv === false) {
            throw new \InvalidArgumentException('Invalid base64 QR code data');
        }

        return $this->decodeTLV($tlv);
    }

    /**
     * Validate QR code data
     */
    public function validate(string $base64Data): bool
    {
        try {
            $decoded = $this->decode($base64Data);

            // Check required fields
            $requiredTags = [
                self::TAG_SELLER_NAME,
                self::TAG_VAT_NUMBER,
                self::TAG_TIMESTAMP,
                self::TAG_TOTAL,
                self::TAG_VAT_TOTAL,
            ];

            foreach ($requiredTags as $tag) {
                if (!isset($decoded[$tag]) || empty($decoded[$tag])) {
                    return false;
                }
            }

            // Validate timestamp format
            if (!strtotime($decoded[self::TAG_TIMESTAMP])) {
                return false;
            }

            // Validate amounts are numeric
            if (!is_numeric($decoded[self::TAG_TOTAL]) || !is_numeric($decoded[self::TAG_VAT_TOTAL])) {
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Get QR code as image (PNG)
     *
     * @return string PNG image data
     */
    public function generateImage(string $qrData, int $size = 300): string
    {
        // Try to use simplesoftwareio/simple-qrcode if available
        if (class_exists(\SimpleSoftwareIO\QrCode\Facades\QrCode::class)) {
            return $this->generateWithLibrary($qrData, $size);
        }

        // Fallback to basic GD implementation
        return $this->generateWithGD($qrData, $size);
    }

    /**
     * Generate QR using simple-qrcode library
     */
    private function generateWithLibrary(string $qrData, int $size): string
    {
        $qrCode = \SimpleSoftwareIO\QrCode\Facades\QrCode::format('png')
            ->size($size)
            ->errorCorrection('M')
            ->generate($qrData);

        return (string) $qrCode;
    }

    /**
     * Generate QR using GD library (fallback)
     */
    private function generateWithGD(string $qrData, int $size): string
    {
        // This is a simplified fallback
        // In production, use a proper QR code library
        $image = imagecreatetruecolor($size, $size);
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);

        imagefill($image, 0, 0, $white);

        // Draw a simple pattern (placeholder for actual QR code)
        $cellSize = max(2, (int) ($size / 25));
        $data = hash('sha256', $qrData);
        $index = 0;

        for ($y = 0; $y < 25; $y++) {
            for ($x = 0; $x < 25; $x++) {
                if (hexdec($data[$index % 64]) % 2 === 0) {
                    imagefilledrectangle(
                        $image,
                        $x * $cellSize,
                        $y * $cellSize,
                        ($x + 1) * $cellSize - 1,
                        ($y + 1) * $cellSize - 1,
                        $black
                    );
                }
                $index++;
            }
        }

        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);

        return $png ?: '';
    }

    /**
     * Encode TLV (Tag-Length-Value)
     */
    private function encodeTLV(int $tag, string $value): string
    {
        $tagBytes = pack('C', $tag);
        $valueBytes = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        $length = strlen($valueBytes);

        if ($length > 255) {
            throw new \InvalidArgumentException("TLV value for tag {$tag} exceeds the one-byte length limit");
        }

        $lengthBytes = pack('C', $length);

        return $tagBytes . $lengthBytes . $valueBytes;
    }

    /**
     * Decode TLV data
     *
     * @return array<int, string>
     */
    private function decodeTLV(string $tlv): array
    {
        $result = [];
        $offset = 0;
        $length = strlen($tlv);

        while ($offset < $length) {
            if ($offset + 2 > $length) {
                break;
            }

            $tag = ord($tlv[$offset]);
            $len = ord($tlv[$offset + 1]);

            if ($offset + 2 + $len > $length) {
                break;
            }

            $value = substr($tlv, $offset + 2, $len);
            $result[$tag] = $value;

            $offset += 2 + $len;
        }

        return $result;
    }

    /**
     * Get decoded data as formatted array
     *
     * @return array<string, string>
     */
    public function getFormattedData(string $base64Data): array
    {
        $decoded = $this->decode($base64Data);

        $tagNames = [
            self::TAG_SELLER_NAME => 'seller_name',
            self::TAG_VAT_NUMBER => 'vat_number',
            self::TAG_TIMESTAMP => 'timestamp',
            self::TAG_TOTAL => 'total_amount',
            self::TAG_VAT_TOTAL => 'vat_amount',
            self::TAG_HASH => 'invoice_hash',
            self::TAG_SIGNATURE => 'digital_signature',
            self::TAG_PUBLIC_KEY => 'public_key',
            self::TAG_SIGNATURE_ECDSA => 'certificate_signature',
        ];

        $result = [];
        foreach ($decoded as $tag => $value) {
            $name = $tagNames[$tag] ?? 'unknown_' . $tag;
            $result[$name] = $value;
        }

        return $result;
    }

    /**
     * Get tag name from tag number
     */
    public function getTagName(int $tag): string
    {
        return match ($tag) {
            self::TAG_SELLER_NAME => 'Seller Name',
            self::TAG_VAT_NUMBER => 'VAT Number',
            self::TAG_TIMESTAMP => 'Timestamp',
            self::TAG_TOTAL => 'Total Amount',
            self::TAG_VAT_TOTAL => 'VAT Total',
            self::TAG_HASH => 'Invoice Hash',
            self::TAG_SIGNATURE => 'Digital Signature',
            self::TAG_PUBLIC_KEY => 'Public Key',
            self::TAG_SIGNATURE_ECDSA => 'ECDSA Signature',
            default => 'Unknown',
        };
    }
}
