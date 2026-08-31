<?php

declare(strict_types=1);

namespace MuPag\Sdk\Resources;

use MuPag\Sdk\Http\ApiClient;

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
    public function cancel(
        string $id,
        string $mode,
        ?string $reason = null,
        ?string $idempotencyKey = null
    ): array
    {
        if ($id === '' || strlen($id) > 256 || preg_match('/\A[\x21-\x7E]+\z/D', $id) !== 1) {
            throw new \InvalidArgumentException('Subscription ID invalido.');
        }
        if (!in_array($mode, ['immediate', 'end_of_period'], true)) {
            throw new \InvalidArgumentException('mode deve ser immediate ou end_of_period.');
        }
        if ($reason !== null && strlen($reason) > 500) {
            throw new \InvalidArgumentException('reason excede 500 caracteres.');
        }
        $payload = ['mode' => $mode];
        if ($reason !== null && $reason !== '') {
            $payload['reason'] = $reason;
        }
        return $this->client->post(
            '/v1/subscriptions/' . rawurlencode($id) . '/cancel',
            $payload,
            $idempotencyKey === null ? [] : ['Idempotency-Key' => $idempotencyKey],
            function (array $response): array {
                $data = is_array($response['data'] ?? null) ? $response['data'] : $response;
                if (!is_string($data['id'] ?? null)
                    || preg_match('/\A[A-Za-z0-9._~-]{1,256}\z/D', $data['id']) !== 1
                    || !is_string($data['status'] ?? null)
                    || $data['status'] === '') {
                    throw new \UnexpectedValueException('Resposta 2xx de subscription invalida.');
                }

                return $data;
            }
        );
    }
}
