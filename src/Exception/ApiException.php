<?php

declare(strict_types=1);

namespace MuPag\Sdk\Exception;

class ApiException extends MuPagException
{
    public function __construct(
        string $message,
        private readonly int $statusCode,
        private readonly ?string $apiCode = null,
        private readonly ?string $requestId = null,
        private readonly ?string $suggestion = null,
        private readonly ?string $documentationUrl = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function apiCode(): ?string
    {
        return $this->apiCode;
    }

    public function requestId(): ?string
    {
        return $this->requestId;
    }

    public function suggestion(): ?string
    {
        return $this->suggestion;
    }

    public function documentationUrl(): ?string
    {
        return $this->documentationUrl;
    }
}
