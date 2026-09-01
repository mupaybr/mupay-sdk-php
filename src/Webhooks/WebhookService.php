<?php

declare(strict_types=1);

namespace MuPag\Sdk\Webhooks;

use MuPag\Sdk\Exception\WebhookVerificationException;

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
        if (strlen($payload) > 1_048_576) {
            throw new WebhookVerificationException('Payload de webhook excede o limite seguro de 1 MiB.');
        }
        if ($secret === '' || strlen($secret) > 512 || trim($secret) !== $secret) {
            throw new WebhookVerificationException('Webhook secret invalido.');
        }
        if ($toleranceSeconds < 1 || $toleranceSeconds > 86_400) {
            throw new WebhookVerificationException('Tolerancia de webhook invalida.');
        }
        $parts = $this->parseSignatureHeader($signatureHeader);
        $timestampText = $parts['t'] ?? null;
        $timestamp = is_string($timestampText) && $timestampText !== '' && ctype_digit($timestampText)
            ? (int) $timestampText
            : null;
        $signature = $parts['v1'] ?? null;

        if ($timestamp === null || $timestamp <= 0 || (string) $timestamp !== $timestampText
            || $signature === null || preg_match('/\A[0-9a-fA-F]{64}\z/D', $signature) !== 1) {
            throw new WebhookVerificationException('Assinatura de webhook ausente ou malformada.');
        }

        $now ??= time();
        if (abs($now - $timestamp) > $toleranceSeconds) {
            throw new WebhookVerificationException('Timestamp do webhook fora da tolerancia.');
        }

        $expected = hash_hmac('sha256', $timestampText . '.' . $payload, $secret);
        if (!hash_equals($expected, strtolower($signature))) {
            throw new WebhookVerificationException('Assinatura de webhook invalida.');
        }

        try {
            $event = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
            $canonicalEnvelope = json_decode($payload, false, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new WebhookVerificationException('Payload de webhook nao e JSON valido.', previous: $exception);
        }

        if (!is_array($event)) {
            throw new WebhookVerificationException('Payload de webhook precisa ser um objeto JSON.');
        }
        $id = $event['id'] ?? null;
        $type = $event['type'] ?? null;
        if (!is_string($id) || $id === '' || strlen($id) > 256
            || !is_string($type) || $type === '' || strlen($type) > 128
            || !$canonicalEnvelope instanceof \stdClass
            || !property_exists($canonicalEnvelope, 'data')
            || !$canonicalEnvelope->data instanceof \stdClass) {
            throw new WebhookVerificationException('Payload de webhook nao possui campos obrigatorios validos.');
        }

        return $event;
    }

    /**
     * @return array<string, string>
     */
    private function parseSignatureHeader(string $signatureHeader): array
    {
        if (strlen($signatureHeader) > 4096) {
            throw new WebhookVerificationException('Assinatura de webhook ausente ou malformada.');
        }
        $items = explode(',', $signatureHeader);
        if (count($items) > 16) {
            throw new WebhookVerificationException('Assinatura de webhook ausente ou malformada.');
        }
        $parts = [];

        foreach ($items as $item) {
            $pair = explode('=', trim($item), 2);
            if (count($pair) !== 2 || trim($pair[0]) === '') {
                throw new WebhookVerificationException('Assinatura de webhook ausente ou malformada.');
            }
            $key = trim($pair[0]);
            if (($key === 't' || $key === 'v1') && array_key_exists($key, $parts)) {
                throw new WebhookVerificationException('Assinatura de webhook ausente ou malformada.');
            }
            if ($key === 't' || $key === 'v1') {
                $parts[$key] = trim($pair[1]);
            }
        }

        return $parts;
    }
}
