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
                    'requested_at' => '2026-08-31T12:00:00Z',
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
            '{"data":{"refund_id":"rf_legacy","charge_id":"ch_123","amount":500,"status":"completed","requested_at":"2026-08-31T12:00:00Z"}}',
        ];
        yield 'canonical amount null' => [
            '{"data":{"refund_id":"rf_legacy","charge_id":"ch_123","amount_cents":null,"amount":500,"status":"completed","requested_at":"2026-08-31T12:00:00Z"}}',
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
            '{"data":{"refund_id":"rf_123","charge_id":"ch_123","amount_cents":501,"status":"completed","requested_at":"2026-08-31T12:00:00Z"}}',
        ];
        yield 'legacy amount' => [
            '{"data":{"refund_id":"rf_123","charge_id":"ch_123","amount":501,"status":"completed","requested_at":"2026-08-31T12:00:00Z"}}',
        ];
    }

    public function testCreateTreatsMismatchedChargeIdAsOutcomeUnknown(): void
    {
        $http = new FakeHttpClient([
            new Response(201, [], '{"data":{"refund_id":"rf_123","charge_id":"ch_other","amount_cents":500,"status":"completed","requested_at":"2026-08-31T12:00:00Z"}}'),
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
            new Response(202, [], '{"refund_id":"rf_123","charge_id":"ch_123","amount_cents":500,"status":"requested","reason":"customer_request","requested_at":"2026-08-31T12:00:00Z"}'),
        ]);
        $resource = new RefundResource(new ApiClient('sk_test_123', 'https://api.test', $http));

        $resource->create('ch_123', ['full' => true, 'reason' => 'customer_request'], 'idem_refund_full');

        self::assertSame(
            ['full' => true, 'reason' => 'customer_request'],
            json_decode((string) $http->lastRequest()->getBody(), true, 512, JSON_THROW_ON_ERROR)
        );
    }

    public function testCreateNormalizesReasonBeforeSendingAndCorrelating(): void
    {
        $http = new FakeHttpClient([
            new Response(202, [], '{"refund_id":"rf_123","charge_id":"ch_123","amount_cents":500,"status":"requested","reason":"customer_request","requested_at":"2026-08-31T12:00:00Z"}'),
            new Response(202, [], '{"refund_id":"rf_124","charge_id":"ch_123","amount_cents":500,"status":"requested","requested_at":"2026-08-31T12:00:00Z"}'),
        ]);
        $resource = new RefundResource(new ApiClient('sk_test_123', 'https://api.test', $http));

        $resource->create(
            'ch_123',
            ['full' => true, 'reason' => "  customer_request\t"],
            'idem_refund_trimmed'
        );
        $resource->create(
            'ch_123',
            ['full' => true, 'reason' => " \t\r\n "],
            'idem_refund_empty'
        );

        self::assertSame(
            ['full' => true, 'reason' => 'customer_request'],
            json_decode((string) $http->requests()[0]->getBody(), true, 512, JSON_THROW_ON_ERROR)
        );
        self::assertSame(
            ['full' => true],
            json_decode((string) $http->requests()[1]->getBody(), true, 512, JSON_THROW_ON_ERROR)
        );
    }

    public function testCreateFullRefundDoesNotInventAmountCorrelation(): void
    {
        $http = new FakeHttpClient([
            new Response(202, [], '{"refund_id":"rf_full","charge_id":"ch_123","amount_cents":750,"status":"requested","requested_at":"2026-08-31T12:00:00Z"}'),
        ]);
        $resource = new RefundResource(
            new ApiClient('sk_test_123', 'https://api.test', $http, RetryPolicy::none())
        );

        $refund = $resource->create('ch_123', ['full' => true], 'idem_refund_full_amount');

        self::assertSame(750, $refund['amount_cents']);
        self::assertCount(1, $http->requests());
    }

    public function testFullRefundWithoutModeEchoDoesNotConfirmAfterAmbiguousRetry(): void
    {
        $http = new FakeHttpClient([
            new Response(503, [], '{"code":"temporarily_unavailable"}'),
            new Response(202, [], '{"refund_id":"rf_full","charge_id":"ch_123","amount_cents":750,"status":"requested","requested_at":"2026-08-31T12:00:00Z"}'),
        ]);
        $resource = new RefundResource(
            new ApiClient(
                'sk_test_123',
                'https://api.test',
                $http,
                new RetryPolicy(1, 0, static function (int $delayMs): void {
                })
            )
        );

        try {
            $resource->create('ch_123', ['full' => true], 'idem_refund_full_ambiguous');
            self::fail('Uncorrelated full refund confirmed an ambiguous mutation.');
        } catch (OutcomeUnknownException $exception) {
            self::assertSame('idem_refund_full_ambiguous', $exception->idempotencyKey());
            self::assertCount(2, $http->requests());
        }
    }

    public function testGetAndListByChargeUseReconciliationEndpoints(): void
    {
        $http = new FakeHttpClient([
            new Response(200, [], '{"refund_id":"rf_123","charge_id":"ch_123","amount_cents":500,"status":"completed","psp_refund_id":null,"reason":null,"requested_at":"2026-08-31T12:00:00Z","completed_at":null,"failure_reason":null}'),
            new Response(200, [], '{"refunds":[{"refund_id":"rf_123","charge_id":"ch_123","amount_cents":500,"status":"completed","psp_refund_id":null,"reason":null,"requested_at":"2026-08-31T12:00:00Z","completed_at":null,"failure_reason":null}],"next_cursor":"Zm8"}'),
        ]);
        $resource = new RefundResource(new ApiClient('sk_test_123', 'https://api.test', $http));

        $refund = $resource->get('rf_123');
        $page = $resource->listByCharge('ch_123', 25, 'Zg');

        self::assertSame('completed', $refund['status']);
        self::assertSame('Zm8', $page['next_cursor']);
        self::assertSame('/v1/refunds/rf_123', $http->requests()[0]->getUri()->getPath());
        self::assertSame('limit=25&cursor=Zg', $http->requests()[1]->getUri()->getQuery());
    }

    public function testGetNormalizesLegacyFieldsAndCorrelatesRequestedRefundId(): void
    {
        $http = new FakeHttpClient([
            new Response(200, [], '{"data":{"id":"rf_123","charge_id":"ch_123","amount":750,"status":"completed","requested_at":"2026-08-31T12:00:00Z"}}'),
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
            'requested_at' => '2026-08-31T12:00:00Z',
        ];

        yield 'different refund ID' => [[...$valid, 'refund_id' => 'rf_other']];
        yield 'missing refund ID' => [[...$valid, 'refund_id' => null]];
        yield 'missing charge ID' => [[...$valid, 'charge_id' => null]];
        yield 'invalid amount' => [[...$valid, 'amount_cents' => 0]];
        yield 'invalid status' => [[...$valid, 'status' => 'mystery']];
        yield 'invalid PSP refund ID type' => [[...$valid, 'psp_refund_id' => 42]];
        yield 'invalid reason type' => [[...$valid, 'reason' => false]];
        yield 'invalid completed timestamp' => [[...$valid, 'completed_at' => []]];
        yield 'invalid failure reason type' => [[...$valid, 'failure_reason' => ['message' => 'failed']]];
    }

    public function testListByChargeNormalizesEveryRefundWithoutInventingAmountCorrelation(): void
    {
        $http = new FakeHttpClient([
            new Response(200, [], '{"refunds":[{"id":"rf_legacy","charge_id":"ch_123","amount":750,"status":"completed","requested_at":"2026-08-31T12:00:00Z"}]}'),
        ]);
        $resource = new RefundResource(new ApiClient('sk_test_123', 'https://api.test', $http));

        $page = $resource->listByCharge('ch_123');

        self::assertSame('rf_legacy', $page['refunds'][0]['refund_id']);
        self::assertSame(750, $page['refunds'][0]['amount_cents']);
        self::assertSame(750, $page['refunds'][0]['amount']);
    }

    #[DataProvider('terminalRefundCursorProvider')]
    public function testListByChargeTreatsMissingAndNullCursorAsTerminal(array $pagination): void
    {
        $http = new FakeHttpClient([
            new Response(200, [], json_encode([
                'refunds' => [[
                    'refund_id' => 'rf_terminal',
                    'charge_id' => 'ch_123',
                    'amount_cents' => 500,
                    'status' => 'completed',
                    'requested_at' => '2026-08-31T12:00:00Z',
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
        yield 'non-canonical trailing bits' => ['Zh'];
        yield 'padded base64url' => ['Zg=='];
    }

    #[DataProvider('refundPageLimitProvider')]
    public function testListByChargeEnforcesRequestedAndDefaultPageLimits(
        ?int $limit,
        int $itemCount,
        bool $wantError
    ): void {
        $refund = [
            'refund_id' => 'rf_123',
            'charge_id' => 'ch_123',
            'amount_cents' => 500,
            'status' => 'completed',
            'requested_at' => '2026-08-31T12:00:00Z',
        ];
        $http = new FakeHttpClient([
            new Response(200, [], json_encode([
                'refunds' => array_fill(0, $itemCount, $refund),
            ], JSON_THROW_ON_ERROR)),
        ]);
        $resource = new RefundResource(new ApiClient('sk_test_123', 'https://api.test', $http));

        if ($wantError) {
            $this->expectException(\UnexpectedValueException::class);
        }

        $page = $resource->listByCharge('ch_123', $limit);
        if (!$wantError) {
            self::assertCount($itemCount, $page['refunds']);
        }
    }

    public static function refundPageLimitProvider(): iterable
    {
        yield 'requested overflow' => [1, 2, true];
        yield 'requested exact' => [2, 2, false];
        yield 'default overflow' => [null, 101, true];
        yield 'default maximum' => [null, 100, false];
    }

    #[DataProvider('refundCreateTimestampProvider')]
    public function testCreateRequiresValidRequestedAt(string $body): void
    {
        $http = new FakeHttpClient([new Response(202, [], $body)]);
        $resource = new RefundResource(
            new ApiClient('sk_test_123', 'https://api.test', $http, RetryPolicy::none())
        );

        $this->expectException(OutcomeUnknownException::class);
        $resource->create('ch_123', ['amount_cents' => 500], 'idem_refund_requested_at');
    }

    public static function refundCreateTimestampProvider(): iterable
    {
        yield 'missing' => [
            '{"refund_id":"rf_123","charge_id":"ch_123","amount_cents":500,"status":"requested"}',
        ];
        yield 'null' => [
            '{"refund_id":"rf_123","charge_id":"ch_123","amount_cents":500,"status":"requested","requested_at":null}',
        ];
        yield 'impossible date' => [
            '{"refund_id":"rf_123","charge_id":"ch_123","amount_cents":500,"status":"requested","requested_at":"2026-02-30T12:00:00Z"}',
        ];
    }

    #[DataProvider('refundReadTimestampProvider')]
    public function testReadsRequireValidRequestedAt(string $operation, string $body): void
    {
        $http = new FakeHttpClient([new Response(200, [], $body)]);
        $resource = new RefundResource(new ApiClient('sk_test_123', 'https://api.test', $http));

        $this->expectException(\UnexpectedValueException::class);
        if ($operation === 'get') {
            $resource->get('rf_123');
            return;
        }
        $resource->listByCharge('ch_123');
    }

    public static function refundReadTimestampProvider(): iterable
    {
        $missing = '{"refund_id":"rf_123","charge_id":"ch_123","amount_cents":500,"status":"completed"}';
        $invalid = '{"refund_id":"rf_123","charge_id":"ch_123","amount_cents":500,"status":"completed","requested_at":"2026-02-30T12:00:00Z"}';
        yield 'get missing' => ['get', $missing];
        yield 'get invalid' => ['get', $invalid];
        yield 'list missing' => ['list', '{"refunds":[' . $missing . ']}'];
        yield 'list invalid' => ['list', '{"refunds":[' . $invalid . ']}'];
    }

    #[DataProvider('refundReasonCorrelationProvider')]
    public function testCreateCorrelatesRequestedReason(
        bool $includeReason,
        ?string $responseReason,
        bool $wantUnknown
    ): void {
        $refund = [
            'refund_id' => 'rf_123',
            'charge_id' => 'ch_123',
            'amount_cents' => 500,
            'status' => 'requested',
            'requested_at' => '2026-08-31T12:00:00Z',
        ];
        if ($includeReason) {
            $refund['reason'] = $responseReason;
        }
        $http = new FakeHttpClient([
            new Response(202, [], json_encode($refund, JSON_THROW_ON_ERROR)),
        ]);
        $resource = new RefundResource(
            new ApiClient('sk_test_123', 'https://api.test', $http, RetryPolicy::none())
        );

        try {
            $response = $resource->create(
                'ch_123',
                ['amount_cents' => 500, 'reason' => 'customer_request'],
                'idem_refund_reason'
            );
            if ($wantUnknown) {
                self::fail('Uncorrelated refund reason confirmed the mutation.');
            }
            self::assertSame('customer_request', $response['reason']);
        } catch (OutcomeUnknownException $exception) {
            if (!$wantUnknown) {
                throw $exception;
            }
            self::assertSame('idem_refund_reason', $exception->idempotencyKey());
        }
    }

    public static function refundReasonCorrelationProvider(): iterable
    {
        yield 'identical' => [true, 'customer_request', false];
        yield 'missing' => [false, null, true];
        yield 'divergent' => [true, 'duplicate', true];
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
            'requested_at' => '2026-08-31T12:00:00Z',
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
            'requested_at' => '2026-08-31T12:00:00Z',
        ];

        yield 'missing refund ID' => [[...$valid, 'refund_id' => null]];
        yield 'missing charge ID' => [[...$valid, 'charge_id' => null]];
        yield 'different charge ID' => [[...$valid, 'charge_id' => 'ch_other']];
        yield 'invalid amount' => [[...$valid, 'amount_cents' => 0]];
        yield 'invalid status' => [[...$valid, 'status' => 'mystery']];
        yield 'invalid PSP refund ID type' => [[...$valid, 'psp_refund_id' => 42]];
        yield 'invalid reason type' => [[...$valid, 'reason' => false]];
        yield 'invalid completed timestamp' => [[...$valid, 'completed_at' => []]];
        yield 'invalid failure reason type' => [[...$valid, 'failure_reason' => ['message' => 'failed']]];
    }

    public function testReadsRejectUnsafeIdentifiersAndPaginationBeforeNetwork(): void
    {
        $resource = new RefundResource(new ApiClient('sk_test_123', 'https://api.test', new FakeHttpClient([])));

        foreach (
            [
                static fn (): array => $resource->get('../refund'),
                static fn (): array => $resource->listByCharge('ch_123', 101),
                static fn (): array => $resource->listByCharge('ch_123', 25, 'bad cursor'),
                static fn (): array => $resource->listByCharge('ch_123', 25, 'Zh'),
                static fn (): array => $resource->listByCharge('ch_123', 25, 'Zg=='),
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
