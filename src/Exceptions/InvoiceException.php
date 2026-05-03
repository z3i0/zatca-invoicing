<?php

declare(strict_types=1);

namespace SaudiZATCA\Exceptions;

class InvoiceException extends ZatcaException
{
    public function __construct(
        string $message = 'Invoice Error',
        int $code = 0,
        ?array $details = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $details, $previous);
    }
}
