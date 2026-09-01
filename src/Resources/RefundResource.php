<?php

declare(strict_types=1);

namespace MuPag\Sdk\Resources;

use MuPag\Sdk\Http\ApiClient;
use MuPag\Sdk\Pagination\CursorValidator;

final class RefundResource
{
    private const MAX_MONEY_CENTS = 9_000_000_000_000_000;
    private const ALLOWED_STATUSES = [
        'requested',
        'processing',
        'completed',
        'failed',
        'cancelled',
        'unknown',
    ];

    public function __construct(private readonly ApiClient $client)
    {
    }

    /**
     * Solicita estorno total ou parcial de uma cobranca.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function create(string $chargeId, array $params = [], ?string $idempotencyKey = null): array
    {
        $this->validateChargeId($chargeId);
        $this->validateCreateParams($params);
        $params = $this->normalizedCreateParams($params);
        return $this->client->post(
            '/v1/charges/' . rawurlencode($chargeId) . '/refunds',
            $params,
            $this->idempotencyHeader($idempotencyKey),
            fn (array $response): array => $this->validatedRefundData(
                $response,
                expectedAmount: $params['amount_cents'] ?? null,
                expectedChargeId: $chargeId,
                expectedReason: array_key_exists('reason', $params) ? $params['reason'] : null,
                correlateReason: array_key_exists('reason', $params)
            ),
            fn (array $response): array => $this->validatedRefundDataAfterAmbiguous(
                $response,
                expectedAmount: $params['amount_cents'] ?? null,
                expectedChargeId: $chargeId,
                expectedReason: array_key_exists('reason', $params) ? $params['reason'] : null,
                correlateReason: array_key_exists('reason', $params)
            )
        );
    }

    /**
     * Consulta um estorno no escopo do merchant autenticado.
     *
     * @return array<string, mixed>
     */
    public function get(string $refundId): array
    {
        $this->validateResourceId($refundId, 'Refund ID');

        return $this->validatedRefundData(
            $this->client->get('/v1/refunds/' . rawurlencode($refundId)),
            expectedRefundId: $refundId
        );
    }

    /**
     * Lista estornos da cobrança por cursor keyset bounded.
     *
     * @return array{refunds: list<array<string, mixed>>, next_cursor?: string|null}
     */
    public function listByCharge(string $chargeId, ?int $limit = null, ?string $cursor = null): array
    {
        $this->validateChargeId($chargeId);
        if ($limit !== null && ($limit < 1 || $limit > 100)) {
            throw new \InvalidArgumentException('limit deve estar entre 1 e 100.');
        }
        if ($cursor !== null && !CursorValidator::isCanonicalBase64Url($cursor)) {
            throw new \InvalidArgumentException('cursor invalido.');
        }

        $query = [];
        if ($limit !== null) {
            $query['limit'] = $limit;
        }
        if ($cursor !== null) {
            $query['cursor'] = $cursor;
        }
        $response = $this->client->get(
            '/v1/charges/' . rawurlencode($chargeId) . '/refunds',
            $query
        );
        $refunds = $response['refunds'] ?? null;
        if (!is_array($refunds) || !array_is_list($refunds)) {
            throw new \UnexpectedValueException('Resposta de listagem de refunds invalida.');
        }
        if (count($refunds) > ($limit ?? 100)) {
            throw new \UnexpectedValueException('Resposta de listagem de refunds excede o limit solicitado.');
        }
        $validatedRefunds = [];
        foreach ($refunds as $refund) {
            if (!is_array($refund)) {
                throw new \UnexpectedValueException('Resposta de listagem de refunds invalida.');
            }
            $validatedRefunds[] = $this->validatedRefund(
                $refund,
                expectedChargeId: $chargeId
            );
        }
        if (array_key_exists('next_cursor', $response)
            && $response['next_cursor'] !== null
            && $response['next_cursor'] !== ''
            && !CursorValidator::isCanonicalBase64Url($response['next_cursor'])) {
            throw new \UnexpectedValueException('Cursor de refunds invalido na resposta.');
        }

        $response['refunds'] = $validatedRefunds;
        /** @var array{refunds: list<array<string, mixed>>, next_cursor?: string|null} $response */
        return $response;
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
    private function validatedRefundData(
        array $response,
        ?int $expectedAmount = null,
        ?string $expectedRefundId = null,
        ?string $expectedChargeId = null,
        ?string $expectedReason = null,
        bool $correlateReason = false
    ): array {
        return $this->validatedRefund(
            $this->data($response),
            $expectedAmount,
            $expectedRefundId,
            $expectedChargeId,
            $expectedReason,
            $correlateReason
        );
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function validatedRefundDataAfterAmbiguous(
        array $response,
        ?int $expectedAmount = null,
        ?string $expectedRefundId = null,
        ?string $expectedChargeId = null,
        ?string $expectedReason = null,
        bool $correlateReason = false
    ): array {
        $data = $this->validatedRefundData(
            $response,
            $expectedAmount,
            $expectedRefundId,
            $expectedChargeId,
            $expectedReason,
            $correlateReason
        );
        if ($expectedAmount === null) {
            throw new \UnexpectedValueException(
                'Resposta nao confirma semanticamente o estorno total solicitado.'
            );
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function validatedRefund(
        array $data,
        ?int $expectedAmount = null,
        ?string $expectedRefundId = null,
        ?string $expectedChargeId = null,
        ?string $expectedReason = null,
        bool $correlateReason = false
    ): array
    {
        $id = $data['refund_id'] ?? $data['id'] ?? null;
        $chargeId = $data['charge_id'] ?? null;
        $amount = $data['amount_cents'] ?? $data['amount'] ?? null;
        if (!is_string($id) || !$this->validResourceId($id)) {
            throw new \UnexpectedValueException('Resposta 2xx sem refund_id valido.');
        }
        if (!is_string($chargeId) || !$this->validResourceId($chargeId)) {
            throw new \UnexpectedValueException('Resposta 2xx sem charge_id de refund valido.');
        }
        if (!is_int($amount) || $amount < 1 || $amount > self::MAX_MONEY_CENTS) {
            throw new \UnexpectedValueException('Resposta 2xx sem valor de refund valido.');
        }
        if (!is_string($data['status'] ?? null)
            || !in_array($data['status'], self::ALLOWED_STATUSES, true)) {
            throw new \UnexpectedValueException('Resposta 2xx sem status de refund valido.');
        }
        if (!$this->validTimestamp($data['requested_at'] ?? null)) {
            throw new \UnexpectedValueException('Resposta 2xx sem requested_at de refund valido.');
        }
        foreach (['psp_refund_id', 'reason', 'failure_reason'] as $optionalString) {
            if (array_key_exists($optionalString, $data)
                && $data[$optionalString] !== null
                && !is_string($data[$optionalString])) {
                throw new \UnexpectedValueException(
                    sprintf('Resposta 2xx com %s de refund invalido.', $optionalString)
                );
            }
        }
        if (array_key_exists('completed_at', $data)
            && $data['completed_at'] !== null
            && !$this->validTimestamp($data['completed_at'])) {
            throw new \UnexpectedValueException('Resposta 2xx com completed_at de refund invalido.');
        }
        if ($expectedAmount !== null && $amount !== $expectedAmount) {
            throw new \UnexpectedValueException('Resposta 2xx com valor de refund divergente.');
        }
        if ($expectedRefundId !== null && $id !== $expectedRefundId) {
            throw new \UnexpectedValueException('Resposta 2xx com refund_id divergente.');
        }
        if ($expectedChargeId !== null && $chargeId !== $expectedChargeId) {
            throw new \UnexpectedValueException('Resposta 2xx com charge_id de refund divergente.');
        }
        if ($correlateReason
            && (!array_key_exists('reason', $data) || $data['reason'] !== $expectedReason)) {
            throw new \UnexpectedValueException('Resposta 2xx com reason de refund divergente.');
        }

        $data['refund_id'] = $id;
        $data['charge_id'] = $chargeId;
        $data['amount_cents'] = $amount;
        return $data;
    }

    private function validateChargeId(string $chargeId): void
    {
        $this->validateResourceId($chargeId, 'Charge ID');
    }

    private function validateResourceId(string $value, string $field): void
    {
        if (!$this->validResourceId($value)) {
            throw new \InvalidArgumentException($field . ' invalido.');
        }
    }

    private function validResourceId(string $value): bool
    {
        return $value !== '.'
            && $value !== '..'
            && preg_match('/\A[A-Za-z0-9._~-]{1,256}\z/D', $value) === 1;
    }

    private function validTimestamp(mixed $value): bool
    {
        if (!is_string($value) || strlen($value) > 64) {
            return false;
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
            return false;
        }
        $zone = strtolower($parts['zone']) === 'z' ? '+00:00' : $parts['zone'];
        $timestamp = \DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:sP',
            $parts['date'] . 'T' . $parts['time'] . $zone
        );
        $errors = \DateTimeImmutable::getLastErrors();

        return $timestamp !== false
            && (!is_array($errors)
                || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
    }

    /**
     * @param array<string, mixed> $params
     */
    private function validateCreateParams(array $params): void
    {
        if (array_diff(array_keys($params), ['amount_cents', 'full', 'reason']) !== []) {
            throw new \InvalidArgumentException('Refund contém campos desconhecidos.');
        }
        $hasAmount = array_key_exists('amount_cents', $params);
        $isFull = ($params['full'] ?? null) === true;
        if ($hasAmount === $isFull || (array_key_exists('full', $params) && !$isFull)) {
            throw new \InvalidArgumentException('Informe exatamente um de amount_cents ou full=true.');
        }
        if ($hasAmount
            && (!is_int($params['amount_cents'])
                || $params['amount_cents'] < 1
                || $params['amount_cents'] > self::MAX_MONEY_CENTS)) {
            throw new \InvalidArgumentException('amount_cents invalido para refund.');
        }
        if (array_key_exists('reason', $params)
            && (!is_string($params['reason'])
                || strlen($params['reason']) > 500
                || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $params['reason']) === 1)) {
            throw new \InvalidArgumentException('reason invalido para refund.');
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function normalizedCreateParams(array $params): array
    {
        if (!array_key_exists('reason', $params)) {
            return $params;
        }

        $reason = preg_replace('/\A[\x00-\x20]+|[\x00-\x20]+\z/D', '', $params['reason']);
        if ($reason === null || $reason === '') {
            unset($params['reason']);
        } else {
            $params['reason'] = $reason;
        }
        return $params;
    }
}
