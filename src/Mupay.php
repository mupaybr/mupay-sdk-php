<?php

declare(strict_types=1);

namespace Mupay\Sdk;

use Mupay\Sdk\Http\ApiClient;
use Mupay\Sdk\Http\RetryPolicy;
use Mupay\Sdk\Resources\ChargeResource;
use Mupay\Sdk\Resources\SubscriptionResource;
use Mupay\Sdk\Webhooks\WebhookService;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;

final class Mupay
{
    public readonly ChargeResource $charges;
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
        Environment $environment = Environment::Live,
        ?ClientInterface $httpClient = null,
        ?RetryPolicy $retryPolicy = null,
        ?LoggerInterface $logger = null,
        ?string $baseUrl = null,
        float $timeoutSeconds = 10.0
    ) {
        $client = new ApiClient(
            $apiKey,
            $baseUrl ?? $environment->baseUrl(),
            $httpClient,
            $retryPolicy,
            $logger,
            $timeoutSeconds
        );

        $this->charges = new ChargeResource($client);
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
        float $timeoutSeconds = 10.0
    ): self {
        return new self($apiKey, Environment::Test, $httpClient, $retryPolicy, $logger, $baseUrl, $timeoutSeconds);
    }

    /**
     * Cria um cliente apontando para producao.
     */
    public static function live(
        string $apiKey,
        ?ClientInterface $httpClient = null,
        ?RetryPolicy $retryPolicy = null,
        ?LoggerInterface $logger = null,
        ?string $baseUrl = null,
        float $timeoutSeconds = 10.0
    ): self {
        return new self($apiKey, Environment::Live, $httpClient, $retryPolicy, $logger, $baseUrl, $timeoutSeconds);
    }
}
