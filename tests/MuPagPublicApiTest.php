<?php

declare(strict_types=1);

namespace MuPag\Sdk\Tests;

use MuPag\Sdk\MuPagClient;
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
}
