<?php

declare(strict_types=1);

namespace Mupay\Sdk\Tests\Resources;

use Mupay\Sdk\Http\ApiClient;
use Mupay\Sdk\Http\RetryPolicy;
use Mupay\Sdk\Resources\ChargeResource;
use Mupay\Sdk\Tests\Support\FakeHttpClient;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class ChargeResourceTest extends TestCase
{
    public function testCreateDelegatesToChargesEndpoint(): void
    {
        $http = new FakeHttpClient([
            new Response(200, [], '{"data":{"id":"ch_123","status":"pending"}}'),
        ]);
        $resource = new ChargeResource(new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none()));

        $charge = $resource->create(['amount' => 9900], 'idem_charge');

        self::assertSame('ch_123', $charge['id']);
        self::assertSame('/v1/charges', $http->lastRequest()->getUri()->getPath());
        self::assertSame('idem_charge', $http->lastRequest()->getHeaderLine('Idempotency-Key'));
    }

    public function testAllReturnsIteratorAcrossPages(): void
    {
        $http = new FakeHttpClient([
            new Response(200, [], '{"data":[{"id":"ch_1"}],"meta":{"next_cursor":"page_2"}}'),
            new Response(200, [], '{"data":[{"id":"ch_2"}],"meta":{}}'),
        ]);
        $resource = new ChargeResource(new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none()));

        $ids = array_map(
            static fn (array $charge): string => $charge['id'],
            iterator_to_array($resource->all(['limit' => 1]), false)
        );

        self::assertSame(['ch_1', 'ch_2'], $ids);
        self::assertSame('limit=1', $http->requests()[0]->getUri()->getQuery());
        self::assertSame('limit=1&cursor=page_2', $http->requests()[1]->getUri()->getQuery());
    }
}
