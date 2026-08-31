<?php

declare(strict_types=1);

namespace MuPag\Sdk\Tests\Resources;

use MuPag\Sdk\Http\ApiClient;
use MuPag\Sdk\Http\RetryPolicy;
use MuPag\Sdk\Resources\ChargeResource;
use MuPag\Sdk\Tests\Support\FakeHttpClient;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class ChargeRefundResourceTest extends TestCase
{
    public function testCreatesRefundForChargeWithIdempotencyKey(): void
    {
        $http = new FakeHttpClient([
            new Response(200, [], '{"data":{"id":"rf_123","amount":12990}}'),
        ]);
        $resource = new ChargeResource(new ApiClient('sk_test_123', 'https://api.sandbox.mupag.com.br', $http, RetryPolicy::none()));

        $refund = $resource->refund('ch_123', ['amount_cents' => 12990], 'idem_refund_123');

        self::assertSame(['id' => 'rf_123', 'amount' => 12990], $refund);
        self::assertSame('/v1/charges/ch_123/refunds', $http->lastRequest()->getUri()->getPath());
        self::assertSame('idem_refund_123', $http->lastRequest()->getHeaderLine('Idempotency-Key'));
    }

    public function testRefundRequiresExplicitIdempotencyKey(): void
    {
        $http = new FakeHttpClient([
            new Response(200, [], '{"data":{"id":"rf_123","amount":12990}}'),
        ]);
        $resource = new ChargeResource(new ApiClient('sk_test_123', 'https://api.sandbox.mupag.com.br', $http, RetryPolicy::none()));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Idempotency-Key obrigatoria');

        $resource->refund('ch_123', ['amount_cents' => 12990]);
    }
}
