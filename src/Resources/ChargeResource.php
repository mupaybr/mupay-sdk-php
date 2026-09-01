<?php

declare(strict_types=1);

namespace MuPag\Sdk\Resources;

use MuPag\Sdk\Http\ApiClient;
use MuPag\Sdk\Pagination\CursorValidator;
use MuPag\Sdk\Pagination\PageIterator;

final class ChargeResource
{
    private const MAX_MONEY_CENTS = 9_000_000_000_000_000;
    private const MAX_INPUT_NESTING_DEPTH = 32;
    private const MIN_EXPIRATION_SECONDS = 60;
    private const MAX_EXPIRATION_SECONDS = 2_147_483_647;
    private const ALLOWED_STATUSES = [
        'created',
        'pending',
        'authorized',
        'under_review',
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
        $params = $this->validateCreateParams($params);
        $couponCode = null;
        if (is_string($params['coupon_code'] ?? null) && trim($params['coupon_code']) !== '') {
            $couponCode = $params['coupon_code'];
        }
        $customerId = is_string($params['customer']['id'] ?? null)
            ? $params['customer']['id']
            : null;
        $externalReference = is_string($params['external_reference'] ?? null)
            ? $params['external_reference']
            : null;
        $cardTokenId = is_string($params['card_token_id'] ?? null)
            ? $params['card_token_id']
            : null;
        $allowGeneratedCardTokenId = $cardTokenId === null && ($params['save_card'] ?? null) === true;
        return $this->client->post(
            '/v1/charges',
            $params,
            $this->idempotencyHeader($idempotencyKey),
            fn (array $response): array => $this->validatedChargeData(
                $response,
                $params['payment_method'],
                $params['amount_cents'],
                $couponCode,
                $customerId,
                $externalReference,
                $cardTokenId,
                $allowGeneratedCardTokenId
            ),
            $couponCode === null ? null : fn (array $data): array => $this->validatedAmbiguousCouponData(
                $data,
                $params['amount_cents'],
                $couponCode
            )
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
    private function validatedChargeData(
        array $response,
        string $expectedPaymentMethod,
        int $expectedAmount,
        ?string $expectedCouponCode,
        ?string $expectedCustomerId,
        ?string $expectedExternalReference,
        ?string $expectedCardTokenId,
        bool $allowGeneratedCardTokenId
    ): array
    {
        $data = $this->data($response);
        $id = $this->aliasedValue($data, 'charge_id', 'id');
        $amount = $this->aliasedValue($data, 'amount_cents', 'amount');
        if (!is_string($id) || !$this->validResourceId($id)) {
            throw new \UnexpectedValueException('Resposta 2xx sem charge_id valido.');
        }
        if (!is_string($data['status'] ?? null) || !in_array($data['status'], self::ALLOWED_STATUSES, true)) {
            throw new \UnexpectedValueException('Resposta 2xx sem status de charge valido.');
        }
        if (!is_int($amount) || $amount < 1 || $amount > self::MAX_MONEY_CENTS) {
            throw new \UnexpectedValueException('Resposta 2xx sem valor financeiro valido.');
        }
        if (array_key_exists('payment_method', $data)
            && (!is_string($data['payment_method'])
                || !hash_equals($expectedPaymentMethod, $data['payment_method']))) {
            throw new \UnexpectedValueException('Resposta 2xx diverge do payment_method solicitado.');
        }
        $this->validateCustomerEcho(
            $data,
            $expectedCustomerId,
            'Resposta 2xx diverge do customer solicitado.'
        );
        $this->validateOptionalIntentEcho(
            $data,
            'external_reference',
            $expectedExternalReference,
            false
        );
        $this->validateOptionalIntentEcho(
            $data,
            'card_token_id',
            $expectedCardTokenId,
            $allowGeneratedCardTokenId
        );
        $this->validateOptionalChargeResponseFields(
            $data,
            [
                'psp_charge_id',
                'card_brand',
                'card_last4',
                'three_ds_acs_url',
                'failure_classification',
                'pix_qr_code_base64',
                'pix_emv_code',
            ],
            true,
            'Resposta 2xx'
        );
        if (($expectedCouponCode !== null && $amount > $expectedAmount)
            || ($expectedCouponCode === null && $amount !== $expectedAmount)) {
            throw new \UnexpectedValueException('Resposta 2xx com valor financeiro divergente.');
        }
        $this->validateCouponEvidence($data, $expectedAmount, $expectedCouponCode);

        $data['charge_id'] = $id;
        $data['amount_cents'] = $amount;
        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function validatedAmbiguousCouponData(
        array $data,
        int $expectedAmount,
        string $expectedCouponCode
    ): array {
        $hasEvidence = $this->validateCouponEvidence($data, $expectedAmount, $expectedCouponCode);
        if (($data['amount_cents'] ?? null) === $expectedAmount) {
            return $data;
        }
        if (!$hasEvidence) {
            throw new \UnexpectedValueException(
                'Resposta 2xx descontada nao correlaciona cupom apos tentativa ambigua.'
            );
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    private function validateCouponEvidence(
        array $data,
        int $expectedAmount,
        ?string $expectedCouponCode
    ): bool {
        $hasEvidence = false;
        if (array_key_exists('coupon_code', $data)) {
            $actualCouponCode = $data['coupon_code'];
            if (($expectedCouponCode === null && $actualCouponCode !== null)
                || ($expectedCouponCode !== null
                    && (!is_string($actualCouponCode)
                        || !hash_equals($expectedCouponCode, $actualCouponCode)))) {
                throw new \UnexpectedValueException('Resposta 2xx diverge do coupon_code solicitado.');
            }
            $hasEvidence = $expectedCouponCode !== null;
        }
        foreach (['original_amount_cents', 'amount_subtotal_cents'] as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            if (!is_int($data[$field]) || $data[$field] !== $expectedAmount) {
                throw new \UnexpectedValueException('Resposta 2xx diverge do valor anterior ao desconto.');
            }
            $hasEvidence = true;
        }
        return $hasEvidence;
    }

    /** @param array<string, mixed> $data */
    private function validateCustomerEcho(array $data, ?string $expectedCustomerId, string $message): void
    {
        $hasCustomerId = array_key_exists('customer_id', $data);
        $hasCustomer = array_key_exists('customer', $data);
        if ($hasCustomer
            && (!is_array($data['customer']) || !array_key_exists('id', $data['customer']))) {
            throw new \UnexpectedValueException($message);
        }
        $hasNestedCustomerId = $hasCustomer;
        if (!$hasCustomerId && !$hasNestedCustomerId) {
            return;
        }
        $customerId = $hasCustomerId ? $data['customer_id'] : null;
        $nestedCustomerId = $hasNestedCustomerId ? $data['customer']['id'] : null;
        $echoes = [[$hasCustomerId, $customerId], [$hasNestedCustomerId, $nestedCustomerId]];
        if ($hasCustomerId
            && $customerId !== null
            && (!is_string($customerId) || !$this->validResourceId($customerId))) {
            throw new \UnexpectedValueException($message);
        }
        if ($hasNestedCustomerId
            && (!is_string($nestedCustomerId) || !$this->validResourceId($nestedCustomerId))) {
            throw new \UnexpectedValueException($message);
        }
        if ($hasCustomerId && $hasNestedCustomerId && $customerId !== $nestedCustomerId) {
            throw new \UnexpectedValueException($message);
        }
        if ($expectedCustomerId === null) {
            return;
        }
        foreach ($echoes as [$present, $echo]) {
            if ($present && (!is_string($echo) || !hash_equals($expectedCustomerId, $echo))) {
                throw new \UnexpectedValueException($message);
            }
        }
    }

    /** @param array<string, mixed> $data */
    private function validateOptionalIntentEcho(
        array $data,
        string $field,
        ?string $expected,
        bool $allowGeneratedValue
    ): void {
        if (!array_key_exists($field, $data)) {
            return;
        }
        $actual = $data[$field];
        if ($expected === null) {
            if ($actual === null
                || ($allowGeneratedValue && is_string($actual) && $this->validResourceId($actual))) {
                return;
            }
        } elseif (is_string($actual) && hash_equals($expected, $actual)) {
            return;
        }
        throw new \UnexpectedValueException('Resposta 2xx diverge de ' . $field . ' solicitado.');
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $optionalStrings
     */
    private function validateOptionalChargeResponseFields(
        array $data,
        array $optionalStrings,
        bool $nullable,
        string $context
    ): void {
        foreach ($optionalStrings as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            if (($data[$field] === null && !$nullable)
                || ($data[$field] !== null && !is_string($data[$field]))) {
                throw new \UnexpectedValueException($context . ' com ' . $field . ' invalido.');
            }
        }
        if (!array_key_exists('expires_at', $data)) {
            return;
        }
        if ($data['expires_at'] === null) {
            if ($nullable) {
                return;
            }
            throw new \UnexpectedValueException($context . ' com expires_at invalido.');
        }
        try {
            $this->timestamp($data['expires_at'], 'expires_at');
        } catch (\InvalidArgumentException $exception) {
            throw new \UnexpectedValueException(
                $context . ' com expires_at invalido.',
                previous: $exception
            );
        }
    }

    /** @param array<string, mixed> $data */
    private function aliasedValue(array $data, string $primary, string $legacy): mixed
    {
        $hasPrimary = array_key_exists($primary, $data);
        $hasLegacy = array_key_exists($legacy, $data);
        if ($hasPrimary
            && $hasLegacy
            && $data[$primary] !== $data[$legacy]) {
            throw new \UnexpectedValueException('Resposta contem aliases conflitantes.');
        }
        return $hasPrimary && $data[$primary] !== null
            ? $data[$primary]
            : ($hasLegacy ? $data[$legacy] : null);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function validateCreateParams(array $params): array
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
        if (array_key_exists('metadata', $params)) {
            $params['metadata'] = $this->validatedMetadataSnapshot($params['metadata']);
        }
        $this->rejectSensitiveFields($params);
        if (!is_int($params['amount_cents'] ?? null)
            || $params['amount_cents'] < 100
            || $params['amount_cents'] > self::MAX_MONEY_CENTS) {
            throw new \InvalidArgumentException('amount_cents invalido.');
        }
        foreach (['auth_only', 'save_card', 'is_mit'] as $booleanField) {
            if (array_key_exists($booleanField, $params) && !is_bool($params[$booleanField])) {
                throw new \InvalidArgumentException($booleanField . ' deve ser booleano.');
            }
        }
        if (array_key_exists('expires_in_seconds', $params)
            && (!is_int($params['expires_in_seconds'])
                || $params['expires_in_seconds'] < self::MIN_EXPIRATION_SECONDS
                || $params['expires_in_seconds'] > self::MAX_EXPIRATION_SECONDS)) {
            throw new \InvalidArgumentException('expires_in_seconds invalido.');
        }
        $paymentMethod = $params['payment_method'] ?? null;
        if (!in_array($paymentMethod, ['pix', 'credit_card'], true)) {
            throw new \InvalidArgumentException('payment_method invalido.');
        }
        $this->validateCustomer($params['customer'] ?? null);
        foreach ([
            'description',
            'external_reference',
            'affiliate_code',
            'coupon_code',
            'initial_mit_reference_id',
        ] as $field) {
            if (array_key_exists($field, $params)
                && (!is_string($params[$field]) || $this->containsPanLikeSequence($params[$field]))) {
                throw new \InvalidArgumentException($field . ' nao pode conter PAN.');
            }
        }
        foreach (['id', 'name', 'email'] as $field) {
            if (is_string($params['customer'][$field] ?? null)
                && $this->containsPanLikeSequence($params['customer'][$field])) {
                throw new \InvalidArgumentException('customer.' . $field . ' nao pode conter PAN.');
            }
        }
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
            if ($hasCardToken && $this->containsPanLikeSequence($params['card_token'])) {
                throw new \InvalidArgumentException('card_token nao pode conter PAN.');
            }
            if ($hasCardTokenId
                && (!is_string($params['card_token_id']) || $params['card_token_id'] === '')) {
                throw new \InvalidArgumentException('card_token_id invalido.');
            }
            if ($hasCardTokenId && $this->containsPanLikeSequence($params['card_token_id'])) {
                throw new \InvalidArgumentException('card_token_id nao pode conter PAN.');
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
                || array_key_exists('product_max_installments', $params)
                || array_key_exists('save_card', $params))) {
            throw new \InvalidArgumentException('PIX não aceita campos de cartão.');
        }
        $this->validateSplitRules(
            array_key_exists('split_rules', $params) ? $params['split_rules'] : [],
            $params['amount_cents']
        );
        return $params;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function validatedListCharge(array $data, array $filters): array
    {
        $id = $this->aliasedValue($data, 'charge_id', 'id');
        $amount = $this->aliasedValue($data, 'amount_cents', 'amount');
        if (!is_string($id) || !$this->validResourceId($id)) {
            throw new \UnexpectedValueException('Listagem retornou charge_id invalido.');
        }
        if (!is_string($data['status'] ?? null)
            || !in_array($data['status'], self::ALLOWED_STATUSES, true)) {
            throw new \UnexpectedValueException('Listagem retornou status de charge invalido.');
        }
        if (!is_int($amount) || $amount < 1 || $amount > self::MAX_MONEY_CENTS) {
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
        $this->validateOptionalChargeResponseFields(
            $data,
            ['psp_charge_id', 'card_token_id', 'pix_qr_code', 'pix_copy_paste'],
            false,
            'Listagem retornou charge'
        );
        if (isset($filters['status']) && $data['status'] !== $filters['status']) {
            throw new \UnexpectedValueException('Listagem retornou charge fora do status solicitado.');
        }
        if (isset($filters['payment_method'])
            && array_key_exists('payment_method', $data)
            && (!is_string($data['payment_method'])
                || !hash_equals($filters['payment_method'], $data['payment_method']))) {
            throw new \UnexpectedValueException('Listagem retornou charge fora do payment_method solicitado.');
        }
        $this->validateCustomerEcho(
            $data,
            is_string($filters['customer_id'] ?? null) ? $filters['customer_id'] : null,
            'Listagem retornou charge fora do customer_id solicitado.'
        );
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
            if ($this->containsPanLikeSequence($rule['recipient_id'])) {
                throw new \InvalidArgumentException('recipient_id de split nao pode conter PAN.');
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
                || !$this->validResourceId($customer['id']))) {
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
                || !$this->validResourceId($params['customer_id'])
                || $this->containsPanLikeSequence($params['customer_id']))) {
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
            && !CursorValidator::isCanonicalBase64Url($params['cursor'])) {
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

    private function rejectSensitiveFields(mixed $value, int $depth = 0): void
    {
        if (is_object($value)) {
            $value = get_object_vars($value);
        } elseif (!is_array($value)) {
            return;
        }
        if ($depth > self::MAX_INPUT_NESTING_DEPTH) {
            throw new \InvalidArgumentException('Payload excede o limite de profundidade.');
        }
        foreach ($value as $key => $child) {
            if ($this->containsPanLikeSequence((string) $key)) {
                throw new \InvalidArgumentException('Metadata contem possivel numero de cartao em uma chave.');
            }
            $compact = $this->compactSensitiveKey((string) $key);
            $sensitiveBase = (string) preg_replace(
                '/(cvv|cvc|csc|cid|cav)[0-9]+/',
                '$1',
                rtrim($compact, '0123456789')
            );
            if (in_array($compact, ['pan', 'cardnumber'], true)
                || str_ends_with($sensitiveBase, 'cvv')
                || str_ends_with($sensitiveBase, 'cvc')
                || str_ends_with($sensitiveBase, 'cvvvalue')
                || str_ends_with($sensitiveBase, 'cvcvalue')
                || str_ends_with($sensitiveBase, 'cvvcode')
                || str_ends_with($sensitiveBase, 'cvccode')
                || str_ends_with($sensitiveBase, 'cvvnumber')
                || str_ends_with($sensitiveBase, 'cvcnumber')
                || str_ends_with($sensitiveBase, 'securitycode')
                || str_ends_with($sensitiveBase, 'securityvalue')
                || str_ends_with($sensitiveBase, 'cardsecuritynumber')
                || str_ends_with($sensitiveBase, 'verificationcode')
                || str_ends_with($sensitiveBase, 'verificationvalue')
                || str_ends_with($sensitiveBase, 'verificationnumber')
                || str_ends_with($sensitiveBase, 'cardidentificationnumber')
                || preg_match('/(?:^|card)cav(?:value|code|number)?$/', $sensitiveBase) === 1
                || preg_match('/(?:^|card|amex|americanexpress)(?:csc|cid)(?:value|code|number)?$/', $sensitiveBase) === 1) {
                throw new \InvalidArgumentException('Dados brutos de cartão não são aceitos.');
            }
            $this->rejectSensitiveFields($child, $depth + 1);
        }
    }

    private function compactSensitiveKey(string $key): string
    {
        if (preg_match('/[^\x00-\x7F]/', $key) === 1) {
            if (!function_exists('iconv')) {
                throw new \InvalidArgumentException(
                    'Metadata com chave Unicode nao pode ser validada com seguranca.'
                );
            }
            $normalized = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $key);
            if ($normalized === false) {
                throw new \InvalidArgumentException('Metadata contem chave Unicode invalida.');
            }
            $key = $normalized;
        }

        return (string) preg_replace('/[^a-z0-9]/', '', strtolower($key));
    }

    private function validatedMetadataSnapshot(mixed $value): mixed
    {
        try {
            $encoded = json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
            $decoded = json_decode(
                $encoded,
                false,
                512,
                JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING
            );
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('Metadata deve ser serializavel como JSON.', previous: $exception);
        }

        if (!is_object($decoded)) {
            throw new \InvalidArgumentException('Metadata deve ser um objeto JSON.');
        }

        $this->rejectSensitiveFields($decoded);
        $this->rejectDecodedPanValues($decoded);
        return $decoded;
    }

    private function rejectDecodedPanValues(mixed $value, int $depth = 0): void
    {
        if ($depth > self::MAX_INPUT_NESTING_DEPTH) {
            throw new \InvalidArgumentException('Payload excede o limite de profundidade.');
        }
        if (is_array($value) || is_object($value)) {
            foreach (is_object($value) ? get_object_vars($value) : $value as $child) {
                $this->rejectDecodedPanValues($child, $depth + 1);
            }
            return;
        }
        $panLike = is_string($value)
            ? $this->containsPanLikeSequence($value)
            : ((is_int($value) || is_float($value))
                && $this->containsPanLikeSequence(
                    json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)
                ));
        if ($panLike) {
            throw new \InvalidArgumentException('Metadata contem possivel numero de cartao.');
        }
    }

    private function containsPanLikeSequence(string $value): bool
    {
        $digits = '';
        $appendDigit = function (string $digit) use (&$digits): bool {
            if (strlen($digits) === 19) {
                $digits = substr($digits, 1);
            }
            $digits .= $digit;
            for ($length = 12, $retained = strlen($digits); $length <= $retained; $length++) {
                if ($this->validPanSequence(substr($digits, -$length))) {
                    return true;
                }
            }
            return false;
        };
        $reset = function () use (&$digits): void {
            $digits = '';
        };

        for ($offset = 0, $byteLength = strlen($value); $offset < $byteLength;) {
            $byte = ord($value[$offset]);
            if ($byte >= 48 && $byte <= 57) {
                if ($appendDigit($value[$offset])) {
                    return true;
                }
                $offset++;
                continue;
            }
            if ($byte < 0x80) {
                $offset++;
                if ($this->isAsciiMetadataSeparator($byte)) {
                    continue;
                }
                $reset();
                continue;
            }
            if (preg_match('/\G./us', $value, $match, 0, $offset) !== 1) {
                return false;
            }
            $character = $match[0];
            $offset += strlen($character);
            if (preg_match('/\A\p{Nd}\z/uD', $character) === 1) {
                return true;
            }
            if (preg_match('/\A(?:\s|\p{P}|\p{S}|\p{M}|\p{Cf}|\p{Cc})\z/uD', $character) === 1) {
                continue;
            }
            $reset();
        }
        return false;
    }

    private function isAsciiMetadataSeparator(int $byte): bool
    {
        return !(($byte >= 65 && $byte <= 90) || ($byte >= 97 && $byte <= 122));
    }

    private function validPanSequence(string $digits): bool
    {
        $length = strlen($digits);
        if ($length < 12 || $length > 19 || preg_match('/\A[0-9]+\z/D', $digits) !== 1) {
            return false;
        }
        $sum = 0;
        $doubleDigit = false;
        for ($index = $length - 1; $index >= 0; $index--) {
            $digit = ord($digits[$index]) - 48;
            if ($doubleDigit) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
            $doubleDigit = !$doubleDigit;
        }
        return $sum % 10 === 0;
    }
}
