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
    }

    public function testReadmeDocumentsRemovalBeforeInstallingTheMuPagPackage(): void
    {
        $readme = (string) file_get_contents(__DIR__ . '/../README.md');
        $removePosition = strpos($readme, 'composer remove mupaybr/mupay-sdk');
        $requirePosition = strpos($readme, 'composer require mupag/mupag-sdk:^0.2');

        self::assertIsInt($removePosition);
        self::assertIsInt($requirePosition);
        self::assertLessThan($requirePosition, $removePosition);
        self::assertStringContainsString('use Mupay\\Sdk\\MuPagClient;', $readme);
        self::assertStringContainsString('use MuPag\\Sdk\\MuPagClient;', $readme);
    }
}
