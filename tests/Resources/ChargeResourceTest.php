<?php

declare(strict_types=1);

namespace MuPag\Sdk\Tests\Resources;

use MuPag\Sdk\Exception\ApiException;
use MuPag\Sdk\Exception\OutcomeUnknownException;
use MuPag\Sdk\Http\ApiClient;
use MuPag\Sdk\Http\RetryPolicy;
use MuPag\Sdk\Resources\ChargeResource;
use MuPag\Sdk\Tests\Support\FakeHttpClient;
use MuPag\Sdk\Tests\Support\NetworkFailure;
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

    public function testCreateAcceptsDiscountedCouponAmountAndUnderReviewStatus(): void
    {
        $http = new FakeHttpClient([
            new Response(200, [], '{"data":{"charge_id":"ch_coupon","status":"under_review","amount_cents":4900}}'),
        ]);
        $resource = new ChargeResource(
            new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none())
        );
        $payload = $this->validPixChargePayload(9900);
        $payload['coupon_code'] = 'SAVE50';

        $charge = $resource->create($payload, 'idem_coupon_charge');

        self::assertSame('under_review', $charge['status']);
        self::assertSame(4900, $charge['amount_cents']);
        self::assertCount(1, $http->requests());
    }

    public function testCreateAcceptsNullCouponEchoWhenNoCouponWasRequested(): void
    {
        $http = new FakeHttpClient([
            new Response(
                200,
                [],
                '{"data":{"charge_id":"ch_without_coupon","status":"pending","amount_cents":9900,"coupon_code":null}}'
            ),
        ]);
        $resource = new ChargeResource(
            new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none())
        );

        $charge = $resource->create($this->validPixChargePayload(9900), 'idem_without_coupon');

        self::assertSame('ch_without_coupon', $charge['charge_id']);
        self::assertNull($charge['coupon_code']);
        self::assertCount(1, $http->requests());
    }

    #[DataProvider('conflictingPaymentMethodEchoProvider')]
    public function testCreateRejectsConflictingPaymentMethodEcho(mixed $paymentMethod): void
    {
        $http = new FakeHttpClient([
            new Response(
                200,
                [],
                json_encode(['data' => [
                    'charge_id' => 'ch_wrong_method',
                    'status' => 'pending',
                    'amount_cents' => 9900,
                    'payment_method' => $paymentMethod,
                ]], JSON_THROW_ON_ERROR)
            ),
        ]);
        $resource = new ChargeResource(
            new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none())
        );

        try {
            $resource->create($this->validPixChargePayload(9900), 'idem_wrong_method');
            self::fail('Conflicting payment_method echo confirmed the mutation.');
        } catch (OutcomeUnknownException $exception) {
            self::assertSame('idem_wrong_method', $exception->idempotencyKey());
            self::assertInstanceOf(\UnexpectedValueException::class, $exception->getPrevious());
            self::assertCount(1, $http->requests());
        }
    }

    public static function conflictingPaymentMethodEchoProvider(): iterable
    {
        yield 'different method' => ['credit_card'];
        yield 'explicit null' => [null];
    }

    #[DataProvider('conflictingCouponFirstResponseProvider')]
    public function testCreateRejectsConflictingCouponEvidenceOnFirstResponse(string $responseBody): void
    {
        $http = new FakeHttpClient([
            new Response(200, [], $responseBody),
        ]);
        $resource = new ChargeResource(
            new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none())
        );
        $payload = $this->validPixChargePayload(9900);
        $payload['coupon_code'] = 'SAVE50';

        try {
            $resource->create($payload, 'idem_conflicting_coupon');
            self::fail('Conflicting coupon evidence confirmed the mutation.');
        } catch (OutcomeUnknownException $exception) {
            self::assertSame('idem_conflicting_coupon', $exception->idempotencyKey());
            self::assertInstanceOf(\UnexpectedValueException::class, $exception->getPrevious());
            self::assertCount(1, $http->requests());
        }
    }

    public static function conflictingCouponFirstResponseProvider(): iterable
    {
        yield 'different coupon' => [
            '{"data":{"charge_id":"ch_coupon","status":"under_review","amount_cents":4900,"coupon_code":"OTHER"}}',
        ];
        yield 'different original amount' => [
            '{"data":{"charge_id":"ch_coupon","status":"under_review","amount_cents":4900,"original_amount_cents":10000}}',
        ];
        yield 'different subtotal amount' => [
            '{"data":{"charge_id":"ch_coupon","status":"under_review","amount_cents":4900,"amount_subtotal_cents":10000}}',
        ];
    }

    #[DataProvider('uncorrelatedCouponRetryResponseProvider')]
    public function testCreateKeepsOutcomeUnknownWhenAmbiguousRetryReturnsUncorrelatedCouponDiscount(
        string $responseBody
    ): void {
        $http = new FakeHttpClient([
            new NetworkFailure('Response lost after request dispatch'),
            new Response(200, [], $responseBody),
        ]);
        $resource = new ChargeResource(
            new ApiClient('sk_test_123', 'https://api.test.local', $http, $this->oneRetry())
        );
        $payload = $this->validPixChargePayload(9900);
        $payload['coupon_code'] = 'SAVE50';

        try {
            $resource->create($payload, 'idem_ambiguous_coupon');
            self::fail('Uncorrelated coupon discount confirmed an ambiguous mutation.');
        } catch (OutcomeUnknownException $exception) {
            self::assertSame('idem_ambiguous_coupon', $exception->idempotencyKey());
            self::assertInstanceOf(NetworkFailure::class, $exception->getPrevious());
            self::assertCount(2, $http->requests());
        }
    }

    public static function uncorrelatedCouponRetryResponseProvider(): iterable
    {
        yield 'missing evidence' => [
            '{"data":{"charge_id":"ch_coupon","status":"under_review","amount_cents":4900}}',
        ];
        yield 'different coupon' => [
            '{"data":{"charge_id":"ch_coupon","status":"under_review","amount_cents":4900,"coupon_code":"OTHER"}}',
        ];
        yield 'different original amount' => [
            '{"data":{"charge_id":"ch_coupon","status":"under_review","amount_cents":4900,"original_amount_cents":10000}}',
        ];
        yield 'contradictory evidence' => [
            '{"data":{"charge_id":"ch_coupon","status":"under_review","amount_cents":4900,"coupon_code":"OTHER","original_amount_cents":9900}}',
        ];
        yield 'gross amount with different coupon' => [
            '{"data":{"charge_id":"ch_coupon","status":"under_review","amount_cents":9900,"coupon_code":"OTHER"}}',
        ];
        yield 'gross amount with different original amount' => [
            '{"data":{"charge_id":"ch_coupon","status":"under_review","amount_cents":9900,"original_amount_cents":10000}}',
        ];
        yield 'gross amount with different subtotal amount' => [
            '{"data":{"charge_id":"ch_coupon","status":"under_review","amount_cents":9900,"amount_subtotal_cents":10000}}',
        ];
    }

    #[DataProvider('correlatedCouponRetryResponseProvider')]
    public function testCreateAcceptsCorrelatedCouponDiscountAfterAmbiguousRetry(string $responseBody): void
    {
        $http = new FakeHttpClient([
            new NetworkFailure('Response lost after request dispatch'),
            new Response(200, [], $responseBody),
        ]);
        $resource = new ChargeResource(
            new ApiClient('sk_test_123', 'https://api.test.local', $http, $this->oneRetry())
        );
        $payload = $this->validPixChargePayload(9900);
        $payload['coupon_code'] = 'SAVE50';

        $charge = $resource->create($payload, 'idem_correlated_coupon');

        self::assertSame('ch_coupon', $charge['charge_id']);
        self::assertSame(4900, $charge['amount_cents']);
        self::assertCount(2, $http->requests());
    }

    public static function correlatedCouponRetryResponseProvider(): iterable
    {
        yield 'applied coupon echo' => [
            '{"data":{"charge_id":"ch_coupon","status":"under_review","amount_cents":4900,"coupon_code":"SAVE50"}}',
        ];
        yield 'original amount echo' => [
            '{"data":{"charge_id":"ch_coupon","status":"under_review","amount_cents":4900,"original_amount_cents":9900}}',
        ];
        yield 'subtotal amount echo' => [
            '{"data":{"charge_id":"ch_coupon","status":"under_review","amount_cents":4900,"amount_subtotal_cents":9900}}',
        ];
    }

    public function testAllReturnsIteratorAcrossPages(): void
    {
        $http = new FakeHttpClient([
            new Response(200, [], '{"data":[{"charge_id":"ch_1","status":"pending","amount_cents":100,"created_at":"2026-08-31T12:00:00Z"}],"next_cursor":"Zg"}'),
            new Response(200, [], '{"data":[{"charge_id":"ch_2","status":"pending","amount_cents":100,"created_at":"2026-08-31T12:00:01Z"}]}'),
        ]);
        $resource = new ChargeResource(new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none()));

        $chargeIds = array_map(
            static fn (array $charge): string => $charge['charge_id'],
            iterator_to_array($resource->all(['limit' => 1]), false)
        );

        self::assertSame(['ch_1', 'ch_2'], $chargeIds);
        self::assertCount(2, $http->requests());
        self::assertSame('limit=1', $http->requests()[0]->getUri()->getQuery());
        self::assertSame('limit=1&cursor=Zg', $http->requests()[1]->getUri()->getQuery());
    }

    public function testAllRejectsRepeatedCursorBeforeYieldingDuplicatedPage(): void
    {
        $http = new FakeHttpClient([
            new Response(200, [], '{"data":[{"charge_id":"ch_1","status":"pending","amount_cents":100,"created_at":"2026-08-31T12:00:00Z"}],"meta":{"next_cursor":"same"}}'),
            new Response(200, [], '{"data":[{"charge_id":"ch_duplicate","status":"pending","amount_cents":100,"created_at":"2026-08-31T12:00:01Z"}],"meta":{"next_cursor":"same"}}'),
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
            new Response(200, [], '{"data":[{"charge_id":"ch_duplicate"}],"next_cursor":"Zg"}'),
            new Response(200, [], '{"data":[{"charge_id":"ch_duplicate_again"}],"next_cursor":"Zg"}'),
        ]);
        $resource = new ChargeResource(new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none()));

        $observedChargeIds = [];
        try {
            foreach ($resource->all(['cursor' => 'Zg']) as $charge) {
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
        yield 'non-canonical trailing bits' => ['{"data":[],"next_cursor":"Zh"}'];
        yield 'padded base64url' => ['{"data":[],"next_cursor":"Zg=="}'];
        yield 'outside base64url alphabet' => ['{"data":[],"next_cursor":"bad cursor"}'];
    }

    /** @dataProvider terminalPaginationCursorProvider */
    public function testAllTreatsMissingAndNullCursorAsEndOfPagination(string $responseBody): void
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
        $item = '{"charge_id":"ch_terminal","status":"pending","amount_cents":100,"created_at":"2026-08-31T12:00:00Z"}';
        yield 'missing' => ['{"data":[' . $item . ']}'];
        yield 'root null' => ['{"data":[' . $item . '],"next_cursor":null}'];
        yield 'meta null' => ['{"data":[' . $item . '],"meta":{"next_cursor":null}}'];
        yield 'root empty string' => ['{"data":[' . $item . '],"next_cursor":""}'];
        yield 'meta empty string' => ['{"data":[' . $item . '],"meta":{"next_cursor":""}}'];
    }

    /** @dataProvider invalidChargePageProvider */
    public function testAllRejectsMalformedOrFilterDivergentPageBeforeYielding(
        string $responseBody,
        array $params
    ): void {
        $http = new FakeHttpClient([new Response(200, [], $responseBody)]);
        $resource = new ChargeResource(
            new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none())
        );

        try {
            iterator_to_array($resource->all($params), false);
        } catch (\RuntimeException) {
            self::assertCount(1, $http->requests());
            return;
        }

        self::fail('Malformed or filter-divergent charge page was accepted.');
    }

    public static function invalidChargePageProvider(): iterable
    {
        $valid = [
            'charge_id' => 'ch_1',
            'status' => 'paid',
            'amount_cents' => 100,
            'created_at' => '2026-08-31T12:00:00Z',
        ];

        yield 'missing data' => ['{}', []];
        yield 'object data' => [json_encode(['data' => ['charge_id' => 'ch_1']], JSON_THROW_ON_ERROR), []];
        yield 'scalar item' => [json_encode(['data' => [123]], JSON_THROW_ON_ERROR), []];
        yield 'more than 100 items' => [
            json_encode(['data' => array_fill(0, 101, $valid)], JSON_THROW_ON_ERROR),
            [],
        ];
        yield 'missing economic field' => [
            json_encode(['data' => [array_diff_key($valid, ['amount_cents' => true])]], JSON_THROW_ON_ERROR),
            [],
        ];
        yield 'amount below list minimum' => [
            json_encode(['data' => [[...$valid, 'amount_cents' => 0]]], JSON_THROW_ON_ERROR),
            [],
        ];
        yield 'status outside filter' => [
            json_encode(['data' => [$valid]], JSON_THROW_ON_ERROR),
            ['status' => 'pending'],
        ];
        yield 'payment method outside filter' => [
            json_encode(['data' => [[...$valid, 'payment_method' => 'credit_card']]], JSON_THROW_ON_ERROR),
            ['payment_method' => 'pix'],
        ];
        yield 'customer id outside filter' => [
            json_encode(['data' => [[...$valid, 'customer_id' => 'customer_b']]], JSON_THROW_ON_ERROR),
            ['customer_id' => 'customer_a'],
        ];
        yield 'nested customer id outside filter' => [
            json_encode(['data' => [[...$valid, 'customer' => ['id' => 'customer_b']]]], JSON_THROW_ON_ERROR),
            ['customer_id' => 'customer_a'],
        ];
        yield 'before lower bound' => [
            json_encode(['data' => [[...$valid, 'created_at' => '2026-08-31T11:59:59Z']]], JSON_THROW_ON_ERROR),
            ['created_at_from' => '2026-08-31T12:00:00Z'],
        ];
        yield 'at exclusive upper bound' => [
            json_encode(['data' => [[...$valid, 'created_at' => '2026-08-31T13:00:00Z']]], JSON_THROW_ON_ERROR),
            ['created_at_to' => '2026-08-31T13:00:00Z'],
        ];
    }

    public function testAllAcceptsChargeInsideRequestedStatusAndWindow(): void
    {
        $http = new FakeHttpClient([
            new Response(200, [], '{"data":[{"charge_id":"ch_paid","status":"paid","amount_cents":1,"created_at":"2026-08-31T12:30:00Z"}]}'),
        ]);
        $resource = new ChargeResource(
            new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none())
        );

        $charges = iterator_to_array($resource->all([
            'status' => 'paid',
            'created_at_from' => '2026-08-31T12:00:00Z',
            'created_at_to' => '2026-08-31T13:00:00Z',
        ]), false);

        self::assertSame(['ch_paid'], array_column($charges, 'charge_id'));
        self::assertCount(1, $http->requests());
    }

    public function testAllAcceptsMaximumPublicPageSize(): void
    {
        $item = [
            'charge_id' => 'ch_page_item',
            'status' => 'paid',
            'amount_cents' => 1,
            'created_at' => '2026-08-31T12:30:00Z',
        ];
        $http = new FakeHttpClient([
            new Response(200, [], json_encode(['data' => array_fill(0, 100, $item)], JSON_THROW_ON_ERROR)),
        ]);
        $resource = new ChargeResource(
            new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none())
        );

        self::assertCount(100, iterator_to_array($resource->all(['limit' => 100]), false));
        self::assertCount(1, $http->requests());
    }

    public function testAllRejectsPagesBeyondTheEffectiveLimit(): void
    {
        $item = [
            'charge_id' => 'ch_page_item',
            'status' => 'paid',
            'amount_cents' => 1,
            'created_at' => '2026-08-31T12:30:00Z',
        ];

        foreach ([[[], 26], [['limit' => 2], 3]] as [$params, $count]) {
            $http = new FakeHttpClient([
                new Response(200, [], json_encode(['data' => array_fill(0, $count, $item)], JSON_THROW_ON_ERROR)),
            ]);
            $resource = new ChargeResource(
                new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none())
            );

            $rejected = false;
            try {
                iterator_to_array($resource->all($params), false);
            } catch (\RuntimeException) {
                $rejected = true;
            }
            self::assertTrue($rejected, 'Page larger than the effective limit was accepted.');
            self::assertCount(1, $http->requests());
        }
    }

    public function testAllAcceptsUnderReviewFilterAndItem(): void
    {
        $http = new FakeHttpClient([
            new Response(200, [], '{"data":[{"charge_id":"ch_review","status":"under_review","amount_cents":100,"created_at":"2026-08-31T12:30:00Z"}]}'),
        ]);
        $resource = new ChargeResource(
            new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none())
        );

        $charges = iterator_to_array($resource->all(['status' => 'under_review']), false);

        self::assertSame('under_review', $charges[0]['status']);
        self::assertCount(1, $http->requests());
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

    public function testCreateRejectsRecursiveMetadataBeforeNetwork(): void
    {
        $http = new FakeHttpClient([]);
        $resource = new ChargeResource(
            new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none())
        );
        $recursive = [];
        $recursive['self'] = &$recursive;
        $payload = $this->validPixChargePayload(100);
        $payload['metadata'] = $recursive;

        try {
            $resource->create($payload, 'idem_recursive_metadata');
            self::fail('Recursive metadata was accepted.');
        } catch (\InvalidArgumentException) {
        }

        self::assertCount(0, $http->requests());
    }

    public function testCreateRejectsListMetadataAtTheRootBeforeNetwork(): void
    {
        $http = new FakeHttpClient([]);
        $resource = new ChargeResource(
            new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none())
        );
        $payload = $this->validPixChargePayload(100);
        $payload['metadata'] = ['safe'];

        try {
            $resource->create($payload, 'idem_list_metadata');
            self::fail('List metadata was accepted at the root.');
        } catch (\InvalidArgumentException) {
        }

        self::assertCount(0, $http->requests());
    }

    /** @param array<string, mixed> $metadata */
    #[DataProvider('panLikeMetadataProvider')]
    public function testCreateRejectsPanLikeMetadataValuesBeforeNetwork(array $metadata): void
    {
        $http = new FakeHttpClient([]);
        $resource = new ChargeResource(
            new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none())
        );
        $payload = $this->validPixChargePayload(100);
        $payload['metadata'] = $metadata;

        try {
            $resource->create($payload, 'idem_pan_metadata');
            self::fail('PAN-like metadata value was accepted.');
        } catch (\InvalidArgumentException) {
        }

        self::assertCount(0, $http->requests());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function panLikeMetadataProvider(): iterable
    {
        yield 'spaces and hyphens' => [['note' => '4111 1111-1111 1111']];
        yield 'punctuation' => [['note' => '4111.1111/1111_1111']];
		yield 'symbol separators' => [['note' => '4111+1111=1111|1111']];
		yield 'Unicode format separators' => [['note' => "4111\u{200B}1111\u{200B}1111\u{200B}1111"]];
		yield 'Unicode combining marks' => [['note' => "4111\u{0301}1111\u{0301}1111\u{0301}1111"]];
		yield 'camel-case security code key' => [['securityCode' => '123']];
		yield 'punctuated security code key' => [['nested' => ['security.code' => '123']]];
		yield 'CVV2 key' => [['cvv2' => '123']];
		yield 'CVC2 key' => [['cvc2' => '123']];
		yield 'prefixed CVV key' => [['cardCvv' => '123']];
		yield 'prefixed CSC key' => [['cardCsc' => '123']];
		yield 'prefixed CID key' => [['cardCid' => '1234']];
		yield 'CAV2 key' => [['cav2' => '123']];
		yield 'card CAV2 key' => [['cardCav2' => '123']];
		yield 'numbered CAV value key' => [['cav2_value' => '123']];
		yield 'Amex CID key' => [['amexCid' => '1234']];
		yield 'American Express CID key' => [['americanExpressCid' => '1234']];
		yield 'prefixed security code key' => [['card_security_code' => '123']];
		yield 'security value key' => [['cardSecurityValue' => '123']];
		yield 'security number key' => [['cardSecurityNumber' => '123']];
		yield 'verification code key' => [['cardVerificationCode' => '123']];
		yield 'verification value key' => [['card_verification_value' => '123']];
		yield 'verification number key' => [['cardVerificationNumber' => '123']];
		yield 'identification number key' => [['cardIdentificationNumber' => '123']];
		yield 'CVV value key' => [['cvv_value' => '123']];
		yield 'CVC value key' => [['cvcValue' => '123']];
		yield 'CVV code key' => [['cardCvvCode' => '123']];
		yield 'CVC number key' => [['cardCvcNumber' => '123']];
		yield 'numbered CVV value key' => [['cvv2_value' => '123']];
		yield 'numbered CVC code key' => [['cvc2Code' => '123']];
		yield 'numbered compound CVV key' => [['cardCvv3Number' => '123']];
		yield 'numbered CSC value key' => [['csc2_value' => '123']];
		yield 'numbered CID code key' => [['cid2Code' => '1234']];
		yield 'unrelated numeric prefix' => [['note' => 'order 9 / 4111 1111 1111 1111']];
		yield 'uninterrupted numeric prefix' => [['note' => '94111111111111111']];
        yield 'exact JSON number' => [['note' => 4_111_111_111_111_111]];
		yield 'JSON float' => [['note' => 4_111_111_111_111_111.0]];
		yield 'nested value' => [['order' => [['note' => 'card 4111-1111-1111-1111']]]];
		yield 'PAN property name' => [['4111111111111111' => 'note']];
		yield 'object value' => [['note' => (object) ['value' => '4111111111111111']]];
		yield 'JsonSerializable value' => [['note' => new class implements \JsonSerializable {
			/** @return array<string, string> */
			public function jsonSerialize(): array
			{
				return ['value' => '4111111111111111'];
			}
		}]];

		foreach ([
			'412345678905',
			'4123456789011',
			'41234567890120',
			'412345678901233',
			'4123456789012349',
			'41234567890123458',
			'412345678901234561',
			'4123456789012345677',
		] as $pan) {
			yield strlen($pan) . '-digit PAN with continuous prefix' => [['note' => '9' . $pan]];
			yield strlen($pan) . '-digit PAN with continuous suffix' => [['note' => $pan . '9']];
		}
    }

    public function testCreateSendsTheSameJsonSerializableMetadataSnapshotThatWasValidated(): void
    {
        $stateful = new class implements \JsonSerializable {
            private int $calls = 0;

            /** @return array<string, string> */
            public function jsonSerialize(): array
            {
                $this->calls++;
                return $this->calls === 1
                    ? ['value' => 'safe']
                    : ['card_number' => '4111111111111111'];
            }

            public function calls(): int
            {
                return $this->calls;
            }
        };
        $http = new FakeHttpClient([
            new Response(201, [], '{"charge_id":"charge_1","status":"pending","amount_cents":100}'),
        ]);
        $resource = new ChargeResource(
            new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none())
        );
        $payload = $this->validPixChargePayload(100);
        $payload['metadata'] = ['stateful' => $stateful];

        $resource->create($payload, 'idem_stateful_metadata');

        $sent = json_decode((string) $http->lastRequest()->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['stateful' => ['value' => 'safe']], $sent['metadata']);
        self::assertSame(1, $stateful->calls());
    }

    public function testCreatePreservesJsonObjectShapeInCanonicalMetadataSnapshot(): void
    {
        $http = new FakeHttpClient([
            new Response(201, [], '{"charge_id":"charge_1","status":"pending","amount_cents":100}'),
        ]);
        $resource = new ChargeResource(
            new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none())
        );
        $payload = $this->validPixChargePayload(100);
        $payload['metadata'] = [
            'empty_object' => (object) [],
            'numeric_object' => (object) ['0' => 'safe'],
        ];

        $resource->create($payload, 'idem_object_metadata');

        $sent = json_decode((string) $http->lastRequest()->getBody(), false, 512, JSON_THROW_ON_ERROR);
        self::assertIsObject($sent->metadata->empty_object);
        self::assertIsObject($sent->metadata->numeric_object);
        self::assertSame('safe', $sent->metadata->numeric_object->{'0'});
    }

    public function testCreateAcceptsEquivalentNonLuhnMetadataValue(): void
    {
        $http = new FakeHttpClient([
            new Response(201, [], '{"charge_id":"charge_1","status":"pending","amount_cents":100}'),
        ]);
        $resource = new ChargeResource(
            new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none())
        );
        $payload = $this->validPixChargePayload(100);
        $payload['metadata'] = [
            'note' => '1000 0000 0000 1000',
            'taxIdentificationNumber' => 'BR-123',
            'orderIdentificationNumber' => 'order-123',
            'accountSecurityNumber' => 'account-123',
            'acid' => 'reference-1',
            'placid' => 'reference-2',
        ];

        $charge = $resource->create($payload, 'idem_non_luhn_metadata');

        self::assertSame('charge_1', $charge['charge_id']);
        self::assertCount(1, $http->requests());
    }

    public function testCreateRejectsOversizedMetadataWithoutMaterializingCharacters(): void
    {
        $http = new FakeHttpClient([]);
        $resource = new ChargeResource(
            new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none())
        );
        $payload = $this->validPixChargePayload(100);
        $payload['metadata'] = ['note' => str_repeat('x', 1_048_576)];

        try {
            $resource->create($payload, 'idem_oversized_metadata');
            self::fail('Oversized metadata was accepted.');
        } catch (ApiException $exception) {
            self::assertSame('request_too_large', $exception->apiCode());
        }

        self::assertCount(0, $http->requests());
    }

    /** @dataProvider dotSegmentCustomerIdProvider */
    public function testChargeCustomerIdentifiersRejectDotSegmentsBeforeNetwork(string $id): void
    {
        $http = new FakeHttpClient([]);
        $resource = new ChargeResource(
            new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none())
        );
        $payload = $this->validPixChargePayload(100);
        $payload['customer']['id'] = $id;

        foreach ([
            static fn (): array => $resource->create($payload, 'idem_dot_customer'),
            static fn (): array => iterator_to_array($resource->all(['customer_id' => $id]), false),
        ] as $operation) {
            try {
                $operation();
                self::fail('Dot-segment customer identifier reached a charge path.');
            } catch (\InvalidArgumentException) {
            }
        }

        self::assertCount(0, $http->requests());
    }

    public static function dotSegmentCustomerIdProvider(): iterable
    {
        yield 'single dot' => ['.'];
        yield 'double dot' => ['..'];
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
        yield 'product max installments' => ['product_max_installments', 1];
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

    #[DataProvider('panLikeCardTokenProvider')]
    public function testCreateCardRejectsPanLikeTokenBeforeNetwork(string $field, string $value): void
    {
        $http = new FakeHttpClient([]);
        $resource = new ChargeResource(
            new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none())
        );
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
            $field => $value,
        ];

        $this->expectException(\InvalidArgumentException::class);
        try {
            $resource->create($payload, 'idem_pan_token');
        } finally {
            self::assertCount(0, $http->requests());
        }
    }

    public static function panLikeCardTokenProvider(): iterable
    {
        yield 'ephemeral token is a PAN' => ['card_token', '4111111111111111'];
        yield 'stored token id is a PAN' => ['card_token_id', '4111111111111111'];
    }

    #[DataProvider('panLikeFreeTextProvider')]
    public function testCreateRejectsPanLikeFreeTextBeforeNetwork(string $field): void
    {
        $http = new FakeHttpClient([]);
        $resource = new ChargeResource(
            new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none())
        );
        $payload = $this->validPixChargePayload(9900);
        $payload[$field] = 'reference 4111-1111-1111-1111';

        $this->expectException(\InvalidArgumentException::class);
        try {
            $resource->create($payload, 'idem_pan_text');
        } finally {
            self::assertCount(0, $http->requests());
        }
    }

    public static function panLikeFreeTextProvider(): iterable
    {
        yield 'description' => ['description'];
        yield 'external reference' => ['external_reference'];
        yield 'affiliate code' => ['affiliate_code'];
        yield 'coupon code' => ['coupon_code'];
    }

    public function testCreateRejectsNonStringFreeTextBeforeNetwork(): void
    {
        foreach ([
            4111111111111111,
            new class implements \JsonSerializable {
                public function jsonSerialize(): string
                {
                    return '4111111111111111';
                }
            },
        ] as $index => $value) {
            $http = new FakeHttpClient([]);
            $resource = new ChargeResource(
                new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none())
            );
            $payload = $this->validPixChargePayload(9900);
            $payload['description'] = $value;

            try {
                $resource->create($payload, 'idem_non_string_text_' . $index);
                self::fail('Non-string free text was accepted.');
            } catch (\InvalidArgumentException) {
            }

            self::assertCount(0, $http->requests());
        }
    }

    public function testCreateKeepsOutcomeUnknownForDivergentCustomerEchoAfterAmbiguousRetry(): void
    {
        foreach ([
            ['customer_id' => 'customer_other'],
            ['customer' => ['id' => 'customer_other']],
            ['customer_id' => null],
        ] as $index => $customerEcho) {
            $response = [
                'data' => [
                    'charge_id' => 'ch_customer_' . $index,
                    'status' => 'pending',
                    'amount_cents' => 9900,
                    ...$customerEcho,
                ],
            ];
            $http = new FakeHttpClient([
                new NetworkFailure('Response lost after request dispatch'),
                new Response(200, [], json_encode($response, JSON_THROW_ON_ERROR)),
            ]);
            $resource = new ChargeResource(
                new ApiClient('sk_test_123', 'https://api.test.local', $http, $this->oneRetry())
            );

            try {
                $resource->create($this->validPixChargePayload(9900), 'idem_customer_' . $index);
                self::fail('Divergent customer echo confirmed an ambiguous mutation.');
            } catch (OutcomeUnknownException $exception) {
                self::assertSame('idem_customer_' . $index, $exception->idempotencyKey());
                self::assertCount(2, $http->requests());
            }
        }
    }

    #[DataProvider('nonOneInstallmentValueProvider')]
    public function testCreateRejectsEachPresentNonOneInstallmentValueBeforeNetwork(
        string $field,
        mixed $value
    ): void {
        $http = new FakeHttpClient([]);
        $resource = new ChargeResource(
            new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none())
        );
        $payload = [
            'amount_cents' => 9900,
            'payment_method' => 'credit_card',
            'customer' => [
                'id' => 'customer_123',
                'name' => 'Ana Silva',
                'email' => 'ana@example.com',
                'tax_id' => '12345678901',
            ],
            'card_token_id' => 'token_123',
            'payer_ip' => '203.0.113.10',
            $field => $value,
        ];

        try {
            $resource->create($payload, 'idem_invalid_installments');
        } catch (\InvalidArgumentException) {
            self::assertCount(0, $http->requests());
            return;
        } catch (\Throwable $exception) {
            self::fail('Invalid installment value reached the network: ' . $exception::class);
        }

        self::fail('Invalid installment value was accepted.');
    }

    public static function nonOneInstallmentValueProvider(): iterable
    {
        foreach (['installments', 'product_max_installments'] as $field) {
            yield $field . ' null' => [$field, null];
            yield $field . ' false' => [$field, false];
            yield $field . ' string' => [$field, '1'];
            yield $field . ' zero' => [$field, 0];
            yield $field . ' two' => [$field, 2];
            yield $field . ' float' => [$field, 1.0];
            yield $field . ' array' => [$field, []];
        }
    }

    /** @dataProvider invalidSplitRulesProvider */
    public function testCreateRejectsMalformedOrOverallocatedSplitRulesBeforeNetwork(mixed $splitRules): void
    {
        $http = new FakeHttpClient([]);
        $resource = new ChargeResource(
            new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none())
        );

        try {
            $resource->create($this->validPixChargePayload(1_000) + ['split_rules' => $splitRules], 'idem_split');
        } catch (\InvalidArgumentException) {
            self::assertCount(0, $http->requests());
            return;
        }

        self::fail('Malformed or overallocated split_rules were accepted.');
    }

    public static function invalidSplitRulesProvider(): iterable
    {
        $fixed = static fn (int $cents): array => [
            'recipient_id' => 'recipient_1',
            'value_type' => 'fixed_amount',
            'value_cents' => $cents,
        ];
        $percentage = static fn (int $bps): array => [
            'recipient_id' => 'recipient_1',
            'value_type' => 'percentage_of_gross',
            'value_bps' => $bps,
        ];

        yield 'null rules' => [null];
        yield 'boolean rules' => [false];
        yield 'not a list' => [['recipient_id' => 'recipient_1']];
        yield 'scalar rule' => [[123]];
        yield 'more than 50' => [array_fill(0, 51, $fixed(1))];
        yield 'unknown field' => [[...$fixed(1), 'internal' => true]];
        yield 'dot recipient' => [[...$fixed(1), 'recipient_id' => '..']];
        yield 'fixed aggregate' => [[$fixed(600), $fixed(600)]];
        yield 'percentage aggregate' => [[$percentage(6_000), $percentage(5_000)]];
        yield 'mixed aggregate' => [[$fixed(600), $percentage(5_000)]];
    }

    public function testCreateAcceptsExactSplitBoundaryWithOverflowSafeMaximum(): void
    {
        $amount = 9_000_000_000_000_000;
        $http = new FakeHttpClient([
            new Response(201, [], json_encode([
                'data' => [
                    'charge_id' => 'ch_split_max',
                    'status' => 'pending',
                    'amount_cents' => $amount,
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);
        $resource = new ChargeResource(
            new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none())
        );

        $charge = $resource->create($this->validPixChargePayload($amount) + [
            'split_rules' => [[
                'recipient_id' => 'recipient_1',
                'value_type' => 'percentage_of_gross',
                'value_bps' => 10_000,
            ]],
        ], 'idem_split_max');

        self::assertSame($amount, $charge['amount_cents']);
        self::assertCount(1, $http->requests());
    }

    public function testCreateAcceptsExactMixedSplitBoundary(): void
    {
        $http = new FakeHttpClient([
            new Response(201, [], '{"data":{"charge_id":"ch_split_mixed","status":"pending","amount_cents":1000}}'),
        ]);
        $resource = new ChargeResource(
            new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none())
        );

        $resource->create($this->validPixChargePayload(1_000) + [
            'split_rules' => [
                [
                    'recipient_id' => 'recipient_fixed',
                    'value_type' => 'fixed_amount',
                    'value_cents' => 500,
                ],
                [
                    'recipient_id' => 'recipient_percentage',
                    'value_type' => 'percentage_of_gross',
                    'value_bps' => 5_000,
                ],
            ],
        ], 'idem_split_mixed');

        self::assertCount(1, $http->requests());
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
        self::assertSame(1, $payload['installments']);
        self::assertSame(1, $payload['product_max_installments']);
    }

    public function testAllRejectsUnsafePaginationBeforeNetwork(): void
    {
        $resource = new ChargeResource(new ApiClient('sk_test_123', 'https://api.test.local', new FakeHttpClient([]), RetryPolicy::none()));

        foreach ([
            ['limit' => 101],
            ['cursor' => 'bad cursor'],
            ['cursor' => 'Zh'],
            ['cursor' => 'Zg=='],
            ['status' => 'DROP TABLE'],
        ] as $params) {
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

    /** @return array<string, mixed> */
    private function validPixChargePayload(int $amountCents): array
    {
        return [
            'amount_cents' => $amountCents,
            'payment_method' => 'pix',
            'customer' => [
                'id' => 'customer_123',
                'name' => 'Ana Silva',
                'email' => 'ana@example.com',
                'tax_id' => '12345678901',
            ],
        ];
    }

    private function oneRetry(): RetryPolicy
    {
        return new RetryPolicy(1, 0, static function (int $delayMs): void {
        });
    }
}
