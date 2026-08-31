<?php

declare(strict_types=1);

namespace MuPag\Sdk\Tests;

use GuzzleHttp\Psr7\Response;
use MuPag\Sdk\Http\ApiClient;
use MuPag\Sdk\Http\RetryPolicy;
use MuPag\Sdk\MuPagClient;
use MuPag\Sdk\Resources\ChargeResource;
use MuPag\Sdk\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class MuPagPublicApiTest extends TestCase
{
    public function testExposesTheProductNamedClient(): void
    {
        self::assertInstanceOf(MuPagClient::class, MuPagClient::test('sk_test_123'));
    }

    public function testComposerMetadataUsesThePublicMuPagIdentity(): void
    {
        $manifest = json_decode((string) file_get_contents(__DIR__ . '/../composer.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('mupag/mupag-sdk', $manifest['name']);
        self::assertSame('https://docs.mupag.com.br', $manifest['homepage']);
        self::assertSame('https://github.com/mupaybr/mupag-sdk-php', $manifest['support']['source']);
        self::assertArrayHasKey('MuPag\\Sdk\\', $manifest['autoload']['psr-4']);
        self::assertArrayNotHasKey('Mupay\\Sdk\\', $manifest['autoload']['psr-4']);
        self::assertContains('/.gitignore', $manifest['archive']['exclude']);
        self::assertContains('/composer.lock', $manifest['archive']['exclude']);
        self::assertContains('/phpunit.xml.dist', $manifest['archive']['exclude']);
    }

    public function testReadmeDocumentsTheTruthfulMigrationBeforeInstallingTheMuPagPackage(): void
    {
        $readme = (string) file_get_contents(__DIR__ . '/../README.md');
        $removePosition = strpos($readme, 'composer remove mupaybr/mupay-sdk');
        $requirePosition = strpos($readme, 'composer require mupag/mupag-sdk:^0.2');

        self::assertIsInt($removePosition);
        self::assertIsInt($requirePosition);
        self::assertLessThan($requirePosition, $removePosition);
        self::assertStringContainsString('pacote, o namespace e a classe cliente principal foram renomeados', $readme);
        self::assertStringContainsString('use Mupay\\Sdk\\Mupay;', $readme);
        self::assertStringContainsString('$mupay = Mupay::test(', $readme);
        self::assertStringNotContainsString('use Mupay\\Sdk\\MuPagClient;', $readme);
        self::assertStringContainsString('use MuPag\\Sdk\\MuPagClient;', $readme);
    }

    public function testShippedExamplesUseCanonicalWebhookAndListItemFields(): void
    {
        $webhookExample = (string) file_get_contents(__DIR__ . '/../examples/verify_webhook.php');
        $listExample = (string) file_get_contents(__DIR__ . '/../examples/list_charges.php');
        $readme = (string) file_get_contents(__DIR__ . '/../README.md');

        self::assertStringContainsString("\$_SERVER['HTTP_MUPAG_SIGNATURE']", $webhookExample);
        self::assertStringNotContainsString('HTTP_X_MUPAG_SIGNATURE', $webhookExample);
        self::assertStringContainsString("\$charge['charge_id']", $listExample);
        self::assertStringContainsString("\$charge['charge_id']", $readme);
        self::assertStringContainsString('payload contendo `mode` e `reason`', $readme);
    }

    public function testReadmeErrorExampleSendsACompletePixChargeWithAStableIdempotencyKey(): void
    {
        $readme = (string) file_get_contents(__DIR__ . '/../README.md');
        $matched = preg_match(
            '/## Tratar erros sem adivinhar\s+```php\s+(.*?)```/s',
            $readme,
            $matches
        );

        self::assertSame(1, $matched, 'Bloco PHP de tratamento de erros ausente do README.');

        $http = new FakeHttpClient([
            new Response(200, [], '{"data":{"charge_id":"ch_readme","status":"pending","amount_cents":9900}}'),
        ]);
        $charges = new ChargeResource(
            new ApiClient('sk_test_123', 'https://api.test.local', $http, RetryPolicy::none())
        );
        $mupag = new class ($charges) {
            public function __construct(public readonly ChargeResource $charges)
            {
            }
        };
        $executableExample = preg_replace('/^use [^;]+;\R/m', '', $matches[1]);
        self::assertIsString($executableExample);

        eval($executableExample);

        $request = $http->lastRequest();
        $payload = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([
            'amount_cents' => 9900,
            'payment_method' => 'pix',
            'customer' => [
                'name' => 'Cliente Exemplo',
                'email' => 'cliente@example.test',
                'tax_id' => '00000000000',
            ],
        ], $payload);
        self::assertSame('order_123_error_handling', $request->getHeaderLine('Idempotency-Key'));
    }
}
