<?php

declare(strict_types=1);

namespace MuPag\Sdk;

use MuPag\Sdk\Http\ApiClient;
use MuPag\Sdk\Http\RetryPolicy;
use MuPag\Sdk\Resources\ChargeResource;
use MuPag\Sdk\Resources\RefundResource;
use MuPag\Sdk\Resources\SubscriptionResource;
use MuPag\Sdk\Webhooks\WebhookService;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;

final class MuPagClient
{
    public readonly ChargeResource $charges;
    public readonly RefundResource $refunds;
    public readonly SubscriptionResource $subscriptions;
    public readonly WebhookService $webhooks;

    /**
     * Monta o SDK com os recursos publicos usados pelo lojista.
     *
     * O SDK fica fino de proposito: ele nao tenta calcular regra financeira localmente
     * nem persistir segredo. Toda decisao de negocio continua na API publica.
     */
    public function __construct(
        string $apiKey,
        Environment $environment,
        ?ClientInterface $httpClient = null,
        ?RetryPolicy $retryPolicy = null,
        ?LoggerInterface $logger = null,
        ?string $baseUrl = null,
        float $timeoutSeconds = 10.0,
        int $maxResponseBytes = 2_097_152
    ) {
        self::validateConfiguration($apiKey, $environment, $baseUrl ?? $environment->baseUrl(), $timeoutSeconds);
        $client = new ApiClient(
            $apiKey,
            $baseUrl ?? $environment->baseUrl(),
            $httpClient,
            $retryPolicy,
            $logger,
            $timeoutSeconds,
            $maxResponseBytes
        );

        $this->charges = new ChargeResource($client);
        $this->refunds = new RefundResource($client);
        $this->subscriptions = new SubscriptionResource($client);
        $this->webhooks = new WebhookService();
    }

    /**
     * Cria um cliente apontando para sandbox, ideal para quickstarts e testes locais.
     */
    public static function test(
        string $apiKey,
        ?ClientInterface $httpClient = null,
        ?RetryPolicy $retryPolicy = null,
        ?LoggerInterface $logger = null,
        ?string $baseUrl = null,
        float $timeoutSeconds = 10.0,
        int $maxResponseBytes = 2_097_152
    ): self {
        return new self($apiKey, Environment::Test, $httpClient, $retryPolicy, $logger, $baseUrl, $timeoutSeconds, $maxResponseBytes);
    }

    /**
     * Cria um cliente apontando para producao.
     */
    public static function prd(
        string $apiKey,
        ?ClientInterface $httpClient = null,
        ?RetryPolicy $retryPolicy = null,
        ?LoggerInterface $logger = null,
        ?string $baseUrl = null,
        float $timeoutSeconds = 10.0,
        int $maxResponseBytes = 2_097_152
    ): self {
        return new self($apiKey, Environment::Prd, $httpClient, $retryPolicy, $logger, $baseUrl, $timeoutSeconds, $maxResponseBytes);
    }

    private static function validateConfiguration(
        string $apiKey,
        Environment $environment,
        string $baseUrl,
        float $timeoutSeconds
    ): void {
        $expectedPrefix = $environment === Environment::Test ? 'sk_test_' : 'sk_prd_';
        if (preg_match('/\A[\x21-\x7E]{9,512}\z/D', $apiKey) !== 1) {
            throw new \InvalidArgumentException('apiKey invalida.');
        }
        if (!str_starts_with($apiKey, $expectedPrefix)) {
            throw new \InvalidArgumentException('apiKey nao pertence ao ambiente selecionado.');
        }
        if (!is_finite($timeoutSeconds) || $timeoutSeconds <= 0 || $timeoutSeconds > 120) {
            throw new \InvalidArgumentException('timeoutSeconds deve estar entre 0 e 120 segundos.');
        }
        self::validateBaseUrl($baseUrl, $environment);
    }

    private static function validateBaseUrl(string $baseUrl, Environment $environment): void
    {
        $parts = parse_url($baseUrl);
        $host = is_array($parts) && is_string($parts['host'] ?? null) ? strtolower($parts['host']) : '';
        $scheme = is_array($parts) && is_string($parts['scheme'] ?? null) ? strtolower($parts['scheme']) : '';
        $canonical = parse_url($environment->baseUrl());
        $canonicalHost = is_array($canonical) && is_string($canonical['host'] ?? null)
            ? strtolower($canonical['host'])
            : '';
        $canonicalScheme = is_array($canonical) && is_string($canonical['scheme'] ?? null)
            ? strtolower($canonical['scheme'])
            : '';
        $loopback = $environment === Environment::Test
            && in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true);
        $canonicalOrigin = $scheme === $canonicalScheme
            && $host === $canonicalHost
            && ($parts['port'] ?? null) === ($canonical['port'] ?? null);
        $path = is_array($parts) && is_string($parts['path'] ?? null) ? $parts['path'] : '';
        if (
            !is_array($parts)
            || $host === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || !in_array($path, ['', '/'], true)
            || (!$canonicalOrigin && !$loopback)
            || ($scheme !== 'https' && !($scheme === 'http' && $loopback))
        ) {
            throw new \InvalidArgumentException('baseUrl nao e permitida para o ambiente selecionado.');
        }
    }
}
