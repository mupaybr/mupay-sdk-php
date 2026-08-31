<?php

declare(strict_types=1);

namespace MuPag\Sdk\Http;

use MuPag\Sdk\Exception\ApiException;
use MuPag\Sdk\Exception\OutcomeUnknownException;
use MuPag\Sdk\Exception\RateLimitException;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class ApiClient
{
    private const MAX_REQUEST_BYTES = 1_048_576;
    private const MAX_CONFIGURED_RESPONSE_BYTES = 16_777_216;

    private readonly ClientInterface $httpClient;
    private readonly RetryPolicy $retryPolicy;
    private readonly LoggerInterface $logger;
    private readonly string $baseUrl;
    private readonly int $maxResponseBytes;

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
        float $timeoutSeconds = 10.0,
        int $maxResponseBytes = 2_097_152
    ) {
        if ($apiKey === '' || strlen($apiKey) > 512 || trim($apiKey) !== $apiKey
            || (!str_starts_with($apiKey, 'sk_test_') && !str_starts_with($apiKey, 'sk_prd_'))) {
            throw new \InvalidArgumentException('apiKey invalida.');
        }
        if (!is_finite($timeoutSeconds) || $timeoutSeconds <= 0 || $timeoutSeconds > 120) {
            throw new \InvalidArgumentException('timeoutSeconds deve estar entre 0 e 120 segundos.');
        }
        if ($maxResponseBytes < 1 || $maxResponseBytes > self::MAX_CONFIGURED_RESPONSE_BYTES) {
            throw new \InvalidArgumentException('maxResponseBytes deve estar entre 1 byte e 16 MiB.');
        }
        $this->validateBaseUrl($baseUrl);
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->httpClient = $httpClient ?? new Client(['timeout' => $timeoutSeconds]);
        $this->retryPolicy = $retryPolicy ?? RetryPolicy::default();
        $this->logger = $logger ?? new NullLogger();
        $this->maxResponseBytes = $maxResponseBytes;
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
     * @param null|callable(array<string, mixed>): array<string, mixed> $responseValidator
     * @return array<string, mixed>
     */
    public function post(
        string $path,
        ?array $payload = null,
        array $headers = [],
        ?callable $responseValidator = null
    ): array
    {
        return $this->request('POST', $path, $payload, [], $headers, $responseValidator);
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
     * @param null|callable(array<string, mixed>): array<string, mixed> $responseValidator
     * @return array<string, mixed>
     */
    private function request(
        string $method,
        string $path,
        ?array $payload,
        array $query,
        array $headers,
        ?callable $responseValidator = null
    ): array {
        try {
            $body = $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ApiException('Corpo da requisicao nao e JSON valido.', 0, 'invalid_request_json', previous: $exception);
        }
        if ($body !== null && strlen($body) > self::MAX_REQUEST_BYTES) {
            throw new ApiException('Corpo da requisicao excede o limite seguro de 1 MiB.', 0, 'request_too_large');
        }
        $headers = $this->headers($method, $headers, $body !== null);
        $idempotencyKey = $this->mutationIdempotencyKey($method, $headers);
        $uri = $this->uri($path, $query);
        $attempt = 0;
        $ambiguousCause = null;

        while (true) {
            try {
                $response = $this->httpClient->sendRequest(new Request($method, $uri, $headers, $body));
            } catch (ClientExceptionInterface $exception) {
                if ($idempotencyKey !== null && $ambiguousCause === null) {
                    $ambiguousCause = $exception;
                }
                if (!$this->retryPolicy->shouldRetry($attempt)) {
                    if ($idempotencyKey !== null) {
                        throw new OutcomeUnknownException($idempotencyKey, $ambiguousCause ?? $exception);
                    }
                    throw new ApiException('Falha ao chamar a API MuPag.', 0, previous: $exception);
                }

                $this->logger->warning('Retry de chamada HTTP apos falha de rede.', ['attempt' => $attempt + 1]);
                $this->retryPolicy->sleepBeforeRetry($attempt);
                $attempt++;
                continue;
            }

            try {
                $response = $this->bufferedResponse($response);
            } catch (\RuntimeException $exception) {
                $status = $response->getStatusCode();
                $readException = $exception instanceof ApiException
                    ? $exception
                    : new ApiException(
                        'Falha ao ler o corpo da resposta da API MuPag.',
                        0,
                        'response_read_failed',
                        previous: $exception
                    );
                $statusException = $this->unreadableResponseException($response, $readException);
                $ambiguousRead = ($status >= 200 && $status < 400)
                    || in_array($status, [408, 425, 409], true)
                    || $status >= 500;
                if ($idempotencyKey !== null && $ambiguousRead && $ambiguousCause === null) {
                    $ambiguousCause = $statusException;
                }
                $transientStatus = in_array($status, [408, 425, 429], true) || $status >= 500;
                $streamFailure = !$exception instanceof ApiException;
                $retryableStreamFailure = $streamFailure
                    && (($status >= 200 && $status < 400) || $status === 409);
                if (($transientStatus || $retryableStreamFailure)
                    && $this->retryPolicy->shouldRetry($attempt)) {
                    $this->logger->warning('Retry de chamada HTTP apos falha ao ler resposta.', [
                        'attempt' => $attempt + 1,
                        'status' => $status,
                    ]);
                    $this->retryPolicy->sleepBeforeRetry($attempt, $response);
                    $attempt++;
                    continue;
                }
                if ($idempotencyKey !== null && $ambiguousRead) {
                    throw new OutcomeUnknownException(
                        $idempotencyKey,
                        $ambiguousCause ?? $statusException
                    );
                }
                throw $statusException;
            }

            $responseCode = $this->responseCode($response);
            if ($idempotencyKey !== null && $responseCode === 'idempotency_outcome_unknown') {
                $exception = $this->responseExceptionSnapshot($response);
                throw new OutcomeUnknownException($idempotencyKey, $exception);
            }
            if ($idempotencyKey !== null
                && $ambiguousCause === null
                && $this->isAmbiguousResponse($response, $responseCode)) {
                $ambiguousCause = $this->responseExceptionSnapshot($response);
            }
            $idempotencyInProgress = $responseCode === 'idempotency_in_progress';
            if ($this->retryPolicy->shouldRetry($attempt, $response, $idempotencyInProgress)) {
                $this->logger->warning('Retry de chamada HTTP apos resposta transiente.', [
                    'attempt' => $attempt + 1,
                    'status' => $response->getStatusCode(),
                ]);
                $response->getBody()->close();
                $this->retryPolicy->sleepBeforeRetry($attempt, $response);
                $attempt++;
                continue;
            }

            try {
                $result = $this->handleResponse($response);
                if ($responseValidator !== null) {
                    $result = $responseValidator($result);
                }

                return $result;
            } catch (ApiException $exception) {
                $ambiguousSuccessResponse = $response->getStatusCode() >= 200
                    && $response->getStatusCode() < 300
                    && $exception->statusCode() === 0;
                if ($idempotencyKey !== null
                    && ($ambiguousCause !== null
                        || $this->isAmbiguousResponse($response, $responseCode)
                        || $idempotencyInProgress
                        || ($response->getStatusCode() >= 300 && $response->getStatusCode() < 400)
                        || $ambiguousSuccessResponse)) {
                    throw new OutcomeUnknownException($idempotencyKey, $ambiguousCause ?? $exception);
                }
                throw $exception;
            } catch (\RuntimeException $exception) {
                $status = $response->getStatusCode();
                if ($idempotencyKey !== null
                    && ($ambiguousCause !== null || ($status >= 200 && $status < 400) || $status >= 500)) {
                    throw new OutcomeUnknownException($idempotencyKey, $ambiguousCause ?? $exception);
                }
                throw $exception;
            }
        }
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    private function headers(string $method, array $headers, bool $hasBody): array
    {
        $idempotencyHeaderNames = array_values(array_filter(
            array_keys($headers),
            static fn (string|int $name): bool => strcasecmp((string) $name, 'Idempotency-Key') === 0
        ));
        if (count($idempotencyHeaderNames) > 1) {
            throw new \InvalidArgumentException('Idempotency-Key duplicada com casing diferente.');
        }
        $normalized = array_change_key_case($headers, CASE_LOWER);
        if (isset($normalized['authorization'])) {
            throw new \InvalidArgumentException('Authorization e controlado pelo SDK.');
        }
        $headers = array_merge($headers, [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $this->apiKey,
            'User-Agent' => 'mupag-sdk/0.1.0',
        ]);

        if ($hasBody) {
            $headers['Content-Type'] = $headers['Content-Type'] ?? 'application/json';
        }

        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            if (array_key_exists('idempotency-key', $normalized)) {
                $this->validateIdempotencyKey($normalized['idempotency-key']);
                if ($idempotencyHeaderNames !== []) {
                    unset($headers[$idempotencyHeaderNames[0]]);
                }
                $headers['Idempotency-Key'] = $normalized['idempotency-key'];
            } else {
                $headers['Idempotency-Key'] = 'sdk-php-' . bin2hex(random_bytes(16));
            }
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

        $queryString = http_build_query($query);
        if (strlen($queryString) > 16_384) {
            throw new \InvalidArgumentException('Query string excede o limite seguro.');
        }

        return $uri . '?' . $queryString;
    }

    /**
     * @return array<string, mixed>
     */
    private function handleResponse(ResponseInterface $response): array
    {
        $status = $response->getStatusCode();
        try {
            $decoded = $this->decode($response);
        } catch (ApiException $exception) {
            if ($status < 400) {
                throw $exception;
            }
            $decoded = [];
        }

        if ($status >= 200 && $status < 300) {
            return $decoded;
        }

        $requestId = $this->stringValue($decoded['request_id'] ?? null) ?: $response->getHeaderLine('X-Request-Id') ?: null;
        $apiCode = $this->stringValue($decoded['code'] ?? null);
        $suggestion = $this->stringValue($decoded['suggestion'] ?? null);
        $documentationUrl = $this->stringValue($decoded['documentation_url'] ?? null);
        $message = $this->stringValue($decoded['detail'] ?? $decoded['message'] ?? $decoded['title'] ?? null)
            ?? 'A API MuPag retornou erro.';

        if ($status === 429) {
            $retryAfter = RetryPolicy::retryAfterSeconds($response);
            throw new RateLimitException($message, $apiCode, $requestId, $suggestion, $documentationUrl, $retryAfter);
        }

        throw new ApiException($message, $status, $apiCode, $requestId, $suggestion, $documentationUrl);
    }

    private function responseCode(ResponseInterface $response): ?string
    {
        $decoded = $this->peekDecoded($response);

        return $this->stringValue($decoded['code'] ?? null);
    }

    private function isAmbiguousResponse(ResponseInterface $response, ?string $responseCode): bool
    {
        $status = $response->getStatusCode();

        return in_array($status, [408, 425], true)
            || $status >= 500
            || ($status === 409
                && ($responseCode === null
                    || in_array($responseCode, ['idempotency_in_progress', 'idempotency_outcome_unknown'], true)));
    }

    private function responseExceptionSnapshot(ResponseInterface $response): ApiException
    {
        $decoded = $this->peekDecoded($response);
        $status = $response->getStatusCode();
        $requestId = $this->stringValue($decoded['request_id'] ?? null)
            ?: $response->getHeaderLine('X-Request-Id')
            ?: null;
        $message = $this->stringValue($decoded['detail'] ?? $decoded['message'] ?? $decoded['title'] ?? null)
            ?? 'A resposta da mutacao nao confirmou seu resultado.';

        return new ApiException(
            $message,
            $status,
            $this->stringValue($decoded['code'] ?? null),
            $requestId,
            $this->stringValue($decoded['suggestion'] ?? null),
            $this->stringValue($decoded['documentation_url'] ?? null)
        );
    }

    private function unreadableResponseException(
        ResponseInterface $response,
        ApiException $cause
    ): ApiException {
        $status = $response->getStatusCode();
        if ($status === 429) {
            return new RateLimitException(
                $cause->getMessage(),
                null,
                $response->getHeaderLine('X-Request-Id') ?: null,
                null,
                null,
                RetryPolicy::retryAfterSeconds($response)
            );
        }
        if ($status >= 400) {
            return new ApiException(
                $cause->getMessage(),
                $status,
                'http_' . $status,
                $response->getHeaderLine('X-Request-Id') ?: null,
                previous: $cause
            );
        }

        return $cause;
    }

    private function bufferedResponse(ResponseInterface $response): ResponseInterface
    {
        $contentLength = trim($response->getHeaderLine('Content-Length'));
        $body = $response->getBody();
        try {
            if ($contentLength !== ''
                && (!ctype_digit($contentLength) || (int) $contentLength > $this->maxResponseBytes)) {
                throw new ApiException(
                    'Corpo da resposta excede o limite configurado.',
                    0,
                    'response_too_large'
                );
            }
            $contents = '';
            while (!$body->eof()) {
                $remaining = $this->maxResponseBytes + 1 - strlen($contents);
                if ($remaining <= 0) {
                    throw new ApiException(
                        'Corpo da resposta excede o limite configurado.',
                        0,
                        'response_too_large'
                    );
                }
                $contents .= $body->read(min(8192, $remaining));
                if (strlen($contents) > $this->maxResponseBytes) {
                    throw new ApiException(
                        'Corpo da resposta excede o limite configurado.',
                        0,
                        'response_too_large'
                    );
                }
            }

            return $response->withBody(Utils::streamFor($contents));
        } finally {
            $body->close();
        }
    }

    /** @return array<string, mixed> */
    private function peekDecoded(ResponseInterface $response): array
    {
        $body = $response->getBody();
        if (!$body->isSeekable()) {
            return [];
        }
        $position = $body->tell();
        $body->rewind();
        $contents = $body->getContents();
        $body->seek($position);
        if ($contents === '') {
            return [];
        }
        try {
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        $contentLength = trim($response->getHeaderLine('Content-Length'));
        if ($contentLength !== '' && (!ctype_digit($contentLength) || (int) $contentLength > $this->maxResponseBytes)) {
            throw new ApiException('Corpo da resposta excede o limite configurado.', 0, 'response_too_large');
        }
        $body = $response->getBody();
        $contents = '';
        while (!$body->eof()) {
            $remaining = $this->maxResponseBytes + 1 - strlen($contents);
            if ($remaining <= 0) {
                throw new ApiException('Corpo da resposta excede o limite configurado.', 0, 'response_too_large');
            }
            $contents .= $body->read(min(8192, $remaining));
            if (strlen($contents) > $this->maxResponseBytes) {
                throw new ApiException('Corpo da resposta excede o limite configurado.', 0, 'response_too_large');
            }
        }
        if ($contents === '') {
            return [];
        }

        try {
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ApiException('Resposta da API nao e JSON valido.', 0, 'invalid_json_response', previous: $exception);
        }

        if (!is_array($decoded)) {
            throw new ApiException('Resposta da API precisa ser um objeto JSON.', 0, 'invalid_json_response');
        }

        return $decoded;
    }

    private function stringValue(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return substr((string) preg_replace('/[\x00-\x1F\x7F]/', ' ', $value), 0, 1024);
    }

    private function validateIdempotencyKey(mixed $key): void
    {
        if (!is_string($key) || preg_match('/\A[\x21-\x7E]{1,128}\z/D', $key) !== 1) {
            throw new \InvalidArgumentException('Idempotency-Key deve ter de 1 a 128 caracteres ASCII visiveis.');
        }
    }

    /**
     * @param array<string, string> $headers
     */
    private function mutationIdempotencyKey(string $method, array $headers): ?string
    {
        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return null;
        }
        foreach ($headers as $name => $value) {
            if (strcasecmp($name, 'Idempotency-Key') === 0) {
                return $value;
            }
        }
        throw new \LogicException('Mutacao sem Idempotency-Key.');
    }

    private function validateBaseUrl(string $baseUrl): void
    {
        $parts = parse_url($baseUrl);
        $host = is_array($parts) && is_string($parts['host'] ?? null) ? strtolower($parts['host']) : '';
        $scheme = is_array($parts) && is_string($parts['scheme'] ?? null) ? strtolower($parts['scheme']) : '';
        $loopback = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
        $path = is_array($parts) && is_string($parts['path'] ?? null) ? $parts['path'] : '';
        if (!is_array($parts) || $host === '' || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || !in_array($path, ['', '/'], true)
            || ($scheme !== 'https' && !($scheme === 'http' && $loopback))) {
            throw new \InvalidArgumentException('baseUrl invalida.');
        }
    }
}
