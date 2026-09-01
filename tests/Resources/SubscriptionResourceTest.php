<?php

declare(strict_types=1);

namespace MuPag\Sdk\Tests\Resources;

use MuPag\Sdk\Exception\OutcomeUnknownException;
use MuPag\Sdk\Http\ApiClient;
use MuPag\Sdk\Http\RetryPolicy;
use MuPag\Sdk\Resources\SubscriptionResource;
use MuPag\Sdk\Tests\Support\FakeHttpClient;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SubscriptionResourceTest extends TestCase
{
    public function testCancelUsesSubscriptionCancelEndpoint(): void
    {
        $http = new FakeHttpClient([
            new Response(200, [], '{"data":{"id":"sub_123","status":"canceled","cancel_at_period_end":false}}'),
        ]);
        $resource = new SubscriptionResource(new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none()));

        $subscription = $resource->cancel('sub_123', 'immediate', 'pedido do cliente', 'idem_cancel');

        self::assertSame('canceled', $subscription['status']);
        self::assertSame('/v1/subscriptions/sub_123/cancel', $http->lastRequest()->getUri()->getPath());
        self::assertSame('idem_cancel', $http->lastRequest()->getHeaderLine('Idempotency-Key'));
        self::assertSame(
            '{"mode":"immediate","reason":"pedido do cliente"}',
            (string) $http->lastRequest()->getBody()
        );
    }

    public function testCancelRejectsPanReasonBeforeNetwork(): void
    {
        $http = new FakeHttpClient([
            new Response(200, [], '{"data":{"id":"sub_123","status":"canceled","cancel_at_period_end":false}}'),
        ]);
        $resource = new SubscriptionResource(
            new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none())
        );

        try {
            $resource->cancel('sub_123', 'immediate', 'customer 4111-1111-1111-1111');
            self::fail('PAN-like cancellation reason reached the HTTP client.');
        } catch (\InvalidArgumentException) {
            self::assertCount(0, $http->requests());
        }
    }

    #[DataProvider('controlSeparatedPanProvider')]
    public function testCancelRejectsControlSeparatedPanReasonBeforeNetwork(string $pan): void
    {
        $http = new FakeHttpClient([]);
        $resource = new SubscriptionResource(
            new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none())
        );

        $this->expectException(\InvalidArgumentException::class);
        try {
            $resource->cancel('sub_123', 'immediate', $pan);
        } finally {
            self::assertCount(0, $http->requests());
        }
    }

    public static function controlSeparatedPanProvider(): iterable
    {
        yield 'NUL' => ["4111\0 1111\0 1111\0 1111"];
        yield 'Unicode Cc' => ["4111\u{0080}1111\u{0080}1111\u{0080}1111"];
    }

    public function testCancelAcceptsNumericNonLuhnReason(): void
    {
        $http = new FakeHttpClient([
            new Response(200, [], '{"data":{"id":"sub_123","status":"canceled","cancel_at_period_end":false}}'),
        ]);
        $resource = new SubscriptionResource(
            new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none())
        );

        $resource->cancel('sub_123', 'immediate', '8777777777771013');

        self::assertSame(
            '{"mode":"immediate","reason":"8777777777771013"}',
            (string) $http->lastRequest()->getBody()
        );
    }

    public function testCancelRejectsPanSubscriptionIdBeforeNetwork(): void
    {
        $http = new FakeHttpClient([]);
        $resource = new SubscriptionResource(
            new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none())
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Subscription ID');
        try {
            $resource->cancel('4111-1111-1111-1111', 'immediate');
        } finally {
            self::assertCount(0, $http->requests());
        }
    }

    public function testCancelAcceptsNumericNonLuhnSubscriptionId(): void
    {
        $http = new FakeHttpClient([
            new Response(
                200,
                [],
                '{"data":{"id":"8777777777771013","status":"canceled","cancel_at_period_end":false}}'
            ),
        ]);
        $resource = new SubscriptionResource(
            new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none())
        );

        $resource->cancel('8777777777771013', 'immediate');

        self::assertSame(
            '/v1/subscriptions/8777777777771013/cancel',
            $http->lastRequest()->getUri()->getPath()
        );
        self::assertCount(1, $http->requests());
    }

    public function testCancelTreatsMismatchedResponseIdAsOutcomeUnknown(): void
    {
        $http = new FakeHttpClient([
            new Response(200, [], '{"data":{"id":"sub_other","status":"canceled","cancel_at_period_end":false}}'),
        ]);
        $resource = new SubscriptionResource(new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none()));

        try {
            $resource->cancel('sub_requested', 'immediate', idempotencyKey: 'idem_cancel_mismatch');
            self::fail('Mismatched subscription ID confirmed a cancellation.');
        } catch (OutcomeUnknownException $exception) {
            self::assertSame('idem_cancel_mismatch', $exception->idempotencyKey());
            self::assertInstanceOf(\UnexpectedValueException::class, $exception->getPrevious());
            self::assertCount(1, $http->requests());
        }
    }

    #[DataProvider('modeCompatibleCancelResponseProvider')]
    public function testCancelAcceptsEveryModeCompatibleResponse(
        string $mode,
        string $status,
        bool $cancelAtPeriodEnd
    ): void {
        $http = new FakeHttpClient([
            new Response(200, [], json_encode([
                'data' => [
                    'id' => 'sub_123',
                    'customer_id' => 'cus_123',
                    'plan_id' => 'plan_123',
                    'payment_method' => 'pix',
                    'status' => $status,
                    'cancel_at_period_end' => $cancelAtPeriodEnd,
                    'metadata' => [],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);
        $resource = new SubscriptionResource(
            new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none())
        );

        $subscription = $resource->cancel(
            'sub_123',
            $mode,
            idempotencyKey: 'idem_cancel_compatible'
        );

        self::assertSame($status, $subscription['status']);
        self::assertSame($cancelAtPeriodEnd, $subscription['cancel_at_period_end']);
        self::assertCount(1, $http->requests());
    }

    public static function modeCompatibleCancelResponseProvider(): iterable
    {
        yield 'immediate final cancellation' => ['immediate', 'canceled', false];

        foreach (['trialing', 'active', 'past_due', 'unpaid', 'paused', 'incomplete'] as $status) {
            yield 'scheduled from ' . $status => ['end_of_period', $status, true];
        }
    }

    #[DataProvider('modeIncompatibleCancelResponseProvider')]
    public function testCancelTreatsModeIncompatibleResponseAsOutcomeUnknown(
        string $mode,
        array $response
    ): void {
        $http = new FakeHttpClient([
            new Response(200, [], json_encode(['data' => $response], JSON_THROW_ON_ERROR)),
        ]);
        $resource = new SubscriptionResource(
            new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none())
        );

        try {
            $resource->cancel('sub_123', $mode, idempotencyKey: 'idem_cancel_contract');
            self::fail('Mode-incompatible cancellation response confirmed the mutation.');
        } catch (OutcomeUnknownException $exception) {
            self::assertSame('idem_cancel_contract', $exception->idempotencyKey());
            self::assertInstanceOf(\UnexpectedValueException::class, $exception->getPrevious());
            self::assertCount(1, $http->requests());
        }
    }

    public static function modeIncompatibleCancelResponseProvider(): iterable
    {
        $base = [
            'id' => 'sub_123',
            'customer_id' => 'cus_123',
            'plan_id' => 'plan_123',
            'payment_method' => 'pix',
            'status' => 'canceled',
            'cancel_at_period_end' => false,
            'metadata' => [],
        ];

        yield 'immediate non-final status' => ['immediate', [...$base, 'status' => 'active']];
        yield 'immediate unknown status' => ['immediate', [...$base, 'status' => 'mystery']];
        yield 'immediate scheduled marker' => [
            'immediate',
            [...$base, 'cancel_at_period_end' => true],
        ];
        yield 'immediate null marker' => [
            'immediate',
            [...$base, 'cancel_at_period_end' => null],
        ];

        $missingImmediateMarker = $base;
        unset($missingImmediateMarker['cancel_at_period_end']);
        yield 'immediate missing marker' => ['immediate', $missingImmediateMarker];

        $scheduled = [...$base, 'status' => 'active', 'cancel_at_period_end' => true];
        yield 'scheduled canceled status' => [
            'end_of_period',
            [...$scheduled, 'status' => 'canceled'],
        ];
        yield 'scheduled ended status' => [
            'end_of_period',
            [...$scheduled, 'status' => 'ended'],
        ];
        yield 'scheduled expired status' => [
            'end_of_period',
            [...$scheduled, 'status' => 'incomplete_expired'],
        ];
        yield 'scheduled unknown status' => [
            'end_of_period',
            [...$scheduled, 'status' => 'mystery'],
        ];
        yield 'scheduled false marker' => [
            'end_of_period',
            [...$scheduled, 'cancel_at_period_end' => false],
        ];
        yield 'scheduled null marker' => [
            'end_of_period',
            [...$scheduled, 'cancel_at_period_end' => null],
        ];
        yield 'scheduled string marker' => [
            'end_of_period',
            [...$scheduled, 'cancel_at_period_end' => 'true'],
        ];

        $missingScheduledMarker = $scheduled;
        unset($missingScheduledMarker['cancel_at_period_end']);
        yield 'scheduled missing marker' => ['end_of_period', $missingScheduledMarker];
    }

    public function testCancelRejectsUnsafeIdentifiersAndModesBeforeNetwork(): void
    {
        $http = new FakeHttpClient([]);
        $resource = new SubscriptionResource(new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none()));

        foreach ([['', 'immediate'], ['sub_123', 'later']] as [$id, $mode]) {
            try {
                $resource->cancel($id, $mode, idempotencyKey: 'idem_cancel');
                self::fail('Expected invalid cancel input.');
            } catch (\InvalidArgumentException) {
            }
        }
        self::assertCount(0, $http->requests());
    }

    #[DataProvider('nonCanonicalSubscriptionIdProvider')]
    public function testCancelRejectsNonCanonicalSubscriptionIdBeforeNetwork(string $id): void
    {
        $http = new FakeHttpClient([]);
        $resource = new SubscriptionResource(
            new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none())
        );

        try {
            $resource->cancel($id, 'immediate', idempotencyKey: 'idem_cancel_invalid_id');
        } catch (\InvalidArgumentException) {
            self::assertCount(0, $http->requests());
            return;
        } catch (\Throwable $exception) {
            self::fail('Non-canonical subscription ID reached the network: ' . $exception::class);
        }

        self::fail('Non-canonical subscription ID was accepted.');
    }

    public static function nonCanonicalSubscriptionIdProvider(): iterable
    {
        yield 'single dot' => ['.'];
        yield 'double dot' => ['..'];
        yield 'colon' => ['sub:123'];
        yield 'slash' => ['sub/123'];
        yield 'at sign' => ['sub@123'];
        yield 'plus' => ['sub+123'];
        yield 'percent' => ['sub%123'];
        yield 'question mark' => ['sub?123'];
    }
}
