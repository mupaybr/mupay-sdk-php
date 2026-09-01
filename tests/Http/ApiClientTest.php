<?php

declare(strict_types=1);

namespace MuPag\Sdk\Tests\Http;

use MuPag\Sdk\Exception\ApiException;
use MuPag\Sdk\Exception\RateLimitException;
use MuPag\Sdk\Http\ApiClient;
use MuPag\Sdk\Http\RetryPolicy;
use MuPag\Sdk\Tests\Support\FakeHttpClient;
use MuPag\Sdk\Tests\Support\NetworkFailure;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
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
        self::assertSame('mupag-sdk/0.2.0', $request->getHeaderLine('User-Agent'));
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
            'documentation_url' => 'https://docs.mupag.com.br/errors/card_declined',
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
        self::assertSame('https://docs.mupag.com.br/errors/card_declined', $exception->documentationUrl());
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
        self::assertCount(1, $delays);
        self::assertGreaterThanOrEqual(7, $delays[0]);
        self::assertLessThanOrEqual(13, $delays[0]);
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
        self::assertCount(1, $delays);
        self::assertGreaterThanOrEqual(18, $delays[0]);
        self::assertLessThanOrEqual(32, $delays[0]);
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

    public function testInvalidIdempotencyKeyIsRejectedBeforeNetwork(): void
    {
        $http = new FakeHttpClient([]);
        $client = new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none());

        foreach (['', ' ', str_repeat('a', 129), "line\nbreak", 'non-ascii-ç'] as $key) {
            try {
                $client->post('/v1/charges', [], ['Idempotency-Key' => $key]);
                self::fail('Expected invalid idempotency key.');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('Idempotency-Key', $exception->getMessage());
            }
        }

        self::assertCount(0, $http->requests());
    }

    #[DataProvider('mutationMethodProvider')]
    public function testCallerSuppliedPanIdempotencyKeyIsRejectedBeforeEveryMutation(
        string $method
    ): void {
        $http = new FakeHttpClient([
            $method === 'POST' ? new Response(200, [], '{}') : new Response(204),
        ]);
        $client = new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none());

        try {
            if ($method === 'POST') {
                $client->post(
                    '/v1/test-resource',
                    [],
                    ['Idempotency-Key' => 'order-4111-1111-1111-1111']
                );
            } else {
                $client->delete(
                    '/v1/test-resource/res_123',
                    ['Idempotency-Key' => 'order-4111-1111-1111-1111']
                );
            }
            self::fail('PAN-like caller idempotency key reached the HTTP client.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('Idempotency-Key', $exception->getMessage());
        }

        self::assertCount(0, $http->requests());
    }

    #[DataProvider('mutationMethodProvider')]
    public function testCallerSuppliedNumericNonLuhnIdempotencyKeyIsAccepted(
        string $method
    ): void {
        $http = new FakeHttpClient([
            $method === 'POST' ? new Response(200, [], '{}') : new Response(204),
        ]);
        $client = new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none());

        if ($method === 'POST') {
            $client->post(
                '/v1/test-resource',
                [],
                ['Idempotency-Key' => '8777777777771013']
            );
        } else {
            $client->delete(
                '/v1/test-resource/res_123',
                ['Idempotency-Key' => '8777777777771013']
            );
        }

        self::assertSame('8777777777771013', $http->lastRequest()->getHeaderLine('Idempotency-Key'));
        self::assertCount(1, $http->requests());
    }

    public static function mutationMethodProvider(): iterable
    {
        yield 'POST' => ['POST'];
        yield 'DELETE' => ['DELETE'];
    }

    public function testRequestAndResponseBodiesAreBounded(): void
    {
        $http = new FakeHttpClient([
            new Response(200, ['Content-Length' => '1000'], str_repeat('x', 1000)),
        ]);
        $client = new ApiClient(
            'sk_test_123',
            'https://api.test.local',
            $http,
            RetryPolicy::none(),
            maxResponseBytes: 64
        );

        try {
            $client->post('/v1/charges', ['metadata' => ['large' => str_repeat('x', 1024 * 1024)]]);
            self::fail('Expected oversized request.');
        } catch (ApiException $exception) {
            self::assertStringContainsString('requisicao', $exception->getMessage());
        }
        self::assertCount(0, $http->requests());

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('resposta');
        $client->get('/v1/charges');
    }

    public function testGeneratedIdempotencyKeyIsStableAcrossNetworkRetry(): void
    {
        $http = new FakeHttpClient([
            new NetworkFailure('Temporary timeout'),
            new Response(200, [], '{"data":{"id":"ch_retry"}}'),
        ]);
        $client = new ApiClient(
            'sk_test_123',
            'https://api.test.local',
            $http,
            new RetryPolicy(1, 0, static function (int $delayMs): void {
            })
        );

        $client->post('/v1/charges', ['amount_cents' => 100]);

        self::assertCount(2, $http->requests());
        self::assertNotSame('', $http->requests()[0]->getHeaderLine('Idempotency-Key'));
        self::assertSame(
            $http->requests()[0]->getHeaderLine('Idempotency-Key'),
            $http->requests()[1]->getHeaderLine('Idempotency-Key')
        );
    }

    public function testMalformedRetryAfterNeverCrashesParser(): void
    {
        $http = new FakeHttpClient([
            new Response(429, ['Retry-After' => 'not-a-date'], '{"code":"rate_limited"}'),
        ]);
        $client = new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none());

        try {
            $client->get('/v1/charges');
            self::fail('Expected rate limit error.');
        } catch (RateLimitException $exception) {
            self::assertNull($exception->retryAfterSeconds());
        }
    }

    #[DataProvider('nonHttpDateRetryAfterProvider')]
    public function testRetryPolicyRejectsRelativeRetryAfterValues(string $value): void
    {
        $response = new Response(429, ['Retry-After' => $value]);

        self::assertNull(RetryPolicy::retryAfterSeconds($response));
    }

    public static function nonHttpDateRetryAfterProvider(): iterable
    {
        yield 'tomorrow' => ['tomorrow'];
        yield 'next weekday' => ['next Thursday'];
        yield 'relative interval' => ['+1 hour'];
        yield 'invalid calendar date' => ['Sun, 31 Feb 2026 00:00:00 GMT'];
    }

    public function testRetryPolicyAcceptsHttpDateAndCapsDelay(): void
    {
        $futureHttpDate = gmdate('D, d M Y H:i:s \G\M\T', time() + 3600);
        $response = new Response(429, ['Retry-After' => $futureHttpDate]);

        self::assertSame(30, RetryPolicy::retryAfterSeconds($response));
    }

    #[DataProvider('validHttpDateProvider')]
    public function testRetryPolicyAcceptsAllHttpDateFormats(string $value): void
    {
        $response = new Response(429, ['Retry-After' => $value]);

        self::assertSame(0, RetryPolicy::retryAfterSeconds($response));
    }

    public static function validHttpDateProvider(): iterable
    {
        yield 'IMF-fixdate' => ['Sun, 06 Nov 1994 08:49:37 GMT'];
        yield 'obsolete RFC 850' => ['Sunday, 06-Nov-94 08:49:37 GMT'];
        yield 'obsolete ANSI C asctime' => ['Sun Nov  6 08:49:37 1994'];
    }

    public function testRetryPolicyRejectsUnboundedConfiguration(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RetryPolicy(6, 60_000);
    }

    public function testRetryPolicyAppliesInjectableJitterToBackoff(): void
    {
        $delays = [];
        $policy = new RetryPolicy(
            1,
            100,
            static function (int $delayMs) use (&$delays): void {
                $delays[] = $delayMs;
            },
            static fn (int $delayMs): int => $delayMs + 23
        );

        $policy->sleepBeforeRetry(0);

        self::assertSame([123], $delays);
    }
}
