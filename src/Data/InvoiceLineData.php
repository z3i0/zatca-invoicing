<?php

declare(strict_types=1);

namespace SaudiZATCA\Data;

/**
 * Invoice Line Data Transfer Object
 *
 * Represents a single line item in a ZATCA invoice.
 */
class InvoiceLineData
{
    public function __construct(
        public readonly string $name,
        public readonly float $quantity,
        public readonly float $unitPrice,
        public readonly float $taxRate = 15.0,
        public readonly ?string $nameAr = null,
        public readonly string $unitCode = 'PCE',
        public readonly ?float $discount = null,
        public readonly ?string $description = null,
        public readonly ?string $itemCode = null,
        public readonly ?float $taxAmount = null,
        public readonly ?float $totalAmount = null,
        public readonly ?float $netAmount = null,
    ) {
    }

    /**
     * Calculate tax amount for this line
     */
    public function calculateTax(): float
    {
        return round($this->netTotal() * ($this->taxRate / 100), 2);
    }

    /**
     * Calculate net total (after discount)
     */
    public function netTotal(): float
    {
        $gross = $this->quantity * $this->unitPrice;
        return round($gross - ($this->discount ?? 0), 2);
    }

    /**
     * Calculate total with tax
     */
    public function totalWithTax(): float
    {
        return round($this->netTotal() + $this->calculateTax(), 2);
    }

    /**
     * Create from array
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'] ?? '',
            quantity: (float) ($data['quantity'] ?? 1),
            unitPrice: (float) ($data['unit_price'] ?? $data['price'] ?? 0),
            taxRate: (float) ($data['tax_rate'] ?? 15.0),
            nameAr: $data['name_ar'] ?? null,
            unitCode: $data['unit_code'] ?? 'PCE',
            discount: isset($data['discount']) ? (float) $data['discount'] : null,
            description: $data['description'] ?? null,
            itemCode: $data['item_code'] ?? null,
            taxAmount: isset($data['tax_amount']) ? (float) $data['tax_amount'] : null,
            totalAmount: isset($data['total_amount']) ? (float) $data['total_amount'] : null,
            netAmount: isset($data['net_amount']) ? (float) $data['net_amount'] : null,
        );
    }

    /**
     * Convert to array
     */
    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'name_ar' => $this->nameAr,
            'quantity' => $this->quantity,
            'unit_price' => $this->unitPrice,
            'unit_code' => $this->unitCode,
            'tax_rate' => $this->taxRate,
            'discount' => $this->discount,
            'description' => $this->description,
            'item_code' => $this->itemCode,
            'tax_amount' => $this->taxAmount ?? $this->calculateTax(),
            'total_amount' => $this->totalAmount ?? $this->totalWithTax(),
            'net_amount' => $this->netAmount ?? $this->netTotal(),
        ], fn($v) => $v !== null);
    }
}
