<?php

declare(strict_types=1);

namespace Mupay\Sdk\Webhooks;

use Mupay\Sdk\Exception\WebhookVerificationException;

final class WebhookService
{
    /**
     * Verifica a assinatura HMAC e devolve o evento decodificado.
     *
     * O payload assinado segue o contrato `{timestamp}.{raw_json_body}`. A comparacao
     * usa `hash_equals` para evitar timing attack e a tolerancia padrao e 5 minutos.
     *
     * @return array<string, mixed>
     */
    public function constructEvent(
        string $payload,
        string $signatureHeader,
        string $secret,
        ?int $now = null,
        int $toleranceSeconds = 300
    ): array {
        $parts = $this->parseSignatureHeader($signatureHeader);
        $timestamp = isset($parts['t']) && ctype_digit($parts['t']) ? (int) $parts['t'] : null;
        $signature = $parts['v1'] ?? null;

        if ($timestamp === null || $signature === null || $signature === '') {
            throw new WebhookVerificationException('Assinatura de webhook ausente ou malformada.');
        }

        $now ??= time();
        if (abs($now - $timestamp) > $toleranceSeconds) {
            throw new WebhookVerificationException('Timestamp do webhook fora da tolerancia.');
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        if (!hash_equals($expected, $signature)) {
            throw new WebhookVerificationException('Assinatura de webhook invalida.');
        }

        try {
            $event = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new WebhookVerificationException('Payload de webhook nao e JSON valido.', previous: $exception);
        }

        if (!is_array($event)) {
            throw new WebhookVerificationException('Payload de webhook precisa ser um objeto JSON.');
        }

        return $event;
    }

    /**
     * @return array<string, string>
     */
    private function parseSignatureHeader(string $signatureHeader): array
    {
        $parts = [];

        foreach (explode(',', $signatureHeader) as $item) {
            $pair = explode('=', trim($item), 2);
            if (count($pair) === 2) {
                $parts[$pair[0]] = $pair[1];
            }
        }

        return $parts;
    }
}
