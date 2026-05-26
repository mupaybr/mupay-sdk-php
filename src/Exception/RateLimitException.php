<?php

declare(strict_types=1);

namespace Mupay\Sdk\Exception;

final class RateLimitException extends ApiException
{
    public function __construct(
        string $message,
        ?string $apiCode = 'rate_limited',
        ?string $requestId = null,
        ?string $suggestion = null,
        ?string $documentationUrl = null,
        private readonly ?int $retryAfterSeconds = null
    ) {
        parent::__construct($message, 429, $apiCode, $requestId, $suggestion, $documentationUrl);
    }

    public function retryAfterSeconds(): ?int
    {
        return $this->retryAfterSeconds;
    }
}
