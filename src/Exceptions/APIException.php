<?php

declare(strict_types=1);

namespace SaudiZATCA\Exceptions;

class APIException extends ZatcaException
{
    protected ?int $statusCode;
    protected ?string $responseBody;

    public function __construct(
        string $message = 'API Error',
        int $code = 0,
        ?int $statusCode = null,
        ?string $responseBody = null,
        ?array $details = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $details, $previous);
        $this->statusCode = $statusCode;
        $this->responseBody = $responseBody;
    }

    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }

    public function getResponseBody(): ?string
    {
        return $this->responseBody;
    }
}
