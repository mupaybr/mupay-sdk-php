<?php

declare(strict_types=1);

namespace Mupay\Sdk\Tests\Resources;

use Mupay\Sdk\Http\ApiClient;
use Mupay\Sdk\Http\RetryPolicy;
use Mupay\Sdk\Resources\SubscriptionResource;
use Mupay\Sdk\Tests\Support\FakeHttpClient;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class SubscriptionResourceTest extends TestCase
{
    public function testCancelUsesSubscriptionCancelEndpoint(): void
    {
        $http = new FakeHttpClient([
            new Response(200, [], '{"data":{"id":"sub_123","status":"canceled"}}'),
        ]);
        $resource = new SubscriptionResource(new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none()));

        $subscription = $resource->cancel('sub_123', 'idem_cancel');

        self::assertSame('canceled', $subscription['status']);
        self::assertSame('/v1/subscriptions/sub_123/cancel', $http->lastRequest()->getUri()->getPath());
        self::assertSame('idem_cancel', $http->lastRequest()->getHeaderLine('Idempotency-Key'));
        self::assertSame('', (string) $http->lastRequest()->getBody());
    }
}
