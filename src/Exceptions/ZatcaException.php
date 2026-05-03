<?php

declare(strict_types=1);

namespace SaudiZATCA\Exceptions;

use Exception;

class ZatcaException extends Exception
{
    protected ?array $details;

    public function __construct(
        string $message = 'ZATCA Error',
        int $code = 0,
        ?array $details = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->details = $details;
    }

    public function getDetails(): ?array
    {
        return $this->details;
    }
}
