<?php

declare(strict_types=1);

namespace SaudiZATCA\Helpers;

/**
 * ZATCA Helper
 *
 * Utility functions for ZATCA operations.
 */
class ZatcaHelper
{
    /**
     * Format amount to ZATCA standard (2 decimal places)
     */
    public static function formatAmount(float $amount, int $decimals = 2): string
    {
        return number_format($amount, $decimals, '.', '');
    }

    /**
     * Validate Saudi VAT number format
     */
    public static function isValidVatNumber(string $vatNumber): bool
    {
        return preg_match('/^3\d{13}3$/', $vatNumber) === 1;
    }

    /**
     * Format VAT number (ensure 15 digits)
     */
    public static function formatVatNumber(string $vatNumber): string
    {
        $cleaned = preg_replace('/\D/', '', $vatNumber);

        if (strlen($cleaned) === 15) {
            return $cleaned;
        }

        // Pad with leading zeros if needed
        return str_pad($cleaned, 15, '0', STR_PAD_LEFT);
    }

    /**
     * Generate invoice number with prefix
     */
    public static function generateInvoiceNumber(string $prefix = 'INV', int $length = 6): string
    {
        $sequence = str_pad((string) mt_rand(1, 999999), $length, '0', STR_PAD_LEFT);
        return $prefix . '-' . date('Y') . '-' . $sequence;
    }

    /**
     * Parse ZATCA date format
     */
    public static function parseDate(string $dateString): ?\DateTime
    {
        $formats = [
            'Y-m-d\TH:i:sP',     // ISO 8601
            'Y-m-d\TH:i:s',      // Without timezone
            'Y-m-d H:i:s',       // Standard datetime
            'Y-m-d',             // Date only
            'd/m/Y H:i:s',       // Saudi format
            'd/m/Y',             // Saudi date only
        ];

        foreach ($formats as $format) {
            $date = \DateTime::createFromFormat($format, $dateString);
            if ($date !== false) {
                return $date;
            }
        }

        return null;
    }

    /**
     * Format date for ZATCA
     */
    public static function formatDate(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d\TH:i:sP');
    }

    /**
     * Calculate VAT amount
     */
    public static function calculateVat(float $amount, float $rate = 15.0): float
    {
        return round($amount * ($rate / 100), 2);
    }

    /**
     * Calculate total with VAT
     */
    public static function calculateTotalWithVat(float $amount, float $rate = 15.0): float
    {
        return round($amount + self::calculateVat($amount, $rate), 2);
    }

    /**
     * Extract amount from VAT inclusive total
     */
    public static function extractAmountFromVatTotal(float $total, float $rate = 15.0): float
    {
        return round($total / (1 + ($rate / 100)), 2);
    }

    /**
     * Base64 encode with URL safety
     */
    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Base64 decode URL safe string
     */
    public static function base64UrlDecode(string $data): string
    {
        $padded = str_pad(
            strtr($data, '-_', '+/'),
            strlen($data) % 4,
            '=',
            STR_PAD_RIGHT
        );

        $decoded = base64_decode($padded);
        return $decoded !== false ? $decoded : '';
    }

    /**
     * Generate canonical JSON for hashing
     */
    public static function canonicalJson(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * Validate XML against ZATCA schema (basic check)
     */
    public static function isValidXml(string $xml): bool
    {
        if (empty($xml)) {
            return false;
        }

        libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        $errors = libxml_get_errors();
        libxml_clear_errors();

        return $doc !== false && empty($errors);
    }

    /**
     * Extract value from XML using XPath
     */
    public static function extractXmlValue(string $xml, string $xpath): ?string
    {
        $doc = new \DOMDocument();
        $doc->loadXML($xml);

        $domXPath = new \DOMXPath($doc);
        $domXPath->registerNamespace('ubl', 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2');
        $domXPath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');

        $nodes = $domXPath->query($xpath);

        if ($nodes && $nodes->length > 0) {
            return $nodes->item(0)->nodeValue;
        }

        return null;
    }

    /**
     * Mask sensitive data for logging
     */
    public static function maskSensitive(string $data, int $visibleChars = 4): string
    {
        $length = strlen($data);

        if ($length <= $visibleChars * 2) {
            return str_repeat('*', $length);
        }

        return substr($data, 0, $visibleChars)
            . str_repeat('*', $length - $visibleChars * 2)
            . substr($data, -$visibleChars);
    }

    /**
     * Convert TLV data to hex string for debugging
     */
    public static function tlvToHex(string $tlvData): string
    {
        return bin2hex($tlvData);
    }

    /**
     * Parse TLV hex string back to binary
     */
    public static function hexToTlv(string $hexData): string
    {
        return hex2bin($hexData) ?: '';
    }

    /**
     * Get invoice type name in Arabic
     */
    public static function getInvoiceTypeNameAr(string $type): string
    {
        return match ($type) {
            'standard' => 'فاتورة ضريبية',
            'simplified' => 'فاتورة مبسطة',
            'credit_note' => 'اشعار دائن',
            'debit_note' => 'اشعار مدين',
            default => 'فاتورة',
        };
    }

    /**
     * Get payment method name
     */
    public static function getPaymentMethodName(string $code): string
    {
        return match ($code) {
            '10' => 'Cash',
            '30' => 'Credit',
            '42' => 'Bank Transfer',
            '48' => 'Card',
            '1' => 'Other',
            default => 'Unknown',
        };
    }

    /**
     * Check if environment is production
     */
    public static function isProduction(): bool
    {
        return config('zatca.environment') === 'production';
    }

    /**
     * Get current ZATCA environment
     */
    public static function getEnvironment(): string
    {
        return config('zatca.environment', 'sandbox');
    }
}
