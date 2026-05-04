<?php

declare(strict_types=1);

namespace SaudiZATCA\Data;

/**
 * Seller Data Transfer Object
 *
 * Represents seller information required for ZATCA invoices.
 */
class SellerData
{
    public function __construct(
        public readonly string $nameEn,
        public readonly string $vatNumber,
        public readonly ?string $nameAr = null,
        public readonly ?string $street = null,
        public readonly ?string $building = null,
        public readonly ?string $city = null,
        public readonly ?string $district = null,
        public readonly ?string $postalCode = null,
        public readonly string $country = 'SA',
        public readonly ?string $countrySubdivision = null,
        public readonly ?string $registrationNumber = null,
        public readonly ?string $email = null,
        public readonly ?string $phone = null,
        public readonly ?string $industry = null,
    ) {
    }

    /**
     * Create from array
     */
    public static function fromArray(array $data): self
    {
        return new self(
            nameEn: $data['name_en'] ?? $data['name'] ?? '',
            vatNumber: $data['vat_number'] ?? '',
            nameAr: $data['name_ar'] ?? null,
            street: $data['street'] ?? null,
            building: $data['building'] ?? null,
            city: $data['city'] ?? null,
            district: $data['district'] ?? null,
            postalCode: $data['postal_code'] ?? null,
            country: $data['country'] ?? 'SA',
            countrySubdivision: $data['country_subdivision'] ?? null,
            registrationNumber: $data['registration_number'] ?? null,
            email: $data['email'] ?? null,
            phone: $data['phone'] ?? null,
            industry: $data['industry'] ?? null,
        );
    }

    /**
     * Create from config
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            nameEn: $config['name_en'] ?? '',
            vatNumber: $config['vat_number'] ?? '',
            nameAr: $config['name_ar'] ?? null,
            street: $config['street'] ?? null,
            building: $config['building'] ?? null,
            city: $config['city'] ?? null,
            district: $config['district'] ?? null,
            postalCode: $config['postal_code'] ?? null,
            country: $config['country'] ?? 'SA',
            countrySubdivision: $config['country_subdivision'] ?? null,
            registrationNumber: $config['registration_number'] ?? null,
            email: $config['email'] ?? null,
            phone: $config['phone'] ?? null,
            industry: $config['industry'] ?? null,
        );
    }

    /**
     * Convert to array
     */
    public function toArray(): array
    {
        return array_filter([
            'name_en' => $this->nameEn,
            'name_ar' => $this->nameAr,
            'vat_number' => $this->vatNumber,
            'street' => $this->street,
            'building' => $this->building,
            'city' => $this->city,
            'district' => $this->district,
            'postal_code' => $this->postalCode,
            'country' => $this->country,
            'country_subdivision' => $this->countrySubdivision,
            'registration_number' => $this->registrationNumber,
            'email' => $this->email,
            'phone' => $this->phone,
            'industry' => $this->industry,
        ], fn($v) => $v !== null);
    }
}
