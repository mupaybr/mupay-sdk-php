<?php

declare(strict_types=1);

namespace Mupay\Sdk\Tests\Http;

use Mupay\Sdk\Exception\ApiException;
use Mupay\Sdk\Exception\RateLimitException;
use Mupay\Sdk\Http\ApiClient;
use Mupay\Sdk\Http\RetryPolicy;
use Mupay\Sdk\Tests\Support\FakeHttpClient;
use Mupay\Sdk\Tests\Support\NetworkFailure;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class ApiClientTest extends TestCase
{
    public function testPostSendsJsonAuthAndIdempotencyHeaders(): void
    {
        $http = new FakeHttpClient([
            new Response(200, ['X-Request-Id' => 'req_123'], '{"data":{"id":"ch_123"}}'),
        ]);
        $client = new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none());

        $result = $client->post('/v1/charges', ['amount' => 9900], ['Idempotency-Key' => 'idem_123']);

        self::assertSame(['id' => 'ch_123'], $result['data']);
        $request = $http->lastRequest();
        self::assertSame('Bearer sk_test_123', $request->getHeaderLine('Authorization'));
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame('idem_123', $request->getHeaderLine('Idempotency-Key'));
        self::assertSame('{"amount":9900}', (string) $request->getBody());
    }

    public function testProblemDetailsMapToApiException(): void
    {
        $http = new FakeHttpClient([
            new Response(402, ['Content-Type' => 'application/problem+json'], json_encode([
                'title' => 'Pagamento recusado',
                'detail' => 'Cartao recusado pela emissora.',
                'status' => 402,
                'code' => 'card_declined',
                'suggestion' => 'Tente outro metodo.',
                'documentation_url' => 'https://docs.mupay.com/errors/card_declined',
                'request_id' => 'req_declined',
            ], JSON_THROW_ON_ERROR)),
        ]);
        $client = new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none());

        try {
            $client->post('/v1/charges', ['amount' => 9900], ['Idempotency-Key' => 'idem_123']);
            self::fail('Expected ApiException.');
        } catch (ApiException $exception) {
            self::assertSame(402, $exception->statusCode());
            self::assertSame('card_declined', $exception->apiCode());
            self::assertSame('req_declined', $exception->requestId());
            self::assertSame('Tente outro metodo.', $exception->suggestion());
            self::assertSame('https://docs.mupay.com/errors/card_declined', $exception->documentationUrl());
        }
    }

    public function testRateLimitExceptionPreservesRetryAfter(): void
    {
        $http = new FakeHttpClient([
            new Response(429, ['Retry-After' => '7'], '{"code":"rate_limited","message":"Too many requests"}'),
        ]);
        $client = new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none());

        $this->expectException(RateLimitException::class);

        try {
            $client->get('/v1/charges');
        } catch (RateLimitException $exception) {
            self::assertSame(7, $exception->retryAfterSeconds());
            throw $exception;
        }
    }

    public function testRetriesNetworkFailuresBeforeReturningSuccess(): void
    {
        $delays = [];
        $http = new FakeHttpClient([
            new NetworkFailure('Temporary timeout'),
            new Response(200, [], '{"data":{"id":"ch_retry"}}'),
        ]);
        $client = new ApiClient(
            'sk_test_123',
            'https://api.test.local',
            $http,
            new RetryPolicy(2, 10, static function (int $delayMs) use (&$delays): void {
                $delays[] = $delayMs;
            })
        );

        $result = $client->get('/v1/charges/ch_retry');

        self::assertSame('ch_retry', $result['data']['id']);
        self::assertCount(2, $http->requests());
        self::assertSame([10], $delays);
    }

    public function testRetriesServerErrorsBeforeReturningSuccess(): void
    {
        $delays = [];
        $http = new FakeHttpClient([
            new Response(500, [], '{"code":"temporary_failure","message":"Try again"}'),
            new Response(200, [], '{"data":{"id":"ch_server_retry"}}'),
        ]);
        $client = new ApiClient(
            'sk_test_123',
            'https://api.test.local',
            $http,
            new RetryPolicy(1, 25, static function (int $delayMs) use (&$delays): void {
                $delays[] = $delayMs;
            })
        );

        $result = $client->get('/v1/charges/ch_server_retry');

        self::assertSame('ch_server_retry', $result['data']['id']);
        self::assertSame([25], $delays);
    }

    public function testDeleteGeneratesIdempotencyKeyAndAcceptsEmptyBody(): void
    {
        $http = new FakeHttpClient([
            new Response(204),
        ]);
        $client = new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none());

        $result = $client->delete('/v1/test-resource/res_123');

        self::assertSame([], $result);
        self::assertNotSame('', $http->lastRequest()->getHeaderLine('Idempotency-Key'));
    }
}
