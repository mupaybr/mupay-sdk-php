<?php

declare(strict_types=1);

namespace Mupay\Sdk\Http;

use Psr\Http\Message\ResponseInterface;

final class RetryPolicy
{
    /** @var callable(int): void */
    private $sleeper;

    /**
     * Define a politica de retry para falhas transientes.
     *
     * Mantemos retry pequeno por padrao para melhorar DX sem esconder indisponibilidade
     * real da API. Chamadas mutaveis seguem seguras porque o SDK envia idempotency key.
     *
     * @param callable(int): void|null $sleeper
     */
    public function __construct(
        private readonly int $maxRetries = 2,
        private readonly int $baseDelayMs = 200,
        ?callable $sleeper = null
    ) {
        $this->sleeper = $sleeper ?? static function (int $delayMs): void {
            usleep($delayMs * 1000);
        };
    }

    public static function default(): self
    {
        return new self();
    }

    public static function none(): self
    {
        return new self(0, 0, static function (int $delayMs): void {
        });
    }

    public function shouldRetry(int $attempt, ?ResponseInterface $response = null): bool
    {
        if ($attempt >= $this->maxRetries) {
            return false;
        }

        if ($response === null) {
            return true;
        }

        $status = $response->getStatusCode();

        return $status === 429 || $status >= 500;
    }

    public function sleepBeforeRetry(int $attempt, ?ResponseInterface $response = null): void
    {
        $delayMs = $this->delayMs($attempt, $response);
        ($this->sleeper)($delayMs);
    }

    private function delayMs(int $attempt, ?ResponseInterface $response): int
    {
        if ($response !== null && $response->hasHeader('Retry-After')) {
            $retryAfter = (int) $response->getHeaderLine('Retry-After');
            if ($retryAfter > 0) {
                return $retryAfter * 1000;
            }
        }

        return $this->baseDelayMs * (2 ** max(0, $attempt));
    }
}
