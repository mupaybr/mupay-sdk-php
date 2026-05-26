<?php

declare(strict_types=1);

namespace Mupay\Sdk\Resources;

use Mupay\Sdk\Http\ApiClient;
use Mupay\Sdk\Pagination\PageIterator;

final class ChargeResource
{
    public function __construct(private readonly ApiClient $client)
    {
    }

    /**
     * Cria uma cobranca via API publica.
     *
     * A regra financeira fica no backend; aqui apenas enviamos payload, auth e
     * idempotency key para deixar o uso seguro em retries.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function create(array $params, ?string $idempotencyKey = null): array
    {
        $response = $this->client->post('/v1/charges', $params, $this->idempotencyHeader($idempotencyKey));

        return $this->data($response);
    }

    /**
     * Lista cobrancas usando paginacao automatica por cursor.
     *
     * @param array<string, mixed> $params
     */
    public function all(array $params = []): PageIterator
    {
        return new PageIterator($this->client, '/v1/charges', $params);
    }

    /**
     * @return array<string, string>
     */
    private function idempotencyHeader(?string $idempotencyKey): array
    {
        return $idempotencyKey === null ? [] : ['Idempotency-Key' => $idempotencyKey];
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function data(array $response): array
    {
        return is_array($response['data'] ?? null) ? $response['data'] : $response;
    }
}
