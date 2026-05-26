<?php

declare(strict_types=1);

namespace Mupay\Sdk\Tests;

use Mupay\Sdk\Mupay;
use Mupay\Sdk\Http\RetryPolicy;
use Mupay\Sdk\Resources\ChargeResource;
use Mupay\Sdk\Resources\SubscriptionResource;
use Mupay\Sdk\Tests\Support\FakeHttpClient;
use Mupay\Sdk\Webhooks\WebhookService;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class MupayTest extends TestCase
{
    public function testMupayExposesPublicResources(): void
    {
        $mupay = Mupay::test('sk_test_123');

        self::assertInstanceOf(ChargeResource::class, $mupay->charges);
        self::assertInstanceOf(SubscriptionResource::class, $mupay->subscriptions);
        self::assertInstanceOf(WebhookService::class, $mupay->webhooks);
    }

    public function testLiveFactoryUsesProductionBaseUrl(): void
    {
        $http = new FakeHttpClient([
            new Response(200, [], '{"data":{"id":"ch_live"}}'),
        ]);
        $mupay = Mupay::live('sk_live_123', $http, RetryPolicy::none());

        $mupay->charges->create(['amount' => 1000], 'idem_live');

        self::assertSame('api.mupay.com', $http->lastRequest()->getUri()->getHost());
    }
}
