<?php

declare(strict_types=1);

namespace SaudiZATCA\Exceptions;

class CertificateException extends ZatcaException
{
    public function __construct(
        string $message = 'Certificate Error',
        int $code = 0,
        ?array $details = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $details, $previous);
    }
}
