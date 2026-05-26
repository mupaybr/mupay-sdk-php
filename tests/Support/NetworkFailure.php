<?php

declare(strict_types=1);

namespace Mupay\Sdk\Tests\Support;

use GuzzleHttp\Psr7\Request;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;

final class NetworkFailure extends \RuntimeException implements NetworkExceptionInterface
{
    public function getRequest(): RequestInterface
    {
        return new Request('GET', 'https://api.test.local');
    }
}
