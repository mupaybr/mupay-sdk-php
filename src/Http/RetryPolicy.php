<?php

declare(strict_types=1);

namespace MuPag\Sdk\Http;

use Psr\Http\Message\ResponseInterface;

final class RetryPolicy
{
    private const HTTP_DATE_FORMAT = 'D, d M Y H:i:s \G\M\T';

    /** @var callable(int): void */
    private $sleeper;

    /** @var callable(int): int */
    private $jitter;

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
        ?callable $sleeper = null,
        ?callable $jitter = null
    ) {
        if ($maxRetries < 0 || $maxRetries > 5 || $baseDelayMs < 0 || $baseDelayMs > 30_000) {
            throw new \InvalidArgumentException('Retry deve usar maxRetries entre 0 e 5 e baseDelayMs entre 0 e 30000.');
        }
        $this->sleeper = $sleeper ?? static function (int $delayMs): void {
            usleep($delayMs * 1000);
        };
        $this->jitter = $jitter ?? static function (int $delayMs): int {
            if ($delayMs <= 0) {
                return 0;
            }
            $minimum = intdiv($delayMs * 75, 100);
            $maximum = intdiv(($delayMs * 125) + 99, 100);

            return random_int($minimum, $maximum);
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

    public function shouldRetry(
        int $attempt,
        ?ResponseInterface $response = null,
        bool $idempotencyInProgress = false
    ): bool
    {
        if ($attempt >= $this->maxRetries) {
            return false;
        }

        if ($response === null) {
            return true;
        }

        $status = $response->getStatusCode();

        return in_array($status, [408, 425, 429], true)
            || $status >= 500
            || ($status === 409 && $idempotencyInProgress);
    }

    public function sleepBeforeRetry(int $attempt, ?ResponseInterface $response = null): void
    {
        $delayMs = $this->delayMs($attempt, $response);
        ($this->sleeper)($delayMs);
    }

    private function delayMs(int $attempt, ?ResponseInterface $response): int
    {
        $retryAfter = self::retryAfterSeconds($response);
        if ($retryAfter !== null) {
            return $retryAfter * 1000;
        }

        $baseDelay = min(30_000, $this->baseDelayMs * (2 ** max(0, $attempt)));

        return min(30_000, max(0, ($this->jitter)($baseDelay)));
    }

    public static function retryAfterSeconds(?ResponseInterface $response): ?int
    {
        if ($response === null || !$response->hasHeader('Retry-After')) {
            return null;
        }
        $value = trim($response->getHeaderLine('Retry-After'));
        if (ctype_digit($value)) {
            return min(30, (int) $value);
        }
        $retryAt = \DateTimeImmutable::createFromFormat(
            '!' . self::HTTP_DATE_FORMAT,
            $value,
            new \DateTimeZone('GMT')
        );
        $errors = \DateTimeImmutable::getLastErrors();
        if ($retryAt === false
            || (is_array($errors)
                && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))
            || $retryAt->format(self::HTTP_DATE_FORMAT) !== $value) {
            return null;
        }

        return min(30, max(0, $retryAt->getTimestamp() - time()));
    }
}
