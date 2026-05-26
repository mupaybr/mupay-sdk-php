<?php

declare(strict_types=1);

namespace Mupay\Sdk\Resources;

use Mupay\Sdk\Http\ApiClient;

final class SubscriptionResource
{
    public function __construct(private readonly ApiClient $client)
    {
    }

    /**
     * Cancela uma assinatura pelo endpoint publico de cancelamento.
     *
     * Usamos POST cancel em vez de deletar localmente para preservar auditoria e
     * deixar o backend decidir transicoes de estado validas.
     *
     * @return array<string, mixed>
     */
    public function cancel(string $id, ?string $idempotencyKey = null): array
    {
        $response = $this->client->post(
            '/v1/subscriptions/' . rawurlencode($id) . '/cancel',
            null,
            $idempotencyKey === null ? [] : ['Idempotency-Key' => $idempotencyKey]
        );

        return is_array($response['data'] ?? null) ? $response['data'] : $response;
    }
}
