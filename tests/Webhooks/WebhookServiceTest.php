<?php

declare(strict_types=1);

namespace Mupay\Sdk\Tests\Webhooks;

use Mupay\Sdk\Exception\WebhookVerificationException;
use Mupay\Sdk\Webhooks\WebhookService;
use PHPUnit\Framework\TestCase;

final class WebhookServiceTest extends TestCase
{
    public function testConstructEventReturnsDecodedPayloadWhenSignatureIsValid(): void
    {
        $payload = '{"id":"evt_123","type":"charge.paid"}';
        $signature = 't=1700000000,v1=' . hash_hmac('sha256', '1700000000.' . $payload, 'whsec_test');

        $event = (new WebhookService())->constructEvent($payload, $signature, 'whsec_test', 1700000000);

        self::assertSame('evt_123', $event['id']);
        self::assertSame('charge.paid', $event['type']);
    }

    public function testConstructEventRejectsStaleTimestamp(): void
    {
        $payload = '{"id":"evt_123"}';
        $signature = 't=1700000000,v1=' . hash_hmac('sha256', '1700000000.' . $payload, 'whsec_test');

        $this->expectException(WebhookVerificationException::class);

        (new WebhookService())->constructEvent($payload, $signature, 'whsec_test', 1700000401);
    }

    public function testConstructEventRejectsInvalidSignature(): void
    {
        $this->expectException(WebhookVerificationException::class);

        (new WebhookService())->constructEvent('{"id":"evt_123"}', 't=1700000000,v1=bad', 'whsec_test', 1700000000);
    }

    public function testConstructEventRejectsMalformedHeader(): void
    {
        $this->expectException(WebhookVerificationException::class);

        (new WebhookService())->constructEvent('{"id":"evt_123"}', 'not-a-signature', 'whsec_test', 1700000000);
    }

    public function testConstructEventRejectsInvalidJsonAfterValidSignature(): void
    {
        $payload = '{invalid';
        $signature = 't=1700000000,v1=' . hash_hmac('sha256', '1700000000.' . $payload, 'whsec_test');

        $this->expectException(WebhookVerificationException::class);

        (new WebhookService())->constructEvent($payload, $signature, 'whsec_test', 1700000000);
    }
}
