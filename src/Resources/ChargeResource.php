<?php

declare(strict_types=1);

namespace MuPag\Sdk\Resources;

use MuPag\Sdk\Http\ApiClient;
use MuPag\Sdk\Pagination\PageIterator;

final class ChargeResource
{
    private const MAX_MONEY_CENTS = 9_000_000_000_000_000;
    private const ALLOWED_STATUSES = [
        'created',
        'pending',
        'authorized',
        'paid',
        'partially_refunded',
        'refunded',
        'failed',
        'expired',
        'cancelled',
        'disputed',
        'chargeback',
    ];

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
        $this->validateCreateParams($params);
        return $this->client->post(
            '/v1/charges',
            $params,
            $this->idempotencyHeader($idempotencyKey),
            fn (array $response): array => $this->validatedChargeData($response)
        );
    }

    /**
     * Lista cobrancas usando paginacao automatica por cursor.
     *
     * @param array<string, mixed> $params
     */
    public function all(array $params = []): PageIterator
    {
        $this->validateListParams($params);
        return new PageIterator($this->client, '/v1/charges', $params);
    }

    /**
     * Cria um estorno para uma cobranca.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function refund(string $chargeId, array $params = [], string $idempotencyKey = ''): array
    {
        if (trim($idempotencyKey) === '') {
            throw new \InvalidArgumentException('Idempotency-Key obrigatoria para refund.');
        }

        return (new RefundResource($this->client))->create($chargeId, $params, $idempotencyKey);
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

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function validatedChargeData(array $response): array
    {
        $data = $this->data($response);
        $id = $data['charge_id'] ?? $data['id'] ?? null;
        $amount = $data['amount_cents'] ?? $data['amount'] ?? null;
        if (!is_string($id) || preg_match('/\A[A-Za-z0-9._~-]{1,256}\z/D', $id) !== 1) {
            throw new \UnexpectedValueException('Resposta 2xx sem charge_id valido.');
        }
        if (!is_string($data['status'] ?? null) || !in_array($data['status'], self::ALLOWED_STATUSES, true)) {
            throw new \UnexpectedValueException('Resposta 2xx sem status de charge valido.');
        }
        if ($amount !== null && (!is_int($amount) || $amount < 1 || $amount > self::MAX_MONEY_CENTS)) {
            throw new \UnexpectedValueException('Resposta 2xx sem valor financeiro valido.');
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function validateCreateParams(array $params): void
    {
        $allowed = [
            'amount_cents',
            'payment_method',
            'installments',
            'card_token',
            'card_token_id',
            'save_card',
            'customer',
            'description',
            'soft_descriptor',
            'payer_ip',
            'auth_only',
            'product_max_installments',
            'external_reference',
            'expires_in_seconds',
            'metadata',
            'affiliate_code',
            'coupon_code',
            'split_rules',
            'is_mit',
            'initial_mit_reference_id',
        ];
        if (array_diff(array_keys($params), $allowed) !== []) {
            throw new \InvalidArgumentException('Charge contém campos desconhecidos.');
        }
        $this->rejectSensitiveFields($params);
        if (!is_int($params['amount_cents'] ?? null)
            || $params['amount_cents'] < 100
            || $params['amount_cents'] > self::MAX_MONEY_CENTS) {
            throw new \InvalidArgumentException('amount_cents invalido.');
        }
        $paymentMethod = $params['payment_method'] ?? null;
        if (!in_array($paymentMethod, ['pix', 'credit_card'], true)) {
            throw new \InvalidArgumentException('payment_method invalido.');
        }
        $this->validateCustomer($params['customer'] ?? null);
        if (array_key_exists('soft_descriptor', $params)
            && $params['soft_descriptor'] !== null
            && (!is_string($params['soft_descriptor']) || $params['soft_descriptor'] !== '')) {
            throw new \InvalidArgumentException('soft_descriptor não é suportado pelo PSP Asaas.');
        }
        if (isset($params['installments'])
            && (!is_int($params['installments']) || $params['installments'] !== 1)) {
            throw new \InvalidArgumentException('installments deve ser 1 quando informado.');
        }
        if (isset($params['product_max_installments'])
            && (!is_int($params['product_max_installments']) || $params['product_max_installments'] !== 1)) {
            throw new \InvalidArgumentException('product_max_installments deve ser 1 quando informado.');
        }
        $payerIp = $params['payer_ip'] ?? null;
        if ($payerIp !== null
            && (!is_string($payerIp) || filter_var($payerIp, FILTER_VALIDATE_IP) === false)) {
            throw new \InvalidArgumentException('payer_ip deve ser um IP literal IPv4 ou IPv6.');
        }
        $hasCardToken = is_string($params['card_token'] ?? null) && $params['card_token'] !== '';
        $hasCardTokenId = is_string($params['card_token_id'] ?? null) && $params['card_token_id'] !== '';
        if ($hasCardToken && $hasCardTokenId) {
            throw new \InvalidArgumentException('card_token e card_token_id são mutuamente exclusivos.');
        }
        if ($paymentMethod === 'credit_card' && !$hasCardToken && !$hasCardTokenId) {
            throw new \InvalidArgumentException('credit_card exige token do PSP.');
        }
        if ($paymentMethod === 'credit_card' && $payerIp === null) {
            throw new \InvalidArgumentException('credit_card exige payer_ip literal do pagador.');
        }
        if ($paymentMethod === 'pix'
            && ($hasCardToken || $hasCardTokenId || isset($params['installments']) || isset($params['save_card']))) {
            throw new \InvalidArgumentException('PIX não aceita campos de cartão.');
        }
    }

    private function validateCustomer(mixed $customer): void
    {
        if (!is_array($customer)
            || array_diff(array_keys($customer), ['id', 'name', 'email', 'tax_id']) !== []) {
            throw new \InvalidArgumentException('customer invalido.');
        }
        if (isset($customer['id'])
            && (!is_string($customer['id'])
                || preg_match('/\A[A-Za-z0-9._~-]{1,256}\z/D', $customer['id']) !== 1)) {
            throw new \InvalidArgumentException('customer.id invalido.');
        }
        if (!is_string($customer['name'] ?? null)
            || trim($customer['name']) === ''
            || strlen($customer['name']) > 200
            || !is_string($customer['email'] ?? null)
            || filter_var($customer['email'], FILTER_VALIDATE_EMAIL) === false
            || strlen($customer['email']) > 254
            || !is_string($customer['tax_id'] ?? null)
            || preg_match('/\A(?:[0-9]{11}|[0-9]{14})\z/D', $customer['tax_id']) !== 1) {
            throw new \InvalidArgumentException('customer invalido.');
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    private function validateListParams(array $params): void
    {
        $allowed = ['status', 'customer_id', 'payment_method', 'created_at_from', 'created_at_to', 'limit', 'cursor'];
        if (array_diff(array_keys($params), $allowed) !== []) {
            throw new \InvalidArgumentException('Filtros de charge contêm campos desconhecidos.');
        }
        if (isset($params['status']) && !in_array($params['status'], self::ALLOWED_STATUSES, true)) {
            throw new \InvalidArgumentException('status invalido.');
        }
        if (isset($params['customer_id'])
            && (!is_string($params['customer_id'])
                || preg_match('/\A[A-Za-z0-9._~-]{1,256}\z/D', $params['customer_id']) !== 1)) {
            throw new \InvalidArgumentException('customer_id invalido.');
        }
        if (isset($params['payment_method'])
            && !in_array($params['payment_method'], ['pix', 'credit_card'], true)) {
            throw new \InvalidArgumentException('payment_method invalido.');
        }
        if (isset($params['limit'])
            && (!is_int($params['limit']) || $params['limit'] < 1 || $params['limit'] > 100)) {
            throw new \InvalidArgumentException('limit deve estar entre 1 e 100.');
        }
        if (isset($params['cursor'])
            && (!is_string($params['cursor'])
                || preg_match('/\A[\x21-\x7E]{1,256}\z/D', $params['cursor']) !== 1)) {
            throw new \InvalidArgumentException('cursor invalido.');
        }
        $from = $this->timestamp($params['created_at_from'] ?? null, 'created_at_from');
        $to = $this->timestamp($params['created_at_to'] ?? null, 'created_at_to');
        if ($from !== null && $to !== null && $from >= $to) {
            throw new \InvalidArgumentException('created_at_from deve ser anterior a created_at_to.');
        }
    }

    private function timestamp(mixed $value, string $field): ?int
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)
            || strlen($value) > 64
            || preg_match('/(?:Z|[+-][0-9]{2}:[0-9]{2})\z/D', $value) !== 1) {
            throw new \InvalidArgumentException($field . ' invalido.');
        }
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            throw new \InvalidArgumentException($field . ' invalido.');
        }
        return $timestamp;
    }

    private function rejectSensitiveFields(mixed $value): void
    {
        if (!is_array($value)) {
            return;
        }
        foreach ($value as $key => $child) {
            $normalized = strtolower(str_replace('-', '_', (string) $key));
            if (in_array($normalized, ['pan', 'cvv', 'cvc', 'card_number', 'cardnumber', 'security_code'], true)) {
                throw new \InvalidArgumentException('Dados brutos de cartão não são aceitos.');
            }
            $this->rejectSensitiveFields($child);
        }
    }
}
