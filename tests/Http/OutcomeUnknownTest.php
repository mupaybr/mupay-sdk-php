<?php

declare(strict_types=1);

namespace MuPag\Sdk\Tests\Http;

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\PumpStream;
use MuPag\Sdk\Exception\ApiException;
use MuPag\Sdk\Exception\OutcomeUnknownException;
use MuPag\Sdk\Http\ApiClient;
use MuPag\Sdk\Http\RetryPolicy;
use MuPag\Sdk\Resources\ChargeResource;
use MuPag\Sdk\Tests\Support\FakeHttpClient;
use MuPag\Sdk\Tests\Support\NetworkFailure;
use PHPUnit\Framework\TestCase;

final class OutcomeUnknownTest extends TestCase
{
    public function testMutationTransportFailureExposesUnknownOutcomeAndSentKey(): void
    {
        $http = new FakeHttpClient([new NetworkFailure('Response lost after request dispatch')]);
        $client = new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none());

        try {
            $client->post('/v1/charges', ['amount_cents' => 100]);
            self::fail('Expected outcome unknown error.');
        } catch (OutcomeUnknownException $exception) {
            self::assertTrue($exception->outcomeUnknown());
            self::assertNotSame('', $exception->idempotencyKey());
            self::assertSame(
                $http->lastRequest()->getHeaderLine('Idempotency-Key'),
                $exception->idempotencyKey()
            );
            self::assertInstanceOf(NetworkFailure::class, $exception->getPrevious());
        }
    }

    public function testDuplicateCaseInsensitiveIdempotencyHeadersFailBeforeNetwork(): void
    {
        $http = new FakeHttpClient([]);
        $client = new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none());

        try {
            $client->post(
                '/v1/charges',
                ['amount_cents' => 100],
                ['Idempotency-Key' => 'first-key', 'idempotency-key' => 'second-key']
            );
            self::fail('Expected duplicate header rejection.');
        } catch (\InvalidArgumentException) {
            self::assertCount(0, $http->requests());
        }
    }

    public function testMutationExhaustedServerErrorExposesUnknownOutcome(): void
    {
        $http = new FakeHttpClient([
            new Response(503, [], '{"code":"temporarily_unavailable","request_id":"req_503"}'),
        ]);
        $client = new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none());

        try {
            $client->post(
                '/v1/charges',
                ['amount_cents' => 100],
                ['Idempotency-Key' => 'order_503_attempt_1']
            );
            self::fail('Expected outcome unknown error.');
        } catch (OutcomeUnknownException $exception) {
            self::assertSame('order_503_attempt_1', $exception->idempotencyKey());
            self::assertSame(503, $exception->statusCode());
            self::assertSame('req_503', $exception->requestId());
            self::assertInstanceOf(ApiException::class, $exception->getPrevious());
        }
    }

    public function testMutationDefinitiveClientErrorIsNotUnknown(): void
    {
        $http = new FakeHttpClient([
            new Response(422, [], '{"code":"invalid_amount","request_id":"req_422"}'),
        ]);
        $client = new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none());

        try {
            $client->post('/v1/charges', ['amount_cents' => 100]);
            self::fail('Expected API error.');
        } catch (ApiException $exception) {
            self::assertNotInstanceOf(OutcomeUnknownException::class, $exception);
            self::assertSame(422, $exception->statusCode());
        }
    }

    public function testServerErrorThenConflictKeepsOutcomeUnknown(): void
    {
        $http = new FakeHttpClient([
            new Response(503, [], '{"code":"temporarily_unavailable"}'),
            new Response(409, [], '{"code":"fingerprint_conflict"}'),
        ]);
        $client = new ApiClient('sk_test_123', 'https://api.test.local', $http, $this->oneRetry());

        try {
            $client->post('/v1/charges', ['amount_cents' => 100], ['Idempotency-Key' => 'sticky-key']);
            self::fail('Expected outcome unknown error.');
        } catch (OutcomeUnknownException $exception) {
            self::assertSame('sticky-key', $exception->idempotencyKey());
            self::assertCount(2, $http->requests());
            self::assertSame(
                $http->requests()[0]->getHeaderLine('Idempotency-Key'),
                $http->requests()[1]->getHeaderLine('Idempotency-Key')
            );
        }
    }

    public function testTransportLossThenConflictKeepsOutcomeUnknown(): void
    {
        $http = new FakeHttpClient([
            new NetworkFailure('response lost'),
            new Response(409, [], '{"code":"conflict"}'),
        ]);
        $client = new ApiClient('sk_test_123', 'https://api.test.local', $http, $this->oneRetry());

        $this->expectException(OutcomeUnknownException::class);
        $client->post('/v1/charges', ['amount_cents' => 100]);
    }

    public function testResponseStreamLossThenConflictKeepsOutcomeUnknown(): void
    {
        $body = new PumpStream(static function (int $length): string|false {
            throw new \RuntimeException('response body lost');
        });
        $http = new FakeHttpClient([
            new Response(200, [], $body),
            new Response(409, [], '{"code":"fingerprint_conflict"}'),
        ]);
        $client = new ApiClient('sk_test_123', 'https://api.test.local', $http, $this->oneRetry());

        try {
            $client->post(
                '/v1/charges',
                ['amount_cents' => 100],
                ['Idempotency-Key' => 'stream-sticky-key']
            );
            self::fail('Expected outcome unknown error.');
        } catch (OutcomeUnknownException $exception) {
            self::assertSame('stream-sticky-key', $exception->idempotencyKey());
            self::assertCount(2, $http->requests());
            self::assertSame(
                $http->requests()[0]->getHeaderLine('Idempotency-Key'),
                $http->requests()[1]->getHeaderLine('Idempotency-Key')
            );
        }
    }

    public function testUnreadableConflictIsUnknownBecauseItsCodeCannotBeClassified(): void
    {
        $body = new PumpStream(static function (int $length): string|false {
            throw new \RuntimeException('conflict body lost');
        });
        $http = new FakeHttpClient([new Response(409, [], $body)]);
        $client = new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none());

        try {
            $client->post(
                '/v1/charges',
                ['amount_cents' => 100],
                ['Idempotency-Key' => 'unreadable-conflict-key']
            );
            self::fail('Expected outcome unknown error.');
        } catch (OutcomeUnknownException $exception) {
            self::assertSame('unreadable-conflict-key', $exception->idempotencyKey());
            self::assertCount(1, $http->requests());
        }
    }

    /** @dataProvider bufferedUnclassifiableConflictBodyProvider */
    public function testBufferedUnclassifiableConflictIsUnknown(string $body, string $idempotencyKey): void
    {
        $http = new FakeHttpClient([new Response(409, [], $body)]);
        $client = new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none());

        try {
            $client->post(
                '/v1/charges',
                ['amount_cents' => 100],
                ['Idempotency-Key' => $idempotencyKey]
            );
            self::fail('Expected outcome unknown error.');
        } catch (OutcomeUnknownException $exception) {
            self::assertSame($idempotencyKey, $exception->idempotencyKey());
            self::assertSame(409, $exception->statusCode());
            self::assertCount(1, $http->requests());
        }
    }

    /** @return array<string, array{string, string}> */
    public static function bufferedUnclassifiableConflictBodyProvider(): array
    {
        return [
            'empty-body' => ['', 'empty-conflict-key'],
            'malformed-json' => ['{invalid', 'malformed-conflict-key'],
            'missing-code' => ['{}', 'missing-code-conflict-key'],
            'empty-code' => ['{"code":""}', 'empty-code-conflict-key'],
            'whitespace-code' => ['{"code":"   "}', 'whitespace-code-conflict-key'],
            'fallback-code' => ['{"code":"http_409"}', 'fallback-code-conflict-key'],
            'unknown-code' => ['{"code":"future_conflict"}', 'unknown-code-conflict-key'],
        ];
    }

    public function testUnreadableRateLimitBodyRetriesWithTheSameKey(): void
    {
        $body = new PumpStream(static function (int $length): string|false {
            throw new \RuntimeException('rate-limit body lost');
        });
        $http = new FakeHttpClient([
            new Response(429, ['Retry-After' => '0'], $body),
            new Response(201, [], '{"charge_id":"ch_1"}'),
        ]);
        $client = new ApiClient('sk_test_123', 'https://api.test.local', $http, $this->oneRetry());

        $response = $client->post('/v1/charges', ['amount_cents' => 100]);

        self::assertSame('ch_1', $response['charge_id']);
        self::assertCount(2, $http->requests());
        self::assertSame(
            $http->requests()[0]->getHeaderLine('Idempotency-Key'),
            $http->requests()[1]->getHeaderLine('Idempotency-Key')
        );
    }

    public function testServerErrorThenRateLimitKeepsOutcomeUnknown(): void
    {
        $http = new FakeHttpClient([
            new Response(503, [], '{"code":"temporarily_unavailable"}'),
            new Response(429, ['Retry-After' => '0'], '{"code":"rate_limited"}'),
        ]);
        $client = new ApiClient('sk_test_123', 'https://api.test.local', $http, $this->oneRetry());

        $this->expectException(OutcomeUnknownException::class);
        $client->post('/v1/charges', ['amount_cents' => 100]);
    }

    public function testInProgressThenConflictKeepsOutcomeUnknown(): void
    {
        $http = new FakeHttpClient([
            new Response(409, [], '{"code":"idempotency_in_progress"}'),
            new Response(409, [], '{"code":"fingerprint_conflict"}'),
        ]);
        $client = new ApiClient('sk_test_123', 'https://api.test.local', $http, $this->oneRetry());

        $this->expectException(OutcomeUnknownException::class);
        $client->post('/v1/charges', ['amount_cents' => 100]);
    }

    /** @dataProvider ambiguousStatusProvider */
    public function testAmbiguousMutationStatusesExposeEffectiveKey(int $status): void
    {
        $http = new FakeHttpClient([new Response($status, [], '{"code":"transient"}')]);
        $client = new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none());

        try {
            $client->post('/v1/charges', ['amount_cents' => 100]);
            self::fail('Expected outcome unknown error.');
        } catch (OutcomeUnknownException $exception) {
            self::assertNotSame('', $exception->idempotencyKey());
            self::assertSame(
                $http->lastRequest()->getHeaderLine('Idempotency-Key'),
                $exception->idempotencyKey()
            );
        }
    }

    /** @return array<string, array{int}> */
    public static function ambiguousStatusProvider(): array
    {
        return ['request-timeout' => [408], 'too-early' => [425]];
    }

    public function testIdempotencyInProgressRetriesWithSameKey(): void
    {
        $http = new FakeHttpClient([
            new Response(409, [], '{"code":"idempotency_in_progress"}'),
            new Response(201, [], '{"charge_id":"ch_1","status":"pending","amount_cents":100}'),
        ]);
        $client = new ApiClient('sk_test_123', 'https://api.test.local', $http, $this->oneRetry());

        $response = $client->post('/v1/charges', ['amount_cents' => 100]);

        self::assertSame('ch_1', $response['charge_id']);
        self::assertCount(2, $http->requests());
        self::assertSame(
            $http->requests()[0]->getHeaderLine('Idempotency-Key'),
            $http->requests()[1]->getHeaderLine('Idempotency-Key')
        );
    }

    public function testIdempotencyOutcomeUnknownIsImmediate(): void
    {
        $http = new FakeHttpClient([
            new Response(409, [], '{"code":"idempotency_outcome_unknown"}'),
        ]);
        $client = new ApiClient('sk_test_123', 'https://api.test.local', $http, $this->threeRetries());

        try {
            $client->post('/v1/charges', ['amount_cents' => 100]);
            self::fail('Expected outcome unknown error.');
        } catch (OutcomeUnknownException) {
            self::assertCount(1, $http->requests());
        }
    }

    public function testIdempotencyCodeIsParsedFromNonSeekableBody(): void
    {
        $sent = false;
        $body = new PumpStream(static function (int $length) use (&$sent): string|false {
            if ($sent) {
                return false;
            }
            $sent = true;

            return '{"code":"idempotency_outcome_unknown"}';
        });
        $http = new FakeHttpClient([new Response(409, [], $body)]);
        $client = new ApiClient('sk_test_123', 'https://api.test.local', $http, $this->threeRetries());

        $this->expectException(OutcomeUnknownException::class);
        try {
            $client->post('/v1/charges', ['amount_cents' => 100]);
        } finally {
            self::assertCount(1, $http->requests());
        }
    }

    /** @dataProvider definitiveConflictCodeProvider */
    public function testRecognizedConflictIsDefinitiveWithoutPriorAmbiguity(string $code): void
    {
        $http = new FakeHttpClient([
            new Response(409, [], json_encode(['code' => $code], JSON_THROW_ON_ERROR)),
        ]);
        $client = new ApiClient('sk_test_123', 'https://api.test.local', $http, $this->threeRetries());

        try {
            $client->post('/v1/charges', ['amount_cents' => 100]);
            self::fail('Expected API error.');
        } catch (ApiException $exception) {
            self::assertNotInstanceOf(OutcomeUnknownException::class, $exception);
            self::assertSame($code, $exception->apiCode());
            self::assertCount(1, $http->requests());
        }
    }

    public static function definitiveConflictCodeProvider(): iterable
    {
        yield 'fingerprint conflict' => ['fingerprint_conflict'];
        yield 'idempotency fingerprint conflict' => ['idempotency_fingerprint_conflict'];
        yield 'idempotency key reused' => ['idempotency_key_reused'];
    }

    /** @dataProvider invalidSuccessResponseProvider */
    public function testOnlyValidEconomic2xxConfirmsMutation(Response $response): void
    {
        $http = new FakeHttpClient([$response]);
        $client = new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none());
        $resource = new ChargeResource($client);

        $this->expectException(OutcomeUnknownException::class);
        $resource->create([
            'amount_cents' => 100,
            'payment_method' => 'pix',
            'customer' => [
                'id' => '22222222-2222-4222-8222-222222222222',
                'name' => 'Ana Silva',
                'email' => 'ana@example.com',
                'tax_id' => '12345678901',
            ],
        ]);
    }

    /** @return array<string, array{Response}> */
    public static function invalidSuccessResponseProvider(): array
    {
        return [
            'redirect' => [new Response(302, [], '{}')],
            'empty-body' => [new Response(201)],
            'empty-object' => [new Response(201, [], '{}')],
            'malformed-json' => [new Response(201, [], '{')],
            'invalid-economics' => [
                new Response(201, [], '{"charge_id":"ch_1","status":"pending","amount_cents":-1}'),
            ],
            'missing-economics' => [
                new Response(201, [], '{"charge_id":"ch_1","status":"pending"}'),
            ],
            'single-dot-charge-id' => [
                new Response(201, [], '{"charge_id":".","status":"pending","amount_cents":100}'),
            ],
            'double-dot-charge-id' => [
                new Response(201, [], '{"charge_id":"..","status":"pending","amount_cents":100}'),
            ],
        ];
    }

    private function oneRetry(): RetryPolicy
    {
        return new RetryPolicy(1, 0, static function (int $delayMs): void {
        });
    }

    private function threeRetries(): RetryPolicy
    {
        return new RetryPolicy(3, 0, static function (int $delayMs): void {
        });
    }
}
