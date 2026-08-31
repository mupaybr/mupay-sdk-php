<?php

declare(strict_types=1);

namespace MuPag\Sdk\Tests\Webhooks;

use MuPag\Sdk\Exception\WebhookVerificationException;
use MuPag\Sdk\Webhooks\WebhookService;
use PHPUnit\Framework\TestCase;

final class WebhookServiceTest extends TestCase
{
    public function testConstructEventReturnsDecodedPayloadWhenSignatureIsValid(): void
    {
        $payload = '{"id":"evt_123","type":"charge.paid","data":{"charge_id":"ch_123"}}';
        $signature = 't=1700000000,v1=' . hash_hmac('sha256', '1700000000.' . $payload, 'whsec_test');

        $event = (new WebhookService())->constructEvent($payload, $signature, 'whsec_test', 1700000000);

        self::assertSame('evt_123', $event['id']);
        self::assertSame('charge.paid', $event['type']);
        self::assertSame(['charge_id' => 'ch_123'], $event['data']);
    }

    /** @dataProvider invalidCanonicalDataProvider */
    public function testConstructEventRejectsNonObjectCanonicalData(string $payload): void
    {
        $signature = 't=1700000000,v1=' . hash_hmac('sha256', '1700000000.' . $payload, 'whsec_test');

        $this->expectException(WebhookVerificationException::class);
        $this->expectExceptionMessage('campos');
        (new WebhookService())->constructEvent($payload, $signature, 'whsec_test', 1700000000);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidCanonicalDataProvider(): iterable
    {
        yield 'missing' => ['{"id":"evt_123","type":"charge.paid"}'];
        yield 'null' => ['{"id":"evt_123","type":"charge.paid","data":null}'];
        yield 'list' => ['{"id":"evt_123","type":"charge.paid","data":[]}'];
        yield 'scalar' => ['{"id":"evt_123","type":"charge.paid","data":"invalid"}'];
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

    public function testConstructEventRejectsAmbiguousOrNonCanonicalHeaders(): void
    {
        foreach ([
            't=1e3,v1=' . str_repeat('a', 64),
            't=01700000000,v1=' . str_repeat('a', 64),
            't=1700000000,t=1700000000,v1=' . str_repeat('a', 64),
            't=1700000000,v1=' . str_repeat('a', 64) . ',v1=' . str_repeat('a', 64),
        ] as $header) {
            try {
                (new WebhookService())->constructEvent('{}', $header, 'whsec_test', 1700000000);
                self::fail('Expected malformed webhook header rejection.');
            } catch (WebhookVerificationException $exception) {
                self::assertStringContainsString('malform', $exception->getMessage());
            }
        }
    }

    public function testConstructEventRejectsInvalidJsonAfterValidSignature(): void
    {
        $payload = '{invalid';
        $signature = 't=1700000000,v1=' . hash_hmac('sha256', '1700000000.' . $payload, 'whsec_test');

        $this->expectException(WebhookVerificationException::class);

        (new WebhookService())->constructEvent($payload, $signature, 'whsec_test', 1700000000);
    }

    public function testConstructEventRejectsOversizedAndIncompletePayloads(): void
    {
        $oversized = str_repeat('x', 1024 * 1024 + 1);
        try {
            (new WebhookService())->constructEvent(
                $oversized,
                't=1700000000,v1=' . str_repeat('0', 64),
                'whsec_test',
                1700000000
            );
            self::fail('Expected oversized webhook error.');
        } catch (WebhookVerificationException $exception) {
            self::assertStringContainsString('limite', $exception->getMessage());
        }

        $payload = '{"data":{}}';
        $signature = 't=1700000000,v1=' . hash_hmac('sha256', '1700000000.' . $payload, 'whsec_test');
        $this->expectException(WebhookVerificationException::class);
        $this->expectExceptionMessage('campos');
        (new WebhookService())->constructEvent($payload, $signature, 'whsec_test', 1700000000);
    }

    public function testConstructEventRejectsInvalidSecretAndTolerance(): void
    {
        $payload = '{"id":"evt_123","type":"charge.paid","data":{}}';
        $signature = 't=1700000000,v1=' . hash_hmac('sha256', '1700000000.' . $payload, 'whsec_test');

        try {
            (new WebhookService())->constructEvent($payload, $signature, '', 1700000000);
            self::fail('Expected invalid secret.');
        } catch (WebhookVerificationException $exception) {
            self::assertStringContainsString('secret', $exception->getMessage());
        }

        $this->expectException(WebhookVerificationException::class);
        $this->expectExceptionMessage('Tolerancia');
        (new WebhookService())->constructEvent($payload, $signature, 'whsec_test', 1700000000, 0);
    }
}
