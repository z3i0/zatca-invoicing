<?php

declare(strict_types=1);

namespace SaudiZATCA\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

/**
 * ZATCA Certificate Model
 * 
 * Stores certificate information for ZATCA integration.
 */
class ZatcaCertificate extends Model
{
    protected $table = 'zatca_certificates';
    
    protected $fillable = [
        'environment',
        'type',
        'vat_number',
        'organization_name',
        'csr_content',
        'certificate_content',
        'private_key_content',
        'request_id',
        'secret',
        'issued_at',
        'expires_at',
        'is_active',
    ];
    
    protected $casts = [
        'issued_at' => 'datetime',
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
    ];
    
    protected $hidden = [
        'private_key_content',
        'secret',
    ];
    
    /**
     * Scope: Active certificates
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
    
    /**
     * Scope: By environment
     */
    public function scopeEnvironment(Builder $query, string $environment): Builder
    {
        return $query->where('environment', $environment);
    }
    
    /**
     * Scope: By type
     */
    public function scopeType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }
    
    /**
     * Scope: By VAT number
     */
    public function scopeVatNumber(Builder $query, string $vatNumber): Builder
    {
        return $query->where('vat_number', $vatNumber);
    }
    
    /**
     * Check if certificate is valid (not expired)
     */
    public function isValid(): bool
    {
        if (!$this->is_active) {
            return false;
        }
        
        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Get certificate body without PEM headers
     */
    public function getCertificateBody(): ?string
    {
        if (empty($this->certificate_content)) {
            return null;
        }
        
        $cleaned = preg_replace('/-----BEGIN CERTIFICATE-----/', '', $this->certificate_content);
        $cleaned = preg_replace('/-----END CERTIFICATE-----/', '', $cleaned);
        $cleaned = preg_replace('/\s+/', '', $cleaned);
        
        return trim($cleaned);
    }
    
    /**
     * Deactivate this certificate
     */
    public function deactivate(): void
    {
        $this->update(['is_active' => false]);
    }
}
