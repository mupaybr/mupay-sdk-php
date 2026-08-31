<?php

declare(strict_types=1);

namespace MuPag\Sdk\Tests\Resources;

use MuPag\Sdk\Http\ApiClient;
use MuPag\Sdk\Http\RetryPolicy;
use MuPag\Sdk\Resources\ChargeResource;
use MuPag\Sdk\Tests\Support\FakeHttpClient;
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

        $charge = $resource->create([
            'amount_cents' => 9900,
            'payment_method' => 'pix',
            'customer' => [
                'id' => 'customer_123',
                'name' => 'Ana Silva',
                'email' => 'ana@example.com',
                'tax_id' => '12345678901',
            ],
        ], 'idem_charge');

        self::assertSame('ch_123', $charge['id']);
        self::assertSame('/v1/charges', $http->lastRequest()->getUri()->getPath());
        self::assertSame('idem_charge', $http->lastRequest()->getHeaderLine('Idempotency-Key'));
    }

    public function testAllReturnsIteratorAcrossPages(): void
    {
        $http = new FakeHttpClient([
            new Response(200, [], '{"data":[{"charge_id":"ch_1"}],"next_cursor":"page_2"}'),
            new Response(200, [], '{"data":[{"charge_id":"ch_2"}]}'),
        ]);
        $resource = new ChargeResource(new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none()));

        $chargeIds = array_map(
            static fn (array $charge): string => $charge['charge_id'],
            iterator_to_array($resource->all(['limit' => 1]), false)
        );

        self::assertSame(['ch_1', 'ch_2'], $chargeIds);
        self::assertSame('limit=1', $http->requests()[0]->getUri()->getQuery());
        self::assertSame('limit=1&cursor=page_2', $http->requests()[1]->getUri()->getQuery());
    }

    public function testAllStopsWhenApiRepeatsCursor(): void
    {
        $http = new FakeHttpClient([
            new Response(200, [], '{"data":[],"meta":{"next_cursor":"same"}}'),
            new Response(200, [], '{"data":[],"meta":{"next_cursor":"same"}}'),
        ]);
        $resource = new ChargeResource(new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none()));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cursor repetido');
        iterator_to_array($resource->all(), false);
    }

    public function testCreateRejectsPanCvvAndUnknownFieldsBeforeNetwork(): void
    {
        $resource = new ChargeResource(new ApiClient('sk_test_123', 'https://api.test.local', new FakeHttpClient([]), RetryPolicy::none()));
        $base = [
            'amount_cents' => 9900,
            'payment_method' => 'pix',
            'customer' => [
                'id' => 'customer_123',
                'name' => 'Ana Silva',
                'email' => 'ana@example.com',
                'tax_id' => '12345678901',
            ],
        ];

        foreach (['pan', 'cvv', 'card_number'] as $field) {
            try {
                $resource->create($base + [$field => '4111111111111111'], 'idem_charge');
                self::fail('Sensitive field was accepted.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testCreateRejectsIncompleteIdentityUnsupportedDescriptorAndUnsafeCardContext(): void
    {
        $resource = new ChargeResource(new ApiClient('sk_test_123', 'https://api.test.local', new FakeHttpClient([]), RetryPolicy::none()));
        $customer = [
            'id' => 'customer_123',
            'name' => 'Ana Silva',
            'email' => 'ana@example.com',
            'tax_id' => '12345678901',
        ];
        $card = [
            'amount_cents' => 9900,
            'payment_method' => 'credit_card',
            'customer' => $customer,
            'card_token_id' => 'token_123',
        ];
        $invalidPayloads = [
            ['amount_cents' => 9900, 'payment_method' => 'pix', 'customer' => ['id' => 'customer_123']],
            ['amount_cents' => 9900, 'payment_method' => 'pix', 'customer' => array_merge($customer, ['tax_id' => '123'])],
            $card,
            $card + ['payer_ip' => 'payer.example.com'],
            $card + ['payer_ip' => '2001:db8::1::2'],
            $card + ['payer_ip' => '203.0.113.10', 'installments' => 2],
            $card + ['payer_ip' => '203.0.113.10', 'product_max_installments' => 2],
            ['amount_cents' => 9900, 'payment_method' => 'pix', 'customer' => $customer, 'soft_descriptor' => 'MUPAG'],
            ['amount_cents' => 9900, 'payment_method' => 'pix', 'customer' => $customer, 'soft_descriptor' => ' '],
            ['amount_cents' => 9900, 'payment_method' => 'pix', 'customer' => $customer, 'soft_descriptor' => []],
        ];

        foreach ($invalidPayloads as $payload) {
            try {
                $resource->create($payload, 'idem_charge');
                self::fail('Unsafe charge payload was accepted.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testCreateCardForwardsLiteralPayerIp(): void
    {
        $http = new FakeHttpClient([
            new Response(200, [], '{"data":{"id":"ch_123","status":"pending"}}'),
        ]);
        $resource = new ChargeResource(new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none()));

        $resource->create([
            'amount_cents' => 9900,
            'payment_method' => 'credit_card',
            'customer' => [
                'id' => 'customer_123',
                'name' => 'Ana Silva',
                'email' => 'ana@example.com',
                'tax_id' => '12345678901',
            ],
            'card_token_id' => 'token_123',
            'payer_ip' => '2001:db8::1',
            'installments' => 1,
            'product_max_installments' => 1,
        ], 'idem_charge');

        $payload = json_decode((string) $http->lastRequest()->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('2001:db8::1', $payload['payer_ip']);
        self::assertSame(1, $payload['product_max_installments']);
    }

    public function testAllRejectsUnsafePaginationBeforeNetwork(): void
    {
        $resource = new ChargeResource(new ApiClient('sk_test_123', 'https://api.test.local', new FakeHttpClient([]), RetryPolicy::none()));

        foreach ([['limit' => 101], ['cursor' => 'bad cursor'], ['status' => 'DROP TABLE']] as $params) {
            try {
                $resource->all($params);
                self::fail('Unsafe list params were accepted.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
