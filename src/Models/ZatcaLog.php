<?php

declare(strict_types=1);

namespace SaudiZATCA\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ZATCA Log Model
 *
 * Stores detailed logs of all ZATCA operations.
 */
class ZatcaLog extends Model
{
    protected $table = 'zatca_logs';

    protected $fillable = [
        'level',
        'category',
        'environment',
        'invoice_id',
        'action',
        'message',
        'payload',
        'response',
        'status_code',
        'error_message',
        'duration_ms',
        'ip_address',
    ];

    protected $casts = [
        'payload' => 'array',
        'response' => 'array',
        'duration_ms' => 'float',
    ];

    // Log levels
    public const LEVEL_DEBUG = 'debug';
    public const LEVEL_INFO = 'info';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_ERROR = 'error';

    // Categories
    public const CATEGORY_API = 'api';
    public const CATEGORY_INVOICE = 'invoice';
    public const CATEGORY_CERTIFICATE = 'certificate';
    public const CATEGORY_VALIDATION = 'validation';
    public const CATEGORY_GENERAL = 'general';

    /**
     * Invoice relationship
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(ZatcaInvoice::class, 'invoice_id');
    }

    /**
     * Scope: By level
     */
    public function scopeLevel(Builder $query, string $level): Builder
    {
        return $query->where('level', $level);
    }

    /**
     * Scope: By category
     */
    public function scopeCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }

    /**
     * Scope: Errors only
     */
    public function scopeErrors(Builder $query): Builder
    {
        return $query->where('level', self::LEVEL_ERROR);
    }

    /**
     * Scope: Recent
     */
    public function scopeRecent(Builder $query, int $hours = 24): Builder
    {
        return $query->where('created_at', '>=', now()->subHours($hours));
    }

    /**
     * Scope: By action
     */
    public function scopeAction(Builder $query, string $action): Builder
    {
        return $query->where('action', $action);
    }

    /**
     * Create error log entry
     */
    public static function logError(
        string $action,
        string $message,
        ?array $payload = null,
        ?array $response = null,
        ?string $statusCode = null,
        ?int $invoiceId = null
    ): self {
        return self::create([
            'level' => self::LEVEL_ERROR,
            'category' => self::CATEGORY_GENERAL,
            'action' => $action,
            'message' => $message,
            'payload' => $payload,
            'response' => $response,
            'status_code' => $statusCode,
            'invoice_id' => $invoiceId,
        ]);
    }

    /**
     * Create info log entry
     */
    public static function logInfo(
        string $action,
        string $message,
        ?array $payload = null,
        ?array $response = null,
        ?int $invoiceId = null
    ): self {
        return self::create([
            'level' => self::LEVEL_INFO,
            'category' => self::CATEGORY_GENERAL,
            'action' => $action,
            'message' => $message,
            'payload' => $payload,
            'response' => $response,
            'invoice_id' => $invoiceId,
        ]);
    }
}
