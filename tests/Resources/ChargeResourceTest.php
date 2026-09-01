<?php

declare(strict_types=1);

namespace MuPag\Sdk\Tests\Resources;

use MuPag\Sdk\Exception\OutcomeUnknownException;
use MuPag\Sdk\Http\ApiClient;
use MuPag\Sdk\Http\RetryPolicy;
use MuPag\Sdk\Resources\ChargeResource;
use MuPag\Sdk\Tests\Support\FakeHttpClient;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ChargeResourceTest extends TestCase
{
    public function testCreateDelegatesToChargesEndpoint(): void
    {
        $http = new FakeHttpClient([
            new Response(200, [], '{"data":{"charge_id":"ch_123","status":"pending","amount_cents":9900}}'),
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

        self::assertSame('ch_123', $charge['charge_id']);
        self::assertSame('/v1/charges', $http->lastRequest()->getUri()->getPath());
        self::assertSame('idem_charge', $http->lastRequest()->getHeaderLine('Idempotency-Key'));
    }

    public function testCreateNormalizesLegacyResponseIdToChargeId(): void
    {
        $http = new FakeHttpClient([
            new Response(200, [], '{"data":{"id":"ch_legacy","status":"pending","amount_cents":9900}}'),
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
        ], 'idem_legacy_charge');

        self::assertSame('ch_legacy', $charge['charge_id']);
        self::assertSame('ch_legacy', $charge['id']);
    }

    #[DataProvider('legacyAmountResponseProvider')]
    public function testCreateNormalizesLegacyAmountToAmountCents(string $responseBody): void
    {
        $http = new FakeHttpClient([
            new Response(200, [], $responseBody),
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
        ], 'idem_legacy_amount');

        self::assertSame(9900, $charge['amount_cents']);
        self::assertSame(9900, $charge['amount']);
    }

    public static function legacyAmountResponseProvider(): iterable
    {
        yield 'canonical amount absent' => [
            '{"data":{"charge_id":"ch_legacy","status":"pending","amount":9900}}',
        ];
        yield 'canonical amount null' => [
            '{"data":{"charge_id":"ch_legacy","status":"pending","amount_cents":null,"amount":9900}}',
        ];
    }

    #[DataProvider('mismatchedAmountResponseProvider')]
    public function testCreateTreatsMismatchedResponseAmountAsOutcomeUnknown(string $responseBody): void
    {
        $http = new FakeHttpClient([
            new Response(200, [], $responseBody),
        ]);
        $resource = new ChargeResource(new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none()));

        try {
            $resource->create([
                'amount_cents' => 9900,
                'payment_method' => 'pix',
                'customer' => [
                    'id' => 'customer_123',
                    'name' => 'Ana Silva',
                    'email' => 'ana@example.com',
                    'tax_id' => '12345678901',
                ],
            ], 'idem_charge_amount_mismatch');
            self::fail('Mismatched charge amount confirmed the mutation.');
        } catch (OutcomeUnknownException $exception) {
            self::assertSame('idem_charge_amount_mismatch', $exception->idempotencyKey());
            self::assertInstanceOf(\UnexpectedValueException::class, $exception->getPrevious());
            self::assertCount(1, $http->requests());
        }
    }

    public static function mismatchedAmountResponseProvider(): iterable
    {
        yield 'canonical amount' => [
            '{"data":{"charge_id":"ch_123","status":"pending","amount_cents":9901}}',
        ];
        yield 'legacy amount' => [
            '{"data":{"charge_id":"ch_123","status":"pending","amount":9901}}',
        ];
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
        self::assertCount(2, $http->requests());
        self::assertSame('limit=1', $http->requests()[0]->getUri()->getQuery());
        self::assertSame('limit=1&cursor=page_2', $http->requests()[1]->getUri()->getQuery());
    }

    public function testAllRejectsRepeatedCursorBeforeYieldingDuplicatedPage(): void
    {
        $http = new FakeHttpClient([
            new Response(200, [], '{"data":[{"charge_id":"ch_1"}],"meta":{"next_cursor":"same"}}'),
            new Response(200, [], '{"data":[{"charge_id":"ch_duplicate"}],"meta":{"next_cursor":"same"}}'),
        ]);
        $resource = new ChargeResource(new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none()));

        $observedChargeIds = [];
        try {
            foreach ($resource->all() as $charge) {
                $observedChargeIds[] = $charge['charge_id'];
            }
            self::fail('Repeated cursor was accepted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('cursor repetido', $exception->getMessage());
        }

        self::assertSame(['ch_1'], $observedChargeIds);
        self::assertCount(2, $http->requests());
    }

    public function testAllRejectsInitialCursorBeforeYieldingDuplicatedPage(): void
    {
        $http = new FakeHttpClient([
            new Response(200, [], '{"data":[{"charge_id":"ch_duplicate"}],"next_cursor":"page_1"}'),
            new Response(200, [], '{"data":[{"charge_id":"ch_duplicate_again"}],"next_cursor":"page_1"}'),
        ]);
        $resource = new ChargeResource(new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none()));

        $observedChargeIds = [];
        try {
            foreach ($resource->all(['cursor' => 'page_1']) as $charge) {
                $observedChargeIds[] = $charge['charge_id'];
            }
            self::fail('Initial cursor was accepted when returned by the API.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('cursor repetido', $exception->getMessage());
        }

        self::assertSame([], $observedChargeIds);
        self::assertCount(1, $http->requests());
    }

    /** @dataProvider malformedPaginationCursorProvider */
    public function testAllRejectsMalformedCursorBeforeYieldingItsPage(string $responseBody): void
    {
        $http = new FakeHttpClient([
            new Response(200, [], $responseBody),
        ]);
        $resource = new ChargeResource(new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none()));

        $observedChargeIds = [];
        try {
            foreach ($resource->all() as $charge) {
                $observedChargeIds[] = $charge['charge_id'];
            }
            self::fail('Malformed pagination cursor was accepted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('cursor invalido', $exception->getMessage());
        }

        self::assertSame([], $observedChargeIds);
        self::assertCount(1, $http->requests());
    }

    public static function malformedPaginationCursorProvider(): iterable
    {
        yield 'root number' => ['{"data":[{"charge_id":"ch_number"}],"next_cursor":123}'];
        yield 'root array' => ['{"data":[{"charge_id":"ch_array"}],"next_cursor":["page_2"]}'];
        yield 'root object' => ['{"data":[{"charge_id":"ch_object"}],"next_cursor":{"value":"page_2"}}'];
        yield 'meta boolean' => ['{"data":[{"charge_id":"ch_boolean"}],"meta":{"next_cursor":false}}'];
    }

    /** @dataProvider terminalPaginationCursorProvider */
    public function testAllTreatsNullAndEmptyCursorAsEndOfPagination(string $responseBody): void
    {
        $http = new FakeHttpClient([
            new Response(200, [], $responseBody),
        ]);
        $resource = new ChargeResource(new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none()));

        $chargeIds = array_map(
            static fn (array $charge): string => $charge['charge_id'],
            iterator_to_array($resource->all(), false)
        );

        self::assertSame(['ch_terminal'], $chargeIds);
        self::assertCount(1, $http->requests());
    }

    public static function terminalPaginationCursorProvider(): iterable
    {
        yield 'root null' => ['{"data":[{"charge_id":"ch_terminal"}],"next_cursor":null}'];
        yield 'root empty string' => ['{"data":[{"charge_id":"ch_terminal"}],"next_cursor":""}'];
        yield 'meta null' => ['{"data":[{"charge_id":"ch_terminal"}],"meta":{"next_cursor":null}}'];
        yield 'meta empty string' => ['{"data":[{"charge_id":"ch_terminal"}],"meta":{"next_cursor":""}}'];
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

    /** @dataProvider pixCardOnlyFieldProvider */
    public function testCreatePixRejectsCardOnlyFieldsByKeyPresence(string $field, mixed $value): void
    {
        $http = new FakeHttpClient([]);
        $resource = new ChargeResource(new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none()));
        $payload = [
            'amount_cents' => 9900,
            'payment_method' => 'pix',
            'customer' => [
                'id' => 'customer_123',
                'name' => 'Ana Silva',
                'email' => 'ana@example.com',
                'tax_id' => '12345678901',
            ],
            $field => $value,
        ];

        $this->expectException(\InvalidArgumentException::class);
        try {
            $resource->create($payload, 'idem_pix_card_field');
        } finally {
            self::assertCount(0, $http->requests());
        }
    }

    public static function pixCardOnlyFieldProvider(): iterable
    {
        yield 'empty card token' => ['card_token', ''];
        yield 'wrong card token id type' => ['card_token_id', []];
        yield 'null installments' => ['installments', null];
        yield 'null save card' => ['save_card', null];
    }

    /** @dataProvider malformedSecondaryCardTokenProvider */
    public function testCreateCardRejectsEachPresentMalformedTokenBeforeNetwork(array $tokenFields): void
    {
        $http = new FakeHttpClient([]);
        $resource = new ChargeResource(new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none()));
        $payload = [
            'amount_cents' => 9900,
            'payment_method' => 'credit_card',
            'customer' => [
                'id' => 'customer_123',
                'name' => 'Ana Silva',
                'email' => 'ana@example.com',
                'tax_id' => '12345678901',
            ],
            'payer_ip' => '203.0.113.10',
        ] + $tokenFields;

        $this->expectException(\InvalidArgumentException::class);
        try {
            $resource->create($payload, 'idem_card_tokens');
        } finally {
            self::assertCount(0, $http->requests());
        }
    }

    public static function malformedSecondaryCardTokenProvider(): iterable
    {
        yield 'valid card token does not hide null token id' => [[
            'card_token' => 'token_value',
            'card_token_id' => null,
        ]];
        yield 'valid token id does not hide malformed card token' => [[
            'card_token' => [],
            'card_token_id' => 'token_123',
        ]];
        yield 'both valid token fields remain exclusive' => [[
            'card_token' => 'token_value',
            'card_token_id' => 'token_123',
        ]];
    }

    /** @dataProvider validCardTokenProvider */
    public function testCreateCardAcceptsExactlyOneValidTokenField(string $field, string $value): void
    {
        $http = new FakeHttpClient([
            new Response(200, [], '{"data":{"charge_id":"ch_123","status":"pending","amount_cents":9900}}'),
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
            'payer_ip' => '203.0.113.10',
            $field => $value,
        ], 'idem_single_card_token');

        $payload = json_decode((string) $http->lastRequest()->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($value, $payload[$field]);
        self::assertCount(1, $http->requests());
    }

    public static function validCardTokenProvider(): iterable
    {
        yield 'ephemeral token' => ['card_token', 'token_value'];
        yield 'saved token id' => ['card_token_id', 'token_123'];
    }

    public function testCreateCardForwardsLiteralPayerIp(): void
    {
        $http = new FakeHttpClient([
            new Response(200, [], '{"data":{"id":"ch_123","status":"pending","amount_cents":9900}}'),
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

    /** @dataProvider rfc3339TimestampWindowProvider */
    public function testAllPreservesRfc3339WindowsInTheQuery(string $from, string $to): void
    {
        $http = new FakeHttpClient([
            new Response(200, [], '{"data":[]}'),
        ]);
        $resource = new ChargeResource(new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none()));

        iterator_to_array($resource->all([
            'created_at_from' => $from,
            'created_at_to' => $to,
        ]));

        parse_str($http->lastRequest()->getUri()->getQuery(), $query);
        self::assertSame($from, $query['created_at_from']);
        self::assertSame($to, $query['created_at_to']);
    }

    public static function rfc3339TimestampWindowProvider(): iterable
    {
        yield 'whole seconds' => [
            '2024-02-29T23:59:58Z',
            '2024-02-29T23:59:59Z',
        ];
        yield 'same UTC second' => [
            '2026-08-31T12:00:00.100Z',
            '2026-08-31T12:00:00.900Z',
        ];
        yield 'same instant second across offsets' => [
            '2026-08-31T12:00:00.500-03:00',
            '2026-08-31T15:00:00.600Z',
        ];
        yield 'arbitrary fractional precision' => [
            '2026-08-31T12:00:00.123456789Z',
            '2026-08-31T12:00:00.123456790Z',
        ];
        yield 'lowercase RFC 3339 separators' => [
            '2026-08-31t12:00:00.100z',
            '2026-08-31t12:00:00.200z',
        ];
    }

    /** @dataProvider nonIncreasingTimestampWindowProvider */
    public function testAllRejectsEqualOrReversedRfc3339Windows(string $from, string $to): void
    {
        $resource = new ChargeResource(new ApiClient('sk_test_123', 'https://api.test.local', new FakeHttpClient([]), RetryPolicy::none()));

        $this->expectException(\InvalidArgumentException::class);
        $resource->all([
            'created_at_from' => $from,
            'created_at_to' => $to,
        ]);
    }

    public static function nonIncreasingTimestampWindowProvider(): iterable
    {
        yield 'equal literal' => [
            '2026-08-31T12:00:00.100Z',
            '2026-08-31T12:00:00.100Z',
        ];
        yield 'equal instant across offsets' => [
            '2026-08-31T12:00:00.100-03:00',
            '2026-08-31T15:00:00.100Z',
        ];
        yield 'reversed fractional window' => [
            '2026-08-31T12:00:00.900Z',
            '2026-08-31T12:00:00.100Z',
        ];
    }

    /** @dataProvider invalidTimestampProvider */
    public function testAllRejectsInvalidRfc3339Timestamps(string $value): void
    {
        $resource = new ChargeResource(new ApiClient('sk_test_123', 'https://api.test.local', new FakeHttpClient([]), RetryPolicy::none()));

        $this->expectException(\InvalidArgumentException::class);
        $resource->all(['created_at_from' => $value]);
    }

    public static function invalidTimestampProvider(): iterable
    {
        yield 'missing timezone' => ['2026-08-31T12:00:00.100'];
        yield 'not a date' => ['not-a-dateZ'];
        yield 'relative date' => ['tomorrowZ'];
        yield 'date without time' => ['2026-08-31Z'];
        yield 'space separator' => ['2026-08-31 12:00:00Z'];
        yield 'time without seconds' => ['2026-08-31T12:00Z'];
        yield 'invalid calendar date' => ['2026-02-30T12:00:00Z'];
        yield 'invalid offset' => ['2026-08-31T12:00:00+24:00'];
    }
}
