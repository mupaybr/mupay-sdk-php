<?php

declare(strict_types=1);

namespace Mupay\Sdk\Http;

use Mupay\Sdk\Exception\ApiException;
use Mupay\Sdk\Exception\RateLimitException;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class ApiClient
{
    private readonly ClientInterface $httpClient;
    private readonly RetryPolicy $retryPolicy;
    private readonly LoggerInterface $logger;
    private readonly string $baseUrl;

    /**
     * Cliente HTTP interno do SDK.
     *
     * Ele centraliza autenticacao, JSON, idempotencia, retry e mapeamento de erros
     * para que os recursos publicos fiquem wrappers pequenos e previsiveis.
     */
    public function __construct(
        private readonly string $apiKey,
        string $baseUrl,
        ?ClientInterface $httpClient = null,
        ?RetryPolicy $retryPolicy = null,
        ?LoggerInterface $logger = null,
        float $timeoutSeconds = 10.0
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->httpClient = $httpClient ?? new Client(['timeout' => $timeoutSeconds]);
        $this->retryPolicy = $retryPolicy ?? RetryPolicy::default();
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Executa GET com query string opcional.
     *
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = [], array $headers = []): array
    {
        return $this->request('GET', $path, null, $query, $headers);
    }

    /**
     * Executa POST em JSON e garante idempotencia quando o caller nao informou chave.
     *
     * @return array<string, mixed>
     */
    public function post(string $path, ?array $payload = null, array $headers = []): array
    {
        return $this->request('POST', $path, $payload, [], $headers);
    }

    /**
     * Executa DELETE com idempotencia para cancelamentos e operacoes mutaveis.
     *
     * @return array<string, mixed>
     */
    public function delete(string $path, array $headers = []): array
    {
        return $this->request('DELETE', $path, null, [], $headers);
    }

    /**
     * @param array<string, mixed>|null $payload
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $payload, array $query, array $headers): array
    {
        $body = $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR);
        $headers = $this->headers($method, $headers, $body !== null);
        $uri = $this->uri($path, $query);
        $attempt = 0;

        while (true) {
            try {
                $response = $this->httpClient->sendRequest(new Request($method, $uri, $headers, $body));
            } catch (ClientExceptionInterface $exception) {
                if (!$this->retryPolicy->shouldRetry($attempt)) {
                    throw new ApiException('Falha ao chamar a API Mupay.', 0, previous: $exception);
                }

                $this->logger->warning('Retry de chamada HTTP apos falha de rede.', ['attempt' => $attempt + 1]);
                $this->retryPolicy->sleepBeforeRetry($attempt);
                $attempt++;
                continue;
            }

            if ($this->retryPolicy->shouldRetry($attempt, $response)) {
                $this->logger->warning('Retry de chamada HTTP apos resposta transiente.', [
                    'attempt' => $attempt + 1,
                    'status' => $response->getStatusCode(),
                ]);
                $this->retryPolicy->sleepBeforeRetry($attempt, $response);
                $attempt++;
                continue;
            }

            return $this->handleResponse($response);
        }
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    private function headers(string $method, array $headers, bool $hasBody): array
    {
        $normalized = array_change_key_case($headers, CASE_LOWER);
        $headers = array_merge([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $this->apiKey,
            'User-Agent' => 'mupay-sdk/0.1.0',
        ], $headers);

        if ($hasBody) {
            $headers['Content-Type'] = $headers['Content-Type'] ?? 'application/json';
        }

        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) && !isset($normalized['idempotency-key'])) {
            $headers['Idempotency-Key'] = bin2hex(random_bytes(16));
        }

        return $headers;
    }

    /**
     * @param array<string, mixed> $query
     */
    private function uri(string $path, array $query): string
    {
        $uri = $this->baseUrl . '/' . ltrim($path, '/');
        if ($query === []) {
            return $uri;
        }

        return $uri . '?' . http_build_query($query);
    }

    /**
     * @return array<string, mixed>
     */
    private function handleResponse(ResponseInterface $response): array
    {
        $decoded = $this->decode($response);
        $status = $response->getStatusCode();

        if ($status < 400) {
            return $decoded;
        }

        $requestId = $this->stringValue($decoded['request_id'] ?? null) ?: $response->getHeaderLine('X-Request-Id') ?: null;
        $apiCode = $this->stringValue($decoded['code'] ?? null);
        $suggestion = $this->stringValue($decoded['suggestion'] ?? null);
        $documentationUrl = $this->stringValue($decoded['documentation_url'] ?? null);
        $message = $this->stringValue($decoded['detail'] ?? $decoded['message'] ?? $decoded['title'] ?? null)
            ?? 'A API Mupay retornou erro.';

        if ($status === 429) {
            $retryAfter = $response->hasHeader('Retry-After') ? (int) $response->getHeaderLine('Retry-After') : null;
            throw new RateLimitException($message, $apiCode, $requestId, $suggestion, $documentationUrl, $retryAfter);
        }

        throw new ApiException($message, $status, $apiCode, $requestId, $suggestion, $documentationUrl);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        $contents = (string) $response->getBody();
        if ($contents === '') {
            return [];
        }

        $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
