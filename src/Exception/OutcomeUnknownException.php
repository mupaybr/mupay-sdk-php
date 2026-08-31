<?php

declare(strict_types=1);

namespace MuPag\Sdk\Exception;

/**
 * A mutacao pode ter sido aceita, mas a resposta final nao foi confirmada.
 *
 * O caller deve persistir idempotencyKey() e reutiliza-la somente com o mesmo payload.
 */
final class OutcomeUnknownException extends ApiException
{
    public function __construct(
        private readonly string $idempotencyKey,
        \Throwable $previous
    ) {
        parent::__construct(
            'Resultado da mutacao desconhecido; reutilize a Idempotency-Key com o mesmo payload.',
            $previous instanceof ApiException ? $previous->statusCode() : 0,
            'outcome_unknown',
            $previous instanceof ApiException ? $previous->requestId() : null,
            $previous instanceof ApiException ? $previous->suggestion() : null,
            $previous instanceof ApiException ? $previous->documentationUrl() : null,
            $previous
        );
    }

    public function outcomeUnknown(): bool
    {
        return true;
    }

    public function idempotencyKey(): string
    {
        return $this->idempotencyKey;
    }
}
