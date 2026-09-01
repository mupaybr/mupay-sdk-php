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
            fn (array $response): array => $this->validatedChargeData($response, $params['amount_cents'])
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
        return new PageIterator(
            $this->client,
            '/v1/charges',
            $params,
            fn (array $item): array => $this->validatedListCharge($item, $params)
        );
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
    private function validatedChargeData(array $response, int $expectedAmount): array
    {
        $data = $this->data($response);
        $id = $data['charge_id'] ?? $data['id'] ?? null;
        $amount = $data['amount_cents'] ?? $data['amount'] ?? null;
        if (!is_string($id) || !$this->validResourceId($id)) {
            throw new \UnexpectedValueException('Resposta 2xx sem charge_id valido.');
        }
        if (!is_string($data['status'] ?? null) || !in_array($data['status'], self::ALLOWED_STATUSES, true)) {
            throw new \UnexpectedValueException('Resposta 2xx sem status de charge valido.');
        }
        if (!is_int($amount) || $amount < 1 || $amount > self::MAX_MONEY_CENTS) {
            throw new \UnexpectedValueException('Resposta 2xx sem valor financeiro valido.');
        }
        if ($amount !== $expectedAmount) {
            throw new \UnexpectedValueException('Resposta 2xx com valor financeiro divergente.');
        }

        $data['charge_id'] = $id;
        $data['amount_cents'] = $amount;
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
        if (array_key_exists('installments', $params)
            && (!is_int($params['installments']) || $params['installments'] !== 1)) {
            throw new \InvalidArgumentException('installments deve ser 1 quando informado.');
        }
        if (array_key_exists('product_max_installments', $params)
            && (!is_int($params['product_max_installments']) || $params['product_max_installments'] !== 1)) {
            throw new \InvalidArgumentException('product_max_installments deve ser 1 quando informado.');
        }
        $payerIp = $params['payer_ip'] ?? null;
        if ($payerIp !== null
            && (!is_string($payerIp) || filter_var($payerIp, FILTER_VALIDATE_IP) === false)) {
            throw new \InvalidArgumentException('payer_ip deve ser um IP literal IPv4 ou IPv6.');
        }
        $hasCardToken = array_key_exists('card_token', $params);
        $hasCardTokenId = array_key_exists('card_token_id', $params);
        if ($paymentMethod === 'credit_card') {
            if ($hasCardToken
                && (!is_string($params['card_token']) || $params['card_token'] === '')) {
                throw new \InvalidArgumentException('card_token invalido.');
            }
            if ($hasCardTokenId
                && (!is_string($params['card_token_id']) || $params['card_token_id'] === '')) {
                throw new \InvalidArgumentException('card_token_id invalido.');
            }
            if ($hasCardToken === $hasCardTokenId) {
                throw new \InvalidArgumentException('credit_card exige exatamente um token do PSP.');
            }
        }
        if ($paymentMethod === 'credit_card' && $payerIp === null) {
            throw new \InvalidArgumentException('credit_card exige payer_ip literal do pagador.');
        }
        if ($paymentMethod === 'pix'
            && ($hasCardToken
                || $hasCardTokenId
                || array_key_exists('installments', $params)
                || array_key_exists('save_card', $params))) {
            throw new \InvalidArgumentException('PIX não aceita campos de cartão.');
        }
        $this->validateSplitRules(
            array_key_exists('split_rules', $params) ? $params['split_rules'] : [],
            $params['amount_cents']
        );
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function validatedListCharge(array $data, array $filters): array
    {
        $id = $data['charge_id'] ?? $data['id'] ?? null;
        $amount = $data['amount_cents'] ?? $data['amount'] ?? null;
        if (!is_string($id) || !$this->validResourceId($id)) {
            throw new \UnexpectedValueException('Listagem retornou charge_id invalido.');
        }
        if (!is_string($data['status'] ?? null)
            || !in_array($data['status'], self::ALLOWED_STATUSES, true)) {
            throw new \UnexpectedValueException('Listagem retornou status de charge invalido.');
        }
        if (!is_int($amount) || $amount < 100 || $amount > self::MAX_MONEY_CENTS) {
            throw new \UnexpectedValueException('Listagem retornou valor de charge invalido.');
        }
        try {
            $createdAt = $this->timestamp($data['created_at'] ?? null, 'created_at');
        } catch (\InvalidArgumentException $exception) {
            throw new \UnexpectedValueException('Listagem retornou created_at invalido.', previous: $exception);
        }
        if ($createdAt === null) {
            throw new \UnexpectedValueException('Listagem retornou charge sem created_at.');
        }
        if (isset($filters['status']) && $data['status'] !== $filters['status']) {
            throw new \UnexpectedValueException('Listagem retornou charge fora do status solicitado.');
        }
        $from = $this->timestamp($filters['created_at_from'] ?? null, 'created_at_from');
        if ($from !== null && $this->compareTimestamps($createdAt, $from) < 0) {
            throw new \UnexpectedValueException('Listagem retornou charge anterior a created_at_from.');
        }
        $to = $this->timestamp($filters['created_at_to'] ?? null, 'created_at_to');
        if ($to !== null && $this->compareTimestamps($createdAt, $to) >= 0) {
            throw new \UnexpectedValueException('Listagem retornou charge em ou apos created_at_to.');
        }

        $data['charge_id'] = $id;
        $data['amount_cents'] = $amount;
        return $data;
    }

    /**
     * @param mixed $rules
     */
    private function validateSplitRules(mixed $rules, int $amountCents): void
    {
        if (!is_array($rules) || !array_is_list($rules) || count($rules) > 50) {
            throw new \InvalidArgumentException('split_rules deve ser uma lista com no máximo 50 regras.');
        }
        $allocatedCents = 0;
        $totalBps = 0;
        foreach ($rules as $rule) {
            if (!is_array($rule)
                || array_diff(array_keys($rule), ['recipient_id', 'value_type', 'value_bps', 'value_cents']) !== []
                || !is_string($rule['recipient_id'] ?? null)
                || !$this->validResourceId($rule['recipient_id'])) {
                throw new \InvalidArgumentException('Regra de split invalida.');
            }
            $ruleCents = 0;
            if (($rule['value_type'] ?? null) === 'fixed_amount') {
                if (array_diff(array_keys($rule), ['recipient_id', 'value_type', 'value_cents']) !== []
                    || !is_int($rule['value_cents'] ?? null)
                    || $rule['value_cents'] < 1
                    || $rule['value_cents'] > $amountCents) {
                    throw new \InvalidArgumentException('Split fixed_amount exige somente value_cents valido.');
                }
                $ruleCents = $rule['value_cents'];
            } elseif (($rule['value_type'] ?? null) === 'percentage_of_gross') {
                if (array_diff(array_keys($rule), ['recipient_id', 'value_type', 'value_bps']) !== []
                    || !is_int($rule['value_bps'] ?? null)
                    || $rule['value_bps'] < 1
                    || $rule['value_bps'] > 10_000
                    || $totalBps > 10_000 - $rule['value_bps']) {
                    throw new \InvalidArgumentException('Split percentual excede 100% ou possui value_bps invalido.');
                }
                $totalBps += $rule['value_bps'];
                $ruleCents = $this->splitPercentageCents($amountCents, $rule['value_bps']);
            } else {
                throw new \InvalidArgumentException('value_type invalido em split_rules.');
            }
            if ($allocatedCents > $amountCents - $ruleCents) {
                throw new \InvalidArgumentException('Agregado de split_rules excede o valor da cobrança.');
            }
            $allocatedCents += $ruleCents;
        }
    }

    private function splitPercentageCents(int $amountCents, int $valueBps): int
    {
        return intdiv($amountCents, 10_000) * $valueBps
            + intdiv(($amountCents % 10_000) * $valueBps, 10_000);
    }

    private function validResourceId(string $value): bool
    {
        return $value !== '.'
            && $value !== '..'
            && preg_match('/\A[A-Za-z0-9._~-]{1,256}\z/D', $value) === 1;
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
        if ($from !== null && $to !== null && $this->compareTimestamps($from, $to) >= 0) {
            throw new \InvalidArgumentException('created_at_from deve ser anterior a created_at_to.');
        }
    }

    /** @return array{seconds: int, fraction: string}|null */
    private function timestamp(mixed $value, string $field): ?array
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || strlen($value) > 64) {
            throw new \InvalidArgumentException($field . ' invalido.');
        }
        $matched = preg_match(
            '/\A(?<date>[0-9]{4}-(?:0[1-9]|1[0-2])-(?:0[1-9]|[12][0-9]|3[01]))'
                . '[Tt](?<time>(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9])'
                . '(?:\.(?<fraction>[0-9]+))?'
                . '(?<zone>[Zz]|[+-](?:[01][0-9]|2[0-3]):[0-5][0-9])\z/D',
            $value,
            $parts,
            PREG_UNMATCHED_AS_NULL
        );
        if ($matched !== 1) {
            throw new \InvalidArgumentException($field . ' invalido.');
        }
        $zone = strtolower($parts['zone']) === 'z' ? '+00:00' : $parts['zone'];
        $timestamp = \DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:sP',
            $parts['date'] . 'T' . $parts['time'] . $zone
        );
        $errors = \DateTimeImmutable::getLastErrors();
        if ($timestamp === false
            || (is_array($errors)
                && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))) {
            throw new \InvalidArgumentException($field . ' invalido.');
        }

        return [
            'seconds' => $timestamp->getTimestamp(),
            'fraction' => $parts['fraction'] ?? '0',
        ];
    }

    /**
     * @param array{seconds: int, fraction: string} $left
     * @param array{seconds: int, fraction: string} $right
     */
    private function compareTimestamps(array $left, array $right): int
    {
        $secondsComparison = $left['seconds'] <=> $right['seconds'];
        if ($secondsComparison !== 0) {
            return $secondsComparison;
        }

        $precision = max(strlen($left['fraction']), strlen($right['fraction']));
        return strcmp(
            str_pad($left['fraction'], $precision, '0', STR_PAD_RIGHT),
            str_pad($right['fraction'], $precision, '0', STR_PAD_RIGHT)
        );
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
