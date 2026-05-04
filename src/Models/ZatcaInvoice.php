<?php

declare(strict_types=1);

namespace SaudiZATCA\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ZATCA Invoice Model
 *
 * Stores invoice information and ZATCA submission status.
 */
class ZatcaInvoice extends Model
{
    protected $table = 'zatca_invoices';

    protected $fillable = [
        'invoice_number',
        'uuid',
        'type',
        'status',
        'environment',
        'seller_name',
        'seller_vat_number',
        'buyer_name',
        'buyer_vat_number',
        'sub_total',
        'tax_total',
        'total_amount',
        'discount',
        'currency',
        'issue_date',
        'delivery_date',
        'line_items',
        'notes',
        'invoice_hash',
        'xml_content',
        'signed_xml_content',
        'qr_code',
        'clearance_status',
        'zatca_response',
        'previous_invoice_hash',
        'reference_invoice_number',
        'submitted_at',
        'retry_count',
        'last_retry_at',
    ];

    protected $casts = [
        'issue_date' => 'datetime',
        'delivery_date' => 'datetime',
        'submitted_at' => 'datetime',
        'last_retry_at' => 'datetime',
        'line_items' => 'array',
        'zatca_response' => 'array',
        'sub_total' => 'decimal:2',
        'tax_total' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'discount' => 'decimal:2',
        'retry_count' => 'integer',
    ];

    // Status constants
    public const STATUS_DRAFT = 'draft';
    public const STATUS_GENERATED = 'generated';
    public const STATUS_SIGNED = 'signed';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_CLEARED = 'cleared';
    public const STATUS_REPORTED = 'reported';
    public const STATUS_FAILED = 'failed';

    // Type constants
    public const TYPE_STANDARD = 'standard';
    public const TYPE_SIMPLIFIED = 'simplified';
    public const TYPE_CREDIT_NOTE = 'credit_note';
    public const TYPE_DEBIT_NOTE = 'debit_note';

    /**
     * Scope: By status
     */
    public function scopeStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Scope: By type
     */
    public function scopeType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * Scope: By environment
     */
    public function scopeEnvironment(Builder $query, string $environment): Builder
    {
        return $query->where('environment', $environment);
    }

    /**
     * Scope: Pending submission
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_SIGNED, self::STATUS_FAILED])
                     ->where('retry_count', '<', 3);
    }

    /**
     * Scope: Submitted today
     */
    public function scopeToday(Builder $query): Builder
    {
        return $query->whereDate('created_at', today());
    }

    /**
     * Logs relationship
     */
    public function logs(): HasMany
    {
        return $this->hasMany(ZatcaLog::class, 'invoice_id');
    }

    /**
     * Mark as submitted
     */
    public function markAsSubmitted(?array $response = null): void
    {
        $this->update([
            'status' => self::STATUS_SUBMITTED,
            'submitted_at' => now(),
            'zatca_response' => $response,
            'clearance_status' => $this->type === self::TYPE_SIMPLIFIED ? 'reported' : 'cleared',
        ]);
    }

    /**
     * Mark as failed
     */
    public function markAsFailed(string $error): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'retry_count' => $this->retry_count + 1,
            'last_retry_at' => now(),
        ]);

        $this->logs()->create([
            'level' => 'error',
            'category' => 'invoice',
            'action' => 'submit_invoice',
            'message' => $error,
            'status_code' => '500',
        ]);
    }

    /**
     * Check if invoice can be retried
     */
    public function canRetry(): bool
    {
        return $this->status === self::STATUS_FAILED && $this->retry_count < 3;
    }

    /**
     * Check if invoice is cleared/reported
     */
    public function isProcessed(): bool
    {
        return in_array($this->status, [self::STATUS_CLEARED, self::STATUS_REPORTED], true);
    }
}
