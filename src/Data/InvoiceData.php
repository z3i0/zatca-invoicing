<?php

declare(strict_types=1);

namespace SaudiZATCA\Data;

use DateTime;
use DateTimeInterface;

/**
 * Invoice Data Transfer Object
 *
 * Represents the main invoice data for ZATCA compliance.
 */
class InvoiceData
{
    public const TYPE_STANDARD = 'standard';       // B2B - needs clearance
    public const TYPE_SIMPLIFIED = 'simplified';   // B2C - needs reporting
    public const TYPE_CREDIT_NOTE = 'credit_note';
    public const TYPE_DEBIT_NOTE = 'debit_note';

    public const SUBTYPE_TAX_INVOICE = '0100000';
    public const SUBTYPE_SIMPLIFIED = '0200000';
    public const SUBTYPE_CREDIT_NOTE = '0300000';
    public const SUBTYPE_DEBIT_NOTE = '0400000';
    public const SUBTYPE_PREPAID = '0500000';

    public function __construct(
        public readonly string $invoiceNumber,
        public readonly DateTimeInterface $issueDate,
        public readonly array $lines,
        public readonly string $type = self::TYPE_STANDARD,
        public readonly string $currency = 'SAR',
        public readonly ?DateTimeInterface $deliveryDate = null,
        public readonly ?string $purchaseOrder = null,
        public readonly ?string $billingReference = null,
        public readonly ?string $previousInvoiceHash = null,
        public readonly int $counter = 1,
        public readonly ?string $uuid = null,
        public readonly ?string $sellerVatNumber = null,
        public readonly ?string $buyerVatNumber = null,
        public readonly ?string $notes = null,
        public readonly ?float $totalDiscount = null,
        public readonly ?float $totalCharges = null,
        public readonly ?float $paidAmount = null,
        public readonly ?string $paymentMethod = null,
        public readonly ?string $referenceInvoiceNumber = null,
        public readonly ?DateTimeInterface $referenceInvoiceDate = null,
    ) {
        if (empty($this->lines)) {
            throw new \InvalidArgumentException('Invoice must have at least one line item');
        }
    }

    /**
     * Calculate subtotal (sum of all line net amounts)
     */
    public function subTotal(): float
    {
        return round(array_sum(array_map(
            fn(InvoiceLineData $line) => $line->netTotal(),
            $this->lines
        )), 2);
    }

    /**
     * Calculate total tax
     */
    public function totalTax(): float
    {
        return round(array_sum(array_map(
            fn(InvoiceLineData $line) => $line->calculateTax(),
            $this->lines
        )), 2);
    }

    /**
     * Calculate total amount (subtotal + tax - discount + charges)
     */
    public function totalAmount(): float
    {
        $total = $this->subTotal() + $this->totalTax();
        if ($this->totalDiscount) {
            $total -= $this->totalDiscount;
        }
        if ($this->totalCharges) {
            $total += $this->totalCharges;
        }
        return round(max(0, $total), 2);
    }

    /**
     * Get invoice subtype code
     */
    public function subTypeCode(): string
    {
        return match ($this->type) {
            self::TYPE_STANDARD => self::SUBTYPE_TAX_INVOICE,
            self::TYPE_SIMPLIFIED => self::SUBTYPE_SIMPLIFIED,
            self::TYPE_CREDIT_NOTE => self::SUBTYPE_CREDIT_NOTE,
            self::TYPE_DEBIT_NOTE => self::SUBTYPE_DEBIT_NOTE,
            default => self::SUBTYPE_TAX_INVOICE,
        };
    }

    /**
     * Check if invoice needs clearance (B2B)
     */
    public function needsClearance(): bool
    {
        return in_array($this->type, [self::TYPE_STANDARD, self::TYPE_CREDIT_NOTE, self::TYPE_DEBIT_NOTE], true);
    }

    /**
     * Check if invoice needs reporting (B2C)
     */
    public function needsReporting(): bool
    {
        return $this->type === self::TYPE_SIMPLIFIED;
    }

    /**
     * Get UUID or generate one
     */
    public function getUuid(): string
    {
        return $this->uuid ?? $this->generateUuid();
    }

    /**
     * Generate UUIDv4
     */
    public function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }

    /**
     * Create from array
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $lines = array_map(
            fn(array $line) => $line instanceof InvoiceLineData ? $line : InvoiceLineData::fromArray($line),
            $data['lines'] ?? []
        );

        return new self(
            invoiceNumber: $data['invoice_number'] ?? $data['number'] ?? '',
            issueDate: isset($data['issue_date'])
                ? ($data['issue_date'] instanceof DateTimeInterface ? $data['issue_date'] : new DateTime($data['issue_date']))
                : new DateTime(),
            lines: $lines,
            type: $data['type'] ?? self::TYPE_STANDARD,
            currency: $data['currency'] ?? 'SAR',
            deliveryDate: isset($data['delivery_date']) && $data['delivery_date'] !== null
                ? ($data['delivery_date'] instanceof DateTimeInterface ? $data['delivery_date'] : new DateTime($data['delivery_date']))
                : null,
            purchaseOrder: $data['purchase_order'] ?? null,
            billingReference: $data['billing_reference'] ?? null,
            previousInvoiceHash: $data['previous_invoice_hash'] ?? null,
            counter: (int) ($data['counter'] ?? 1),
            uuid: $data['uuid'] ?? null,
            sellerVatNumber: $data['seller_vat_number'] ?? null,
            buyerVatNumber: $data['buyer_vat_number'] ?? null,
            notes: $data['notes'] ?? null,
            totalDiscount: isset($data['total_discount']) ? (float) $data['total_discount'] : null,
            totalCharges: isset($data['total_charges']) ? (float) $data['total_charges'] : null,
            paidAmount: isset($data['paid_amount']) ? (float) $data['paid_amount'] : null,
            paymentMethod: $data['payment_method'] ?? null,
            referenceInvoiceNumber: $data['reference_invoice_number'] ?? null,
            referenceInvoiceDate: isset($data['reference_invoice_date']) && $data['reference_invoice_date'] !== null
                ? ($data['reference_invoice_date'] instanceof DateTimeInterface ? $data['reference_invoice_date'] : new DateTime($data['reference_invoice_date']))
                : null,
        );
    }

    /**
     * Convert to array
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'invoice_number' => $this->invoiceNumber,
            'issue_date' => $this->issueDate->format('Y-m-d H:i:s'),
            'type' => $this->type,
            'currency' => $this->currency,
            'delivery_date' => $this->deliveryDate?->format('Y-m-d H:i:s'),
            'purchase_order' => $this->purchaseOrder,
            'billing_reference' => $this->billingReference,
            'previous_invoice_hash' => $this->previousInvoiceHash,
            'counter' => $this->counter,
            'uuid' => $this->getUuid(),
            'seller_vat_number' => $this->sellerVatNumber,
            'buyer_vat_number' => $this->buyerVatNumber,
            'notes' => $this->notes,
            'total_discount' => $this->totalDiscount,
            'total_charges' => $this->totalCharges,
            'paid_amount' => $this->paidAmount,
            'payment_method' => $this->paymentMethod,
            'reference_invoice_number' => $this->referenceInvoiceNumber,
            'reference_invoice_date' => $this->referenceInvoiceDate?->format('Y-m-d H:i:s'),
            'sub_total' => $this->subTotal(),
            'total_tax' => $this->totalTax(),
            'total_amount' => $this->totalAmount(),
            'lines' => array_map(fn(InvoiceLineData $line) => $line->toArray(), $this->lines),
        ], fn($v) => $v !== null);
    }
}
