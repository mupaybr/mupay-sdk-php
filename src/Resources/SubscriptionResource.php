<?php

declare(strict_types=1);

namespace MuPag\Sdk\Resources;

use MuPag\Sdk\Http\ApiClient;

final class SubscriptionResource
{
    private const SCHEDULED_CANCEL_STATUSES = [
        'trialing',
        'active',
        'past_due',
        'unpaid',
        'paused',
        'incomplete',
    ];

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
        if ($id === '.'
            || $id === '..'
            || preg_match('/\A[A-Za-z0-9._~-]{1,256}\z/D', $id) !== 1) {
            throw new \InvalidArgumentException('Subscription ID invalido.');
        }
        if (!in_array($mode, ['immediate', 'end_of_period'], true)) {
            throw new \InvalidArgumentException('mode deve ser immediate ou end_of_period.');
        }
        if ($reason !== null && strlen($reason) > 500) {
            throw new \InvalidArgumentException('reason excede 500 caracteres.');
        }
        if ($reason !== null && $this->containsPanLikeSequence($reason)) {
            throw new \InvalidArgumentException('reason invalido para cancelamento.');
        }
        $payload = ['mode' => $mode];
        if ($reason !== null && $reason !== '') {
            $payload['reason'] = $reason;
        }
        return $this->client->post(
            '/v1/subscriptions/' . rawurlencode($id) . '/cancel',
            $payload,
            $idempotencyKey === null ? [] : ['Idempotency-Key' => $idempotencyKey],
            function (array $response) use ($id, $mode): array {
                $data = is_array($response['data'] ?? null) ? $response['data'] : $response;
                $status = $data['status'] ?? null;
                $cancelAtPeriodEnd = $data['cancel_at_period_end'] ?? null;
                if (!is_string($data['id'] ?? null)
                    || preg_match('/\A[A-Za-z0-9._~-]{1,256}\z/D', $data['id']) !== 1
                    || $data['id'] !== $id
                    || !is_string($status)
                    || !is_bool($cancelAtPeriodEnd)
                    || ($mode === 'immediate'
                        && ($status !== 'canceled' || $cancelAtPeriodEnd !== false))
                    || ($mode === 'end_of_period'
                        && (!in_array($status, self::SCHEDULED_CANCEL_STATUSES, true)
                            || $cancelAtPeriodEnd !== true))) {
                    throw new \UnexpectedValueException('Resposta 2xx de subscription invalida.');
                }

                return $data;
            }
        );
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
                if ($this->isAsciiPanSeparator($byte)) {
                    continue;
                }
                $digits = '';
                continue;
            }
            if (preg_match('/\G./us', $value, $match, 0, $offset) !== 1) {
                return false;
            }
            $character = $match[0];
            $offset += strlen($character);
            if (preg_match('/\A(?:\s|\p{P}|\p{S}|\p{M}|\p{Cf})\z/uD', $character) === 1) {
                continue;
            }
            $digits = '';
        }
        return false;
    }

    private function isAsciiPanSeparator(int $byte): bool
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
