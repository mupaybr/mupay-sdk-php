<?php

declare(strict_types=1);

namespace MuPag\Sdk\Tests;

use MuPag\Sdk\MuPagClient;
use MuPag\Sdk\Environment;
use MuPag\Sdk\Http\RetryPolicy;
use MuPag\Sdk\Resources\ChargeResource;
use MuPag\Sdk\Resources\RefundResource;
use MuPag\Sdk\Resources\SubscriptionResource;
use MuPag\Sdk\Tests\Support\FakeHttpClient;
use MuPag\Sdk\Webhooks\WebhookService;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class MuPagClientTest extends TestCase
{
    public function testMuPagExposesPublicResources(): void
    {
        $mupag = MuPagClient::test('sk_test_123');

        self::assertInstanceOf(ChargeResource::class, $mupag->charges);
        self::assertInstanceOf(RefundResource::class, $mupag->refunds);
        self::assertInstanceOf(SubscriptionResource::class, $mupag->subscriptions);
        self::assertInstanceOf(WebhookService::class, $mupag->webhooks);
    }

	public function testPrdFactoryUsesProductionBaseUrl(): void
    {
        $http = new FakeHttpClient([
            new Response(
                200,
                [],
                '{"data":{"id":"ch_prd","status":"pending","amount_cents":1000}}'
            ),
        ]);
		$mupag = MuPagClient::prd('sk_prd_123', $http, RetryPolicy::none());

        $mupag->charges->create([
            'amount_cents' => 1000,
            'payment_method' => 'pix',
            'customer' => [
                'id' => 'customer_123',
                'name' => 'Ana Silva',
                'email' => 'ana@example.com',
                'tax_id' => '12345678901',
            ],
        ], 'idem_prd');

        self::assertSame('api.mupag.com.br', $http->lastRequest()->getUri()->getHost());
    }

    public function testEnvironmentIsExplicitAndApiKeyMustMatchIt(): void
    {
        $this->expectException(\ArgumentCountError::class);

        new MuPagClient('sk_test_123');
    }

    public function testEnvironmentMismatchFailsBeforeCreatingResources(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ambiente');

		new MuPagClient('sk_test_123', Environment::Prd);
    }

    public function testInvalidBaseUrlAndTimeoutFailClosed(): void
    {
        try {
            MuPagClient::test('sk_test_123', baseUrl: 'http://attacker.example/path?token=x');
            self::fail('Expected invalid base URL.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('baseUrl', $exception->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('timeout');
        MuPagClient::test('sk_test_123', timeoutSeconds: 0.0);
    }

    public function testBaseUrlCannotExfiltrateApiKeyAcrossOrigins(): void
    {
        foreach ([
            ['sk_test_123', Environment::Test, 'https://attacker.example'],
			['sk_prd_123', Environment::Prd, 'http://127.0.0.1:8080'],
			['sk_prd_123', Environment::Prd, 'https://api.sandbox.mupag.com.br'],
            ['sk_test_123', Environment::Test, 'https://api.mupag.com.br'],
        ] as [$apiKey, $environment, $baseUrl]) {
            try {
                new MuPagClient($apiKey, $environment, baseUrl: $baseUrl);
                self::fail('Expected cross-origin baseUrl to be rejected.');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('baseUrl', $exception->getMessage());
            }
        }

        self::assertInstanceOf(
            MuPagClient::class,
            MuPagClient::test('sk_test_123', baseUrl: 'http://localhost:8080')
        );
    }

    public function testApiKeyRequiresVisibleAscii(): void
    {
        foreach (["sk_test_line\nbreak", 'sk_test_não-ascii'] as $apiKey) {
            try {
                MuPagClient::test($apiKey);
                self::fail('Expected unsafe apiKey to be rejected.');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('apiKey', $exception->getMessage());
            }
        }
    }
}
