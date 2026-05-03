<?php

declare(strict_types=1);

namespace SaudiZATCA\Data;

/**
 * Buyer Data Transfer Object
 * 
 * Represents buyer/customer information for ZATCA invoices.
 */
class BuyerData
{
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $vatNumber = null,
        public readonly ?string $nameAr = null,
        public readonly ?string $street = null,
        public readonly ?string $building = null,
        public readonly ?string $city = null,
        public readonly ?string $district = null,
        public readonly ?string $postalCode = null,
        public readonly string $country = 'SA',
        public readonly ?string $countrySubdivision = null,
        public readonly ?string $email = null,
        public readonly ?string $phone = null,
        public readonly ?string $registrationNumber = null,
    ) {}

    /**
     * Create from array
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'] ?? $data['name_en'] ?? null,
            vatNumber: $data['vat_number'] ?? null,
            nameAr: $data['name_ar'] ?? null,
            street: $data['street'] ?? null,
            building: $data['building'] ?? null,
            city: $data['city'] ?? null,
            district: $data['district'] ?? null,
            postalCode: $data['postal_code'] ?? null,
            country: $data['country'] ?? 'SA',
            countrySubdivision: $data['country_subdivision'] ?? null,
            email: $data['email'] ?? null,
            phone: $data['phone'] ?? null,
            registrationNumber: $data['registration_number'] ?? null,
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
            'vat_number' => $this->vatNumber,
            'street' => $this->street,
            'building' => $this->building,
            'city' => $this->city,
            'district' => $this->district,
            'postal_code' => $this->postalCode,
            'country' => $this->country,
            'country_subdivision' => $this->countrySubdivision,
            'email' => $this->email,
            'phone' => $this->phone,
            'registration_number' => $this->registrationNumber,
        ], fn($v) => $v !== null);
    }

    /**
     * Check if buyer is a VAT registered business (B2B)
     */
    public function isB2B(): bool
    {
        return !empty($this->vatNumber);
    }
}
