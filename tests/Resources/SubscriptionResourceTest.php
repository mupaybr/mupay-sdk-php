<?php

declare(strict_types=1);

namespace MuPag\Sdk\Tests\Resources;

use MuPag\Sdk\Exception\OutcomeUnknownException;
use MuPag\Sdk\Http\ApiClient;
use MuPag\Sdk\Http\RetryPolicy;
use MuPag\Sdk\Resources\SubscriptionResource;
use MuPag\Sdk\Tests\Support\FakeHttpClient;
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

        $subscription = $resource->cancel('sub_123', 'immediate', 'pedido do cliente', 'idem_cancel');

        self::assertSame('canceled', $subscription['status']);
        self::assertSame('/v1/subscriptions/sub_123/cancel', $http->lastRequest()->getUri()->getPath());
        self::assertSame('idem_cancel', $http->lastRequest()->getHeaderLine('Idempotency-Key'));
        self::assertSame(
            '{"mode":"immediate","reason":"pedido do cliente"}',
            (string) $http->lastRequest()->getBody()
        );
    }

    public function testCancelTreatsMismatchedResponseIdAsOutcomeUnknown(): void
    {
        $http = new FakeHttpClient([
            new Response(200, [], '{"data":{"id":"sub_other","status":"canceled"}}'),
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
}
