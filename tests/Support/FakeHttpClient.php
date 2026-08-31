<?php

declare(strict_types=1);

namespace MuPag\Sdk\Tests\Support;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class FakeHttpClient implements ClientInterface
{
    /** @var list<ResponseInterface|ClientExceptionInterface> */
    private array $queue;

    /** @var list<RequestInterface> */
    private array $requests = [];

    /**
     * @param list<ResponseInterface|ClientExceptionInterface> $queue
     */
    public function __construct(array $queue)
    {
        $this->queue = $queue;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $next = array_shift($this->queue);

        if ($next instanceof ClientExceptionInterface) {
            throw $next;
        }

        if (!$next instanceof ResponseInterface) {
            throw new \RuntimeException('FakeHttpClient queue is empty.');
        }

        return $next;
    }

    public function lastRequest(): RequestInterface
    {
        $request = end($this->requests);
        if (!$request instanceof RequestInterface) {
            throw new \RuntimeException('No request was sent.');
        }

        return $request;
    }

    /**
     * @return list<RequestInterface>
     */
    public function requests(): array
    {
        return $this->requests;
    }
}
