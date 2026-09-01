<?php

declare(strict_types=1);

namespace MuPag\Sdk\Tests\Resources;

use GuzzleHttp\Psr7\Response;
use MuPag\Sdk\Exception\OutcomeUnknownException;
use MuPag\Sdk\Http\ApiClient;
use MuPag\Sdk\Http\RetryPolicy;
use MuPag\Sdk\Resources\RefundResource;
use MuPag\Sdk\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RefundResourceTest extends TestCase
{
    public function testCreatePostsRefundWithIdempotencyKey(): void
    {
        $http = new FakeHttpClient([
            new Response(201, [], json_encode([
                'data' => [
                    'refund_id' => 'rf_123',
                    'charge_id' => 'ch_123',
                    'amount_cents' => 500,
                    'status' => 'completed',
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);
        $resource = new RefundResource(new ApiClient('sk_test_123', 'https://api.test', $http));

        $refund = $resource->create('ch_123', ['amount_cents' => 500], 'idem_refund_1');

        self::assertSame('rf_123', $refund['refund_id']);
        self::assertSame('/v1/charges/ch_123/refunds', $http->lastRequest()->getUri()->getPath());
        self::assertSame('idem_refund_1', $http->lastRequest()->getHeaderLine('Idempotency-Key'));
    }

    /** @dataProvider incompleteEconomicRefundResponseProvider */
    public function testCreateRejectsIncompleteEconomicSuccessResponse(string $body): void
    {
        $http = new FakeHttpClient([new Response(201, [], $body)]);
        $resource = new RefundResource(
            new ApiClient('sk_test_123', 'https://api.test', $http, RetryPolicy::none())
        );

        $this->expectException(OutcomeUnknownException::class);
        $resource->create('ch_123', ['amount_cents' => 500], 'idem_refund_economics');
    }

    public static function incompleteEconomicRefundResponseProvider(): iterable
    {
        yield 'missing charge ID' => ['{"refund_id":"rf_123","amount_cents":500,"status":"completed"}'];
        yield 'missing amount' => ['{"refund_id":"rf_123","charge_id":"ch_123","status":"completed"}'];
        yield 'missing status' => ['{"refund_id":"rf_123","charge_id":"ch_123","amount_cents":500}'];
        yield 'unknown status' => ['{"refund_id":"rf_123","charge_id":"ch_123","amount_cents":500,"status":"mystery"}'];
        yield 'single-dot refund ID' => ['{"refund_id":".","charge_id":"ch_123","amount_cents":500,"status":"completed"}'];
        yield 'double-dot refund ID' => ['{"refund_id":"..","charge_id":"ch_123","amount_cents":500,"status":"completed"}'];
    }

    public function testCreateNormalizesLegacyResponseIdToRefundId(): void
    {
        $http = new FakeHttpClient([
            new Response(201, [], '{"data":{"id":"rf_legacy","charge_id":"ch_123","amount":500,"status":"completed","requested_at":"2026-08-31T12:00:00Z"}}'),
        ]);
        $resource = new RefundResource(
            new ApiClient('sk_test_123', 'https://api.test', $http, RetryPolicy::none())
        );

        $refund = $resource->create('ch_123', ['amount_cents' => 500], 'idem_refund_legacy');

        self::assertSame('rf_legacy', $refund['refund_id']);
        self::assertSame('rf_legacy', $refund['id']);
    }

    #[DataProvider('legacyAmountResponseProvider')]
    public function testCreateNormalizesLegacyAmountToAmountCents(string $responseBody): void
    {
        $http = new FakeHttpClient([
            new Response(201, [], $responseBody),
        ]);
        $resource = new RefundResource(
            new ApiClient('sk_test_123', 'https://api.test', $http, RetryPolicy::none())
        );

        $refund = $resource->create('ch_123', ['amount_cents' => 500], 'idem_refund_legacy_amount');

        self::assertSame(500, $refund['amount_cents']);
        self::assertSame(500, $refund['amount']);
    }

    public static function legacyAmountResponseProvider(): iterable
    {
        yield 'canonical amount absent' => [
            '{"data":{"refund_id":"rf_legacy","charge_id":"ch_123","amount":500,"status":"completed"}}',
        ];
        yield 'canonical amount null' => [
            '{"data":{"refund_id":"rf_legacy","charge_id":"ch_123","amount_cents":null,"amount":500,"status":"completed"}}',
        ];
    }

    #[DataProvider('mismatchedPartialRefundAmountProvider')]
    public function testCreateTreatsMismatchedPartialRefundAmountAsOutcomeUnknown(string $responseBody): void
    {
        $http = new FakeHttpClient([
            new Response(201, [], $responseBody),
        ]);
        $resource = new RefundResource(
            new ApiClient('sk_test_123', 'https://api.test', $http, RetryPolicy::none())
        );

        try {
            $resource->create('ch_123', ['amount_cents' => 500], 'idem_refund_amount_mismatch');
            self::fail('Mismatched partial refund amount confirmed the mutation.');
        } catch (OutcomeUnknownException $exception) {
            self::assertSame('idem_refund_amount_mismatch', $exception->idempotencyKey());
            self::assertInstanceOf(\UnexpectedValueException::class, $exception->getPrevious());
            self::assertCount(1, $http->requests());
        }
    }

    public static function mismatchedPartialRefundAmountProvider(): iterable
    {
        yield 'canonical amount' => [
            '{"data":{"refund_id":"rf_123","charge_id":"ch_123","amount_cents":501,"status":"completed"}}',
        ];
        yield 'legacy amount' => [
            '{"data":{"refund_id":"rf_123","charge_id":"ch_123","amount":501,"status":"completed"}}',
        ];
    }

    public function testCreateTreatsMismatchedChargeIdAsOutcomeUnknown(): void
    {
        $http = new FakeHttpClient([
            new Response(201, [], '{"data":{"refund_id":"rf_123","charge_id":"ch_other","amount_cents":500,"status":"completed"}}'),
        ]);
        $resource = new RefundResource(
            new ApiClient('sk_test_123', 'https://api.test', $http, RetryPolicy::none())
        );

        try {
            $resource->create('ch_123', ['amount_cents' => 500], 'idem_refund_charge_mismatch');
            self::fail('Mismatched refund charge confirmed the mutation.');
        } catch (OutcomeUnknownException $exception) {
            self::assertSame('idem_refund_charge_mismatch', $exception->idempotencyKey());
            self::assertInstanceOf(\UnexpectedValueException::class, $exception->getPrevious());
            self::assertCount(1, $http->requests());
        }
    }

    /** @dataProvider documentedRefundStatusProvider */
    public function testCreateAcceptsDocumentedRefundStatuses(string $status): void
    {
        $http = new FakeHttpClient([
            new Response(201, [], json_encode([
                'refund_id' => 'rf_123',
                'charge_id' => 'ch_123',
                'amount_cents' => 1,
                'status' => $status,
                'requested_at' => '2026-08-31T12:00:00Z',
            ], JSON_THROW_ON_ERROR)),
        ]);
        $resource = new RefundResource(
            new ApiClient('sk_test_123', 'https://api.test', $http, RetryPolicy::none())
        );

        $refund = $resource->create('ch_123', ['amount_cents' => 1], 'idem_refund_status');

        self::assertSame($status, $refund['status']);
    }

    public static function documentedRefundStatusProvider(): iterable
    {
        foreach (['requested', 'processing', 'completed', 'failed', 'cancelled', 'unknown'] as $status) {
            yield $status => [$status];
        }
    }

    public function testCreateRejectsBlankChargeIdBeforeNetwork(): void
    {
        $http = new FakeHttpClient([]);
        $resource = new RefundResource(new ApiClient('sk_test_123', 'https://api.test', $http));

        $this->expectException(\InvalidArgumentException::class);
        $resource->create('', [], 'idem_refund_1');
    }

    /** @dataProvider dotSegmentProvider */
    public function testFinancialPathsRejectDotSegmentIdentifiersBeforeNetwork(string $id): void
    {
        $http = new FakeHttpClient([]);
        $resource = new RefundResource(
            new ApiClient('sk_test_123', 'https://api.test', $http, RetryPolicy::none())
        );

        foreach ([
            static fn (): array => $resource->create($id, ['full' => true], 'idem_dot_refund'),
            static fn (): array => $resource->get($id),
            static fn (): array => $resource->listByCharge($id),
        ] as $operation) {
            try {
                $operation();
                self::fail('Dot-segment identifier reached a financial path.');
            } catch (\InvalidArgumentException) {
            }
        }

        self::assertCount(0, $http->requests());
    }

    public static function dotSegmentProvider(): iterable
    {
        yield 'single dot' => ['.'];
        yield 'double dot' => ['..'];
    }

    public function testCreateRequiresExactlyOneExplicitRefundIntent(): void
    {
        $resource = new RefundResource(new ApiClient('sk_test_123', 'https://api.test', new FakeHttpClient([])));

        foreach ([[], ['full' => false], ['amount_cents' => 100, 'full' => true]] as $params) {
            try {
                $resource->create('ch_123', $params, 'idem_refund_1');
                self::fail('Invalid refund intent was accepted.');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('amount_cents', $exception->getMessage());
            }
        }
    }

    public function testCreateSendsExplicitFullIntent(): void
    {
        $http = new FakeHttpClient([
            new Response(202, [], '{"refund_id":"rf_123","charge_id":"ch_123","amount_cents":500,"status":"requested"}'),
        ]);
        $resource = new RefundResource(new ApiClient('sk_test_123', 'https://api.test', $http));

        $resource->create('ch_123', ['full' => true, 'reason' => 'customer_request'], 'idem_refund_full');

        self::assertSame(
            ['full' => true, 'reason' => 'customer_request'],
            json_decode((string) $http->lastRequest()->getBody(), true, 512, JSON_THROW_ON_ERROR)
        );
    }

    public function testCreateFullRefundDoesNotInventAmountCorrelation(): void
    {
        $http = new FakeHttpClient([
            new Response(202, [], '{"refund_id":"rf_full","charge_id":"ch_123","amount_cents":750,"status":"requested"}'),
        ]);
        $resource = new RefundResource(
            new ApiClient('sk_test_123', 'https://api.test', $http, RetryPolicy::none())
        );

        $refund = $resource->create('ch_123', ['full' => true], 'idem_refund_full_amount');

        self::assertSame(750, $refund['amount_cents']);
        self::assertCount(1, $http->requests());
    }

    public function testGetAndListByChargeUseReconciliationEndpoints(): void
    {
        $http = new FakeHttpClient([
            new Response(200, [], '{"refund_id":"rf_123","charge_id":"ch_123","amount_cents":500,"status":"completed"}'),
            new Response(200, [], '{"refunds":[{"refund_id":"rf_123","charge_id":"ch_123","amount_cents":500,"status":"completed"}],"next_cursor":"cursor_2"}'),
        ]);
        $resource = new RefundResource(new ApiClient('sk_test_123', 'https://api.test', $http));

        $refund = $resource->get('rf_123');
        $page = $resource->listByCharge('ch_123', 25, 'cursor_1');

        self::assertSame('completed', $refund['status']);
        self::assertSame('cursor_2', $page['next_cursor']);
        self::assertSame('/v1/refunds/rf_123', $http->requests()[0]->getUri()->getPath());
        self::assertSame('limit=25&cursor=cursor_1', $http->requests()[1]->getUri()->getQuery());
    }

    public function testGetNormalizesLegacyFieldsAndCorrelatesRequestedRefundId(): void
    {
        $http = new FakeHttpClient([
            new Response(200, [], '{"data":{"id":"rf_123","charge_id":"ch_123","amount":750,"status":"completed"}}'),
        ]);
        $resource = new RefundResource(new ApiClient('sk_test_123', 'https://api.test', $http));

        $refund = $resource->get('rf_123');

        self::assertSame('rf_123', $refund['refund_id']);
        self::assertSame(750, $refund['amount_cents']);
        self::assertSame('rf_123', $refund['id']);
        self::assertSame(750, $refund['amount']);
    }

    #[DataProvider('invalidGetRefundResponseProvider')]
    public function testGetRejectsInvalidOrUncorrelatedRefundResponse(array $response): void
    {
        $http = new FakeHttpClient([
            new Response(200, [], json_encode($response, JSON_THROW_ON_ERROR)),
        ]);
        $resource = new RefundResource(new ApiClient('sk_test_123', 'https://api.test', $http));

        $this->expectException(\UnexpectedValueException::class);
        $resource->get('rf_123');
    }

    public static function invalidGetRefundResponseProvider(): iterable
    {
        $valid = [
            'refund_id' => 'rf_123',
            'charge_id' => 'ch_123',
            'amount_cents' => 500,
            'status' => 'completed',
        ];

        yield 'different refund ID' => [[...$valid, 'refund_id' => 'rf_other']];
        yield 'missing refund ID' => [[...$valid, 'refund_id' => null]];
        yield 'missing charge ID' => [[...$valid, 'charge_id' => null]];
        yield 'invalid amount' => [[...$valid, 'amount_cents' => 0]];
        yield 'invalid status' => [[...$valid, 'status' => 'mystery']];
    }

    public function testListByChargeNormalizesEveryRefundWithoutInventingAmountCorrelation(): void
    {
        $http = new FakeHttpClient([
            new Response(200, [], '{"refunds":[{"id":"rf_legacy","charge_id":"ch_123","amount":750,"status":"completed"}]}'),
        ]);
        $resource = new RefundResource(new ApiClient('sk_test_123', 'https://api.test', $http));

        $page = $resource->listByCharge('ch_123');

        self::assertSame('rf_legacy', $page['refunds'][0]['refund_id']);
        self::assertSame(750, $page['refunds'][0]['amount_cents']);
        self::assertSame(750, $page['refunds'][0]['amount']);
    }

    #[DataProvider('terminalRefundCursorProvider')]
    public function testListByChargeTreatsMissingNullAndEmptyCursorAsTerminal(array $pagination): void
    {
        $http = new FakeHttpClient([
            new Response(200, [], json_encode([
                'refunds' => [[
                    'refund_id' => 'rf_terminal',
                    'charge_id' => 'ch_123',
                    'amount_cents' => 500,
                    'status' => 'completed',
                ]],
                ...$pagination,
            ], JSON_THROW_ON_ERROR)),
        ]);
        $resource = new RefundResource(new ApiClient('sk_test_123', 'https://api.test', $http));

        $page = $resource->listByCharge('ch_123');

        self::assertSame('rf_terminal', $page['refunds'][0]['refund_id']);
        self::assertSame($pagination, array_intersect_key($page, ['next_cursor' => true]));
        self::assertCount(1, $http->requests());
    }

    public static function terminalRefundCursorProvider(): iterable
    {
        yield 'missing' => [[]];
        yield 'null' => [['next_cursor' => null]];
        yield 'empty string' => [['next_cursor' => '']];
    }

    #[DataProvider('invalidRefundCursorProvider')]
    public function testListByChargeRejectsInvalidResponseCursor(mixed $cursor): void
    {
        $http = new FakeHttpClient([
            new Response(200, [], json_encode([
                'refunds' => [],
                'next_cursor' => $cursor,
            ], JSON_THROW_ON_ERROR)),
        ]);
        $resource = new RefundResource(new ApiClient('sk_test_123', 'https://api.test', $http));

        $this->expectException(\UnexpectedValueException::class);
        $resource->listByCharge('ch_123');
    }

    public static function invalidRefundCursorProvider(): iterable
    {
        yield 'boolean' => [false];
        yield 'integer' => [1];
        yield 'array' => [[]];
        yield 'object-shaped map' => [['value' => 'cursor_2']];
        yield 'space' => ['bad cursor'];
    }

    #[DataProvider('invalidRefundListShapeProvider')]
    public function testListByChargeRejectsNonListOrNonArrayRefundEntries(array $response): void
    {
        $http = new FakeHttpClient([
            new Response(200, [], json_encode($response, JSON_THROW_ON_ERROR)),
        ]);
        $resource = new RefundResource(new ApiClient('sk_test_123', 'https://api.test', $http));

        $this->expectException(\UnexpectedValueException::class);
        $resource->listByCharge('ch_123');
    }

    public static function invalidRefundListShapeProvider(): iterable
    {
        $valid = [
            'refund_id' => 'rf_123',
            'charge_id' => 'ch_123',
            'amount_cents' => 500,
            'status' => 'completed',
        ];

        yield 'associative map' => [['refunds' => ['first' => $valid]]];
        yield 'null entry' => [['refunds' => [null]]];
        yield 'scalar entry' => [['refunds' => ['invalid']]];
    }

    #[DataProvider('invalidRefundListEntryProvider')]
    public function testListByChargeRejectsInvalidOrUncorrelatedRefundEntry(array $refund): void
    {
        $http = new FakeHttpClient([
            new Response(200, [], json_encode(['refunds' => [$refund]], JSON_THROW_ON_ERROR)),
        ]);
        $resource = new RefundResource(new ApiClient('sk_test_123', 'https://api.test', $http));

        $this->expectException(\UnexpectedValueException::class);
        $resource->listByCharge('ch_123');
    }

    public static function invalidRefundListEntryProvider(): iterable
    {
        $valid = [
            'refund_id' => 'rf_123',
            'charge_id' => 'ch_123',
            'amount_cents' => 500,
            'status' => 'completed',
        ];

        yield 'missing refund ID' => [[...$valid, 'refund_id' => null]];
        yield 'missing charge ID' => [[...$valid, 'charge_id' => null]];
        yield 'different charge ID' => [[...$valid, 'charge_id' => 'ch_other']];
        yield 'invalid amount' => [[...$valid, 'amount_cents' => 0]];
        yield 'invalid status' => [[...$valid, 'status' => 'mystery']];
    }

    public function testReadsRejectUnsafeIdentifiersAndPaginationBeforeNetwork(): void
    {
        $resource = new RefundResource(new ApiClient('sk_test_123', 'https://api.test', new FakeHttpClient([])));

        foreach (
            [
                static fn (): array => $resource->get('../refund'),
                static fn (): array => $resource->listByCharge('ch_123', 101),
                static fn (): array => $resource->listByCharge('ch_123', 25, 'bad cursor'),
            ] as $operation
        ) {
            try {
                $operation();
                self::fail('Unsafe refund read was accepted.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
