<?php

declare(strict_types=1);

namespace MuPag\Sdk\Pagination;

use MuPag\Sdk\Http\ApiClient;

final class PageIterator implements \IteratorAggregate
{
    /**
     * @param array<string, mixed> $params
     */
    public function __construct(
        private readonly ApiClient $client,
        private readonly string $path,
        private readonly array $params = [],
        private readonly ?\Closure $itemValidator = null
    ) {
    }

    /**
     * Busca paginas sob demanda para evitar carregar listas grandes em memoria.
     *
     * @return \Traversable<int, array<string, mixed>>
     */
    public function getIterator(): \Traversable
    {
        $params = $this->params;
        $seenCursors = [];
        $pages = 0;
        $initialCursor = $params['cursor'] ?? null;
        $effectiveLimit = is_int($params['limit'] ?? null) ? $params['limit'] : 25;

        if ($initialCursor !== null) {
            if (!CursorValidator::isCanonicalBase64Url($initialCursor)) {
                throw new \InvalidArgumentException('cursor invalido.');
            }
            $seenCursors[$initialCursor] = true;
        }

        do {
            $pages++;
            if ($pages > 1_000) {
                throw new \RuntimeException('Paginacao excedeu o limite seguro de 1000 paginas.');
            }
            $response = $this->client->get($this->path, $params);
            if (array_key_exists('next_cursor', $response)) {
                $cursor = $response['next_cursor'];
            } elseif (array_key_exists('meta', $response)) {
                if (!is_array($response['meta'])) {
                    throw new \RuntimeException('API retornou metadados invalidos durante paginacao.');
                }
                $cursor = $response['meta']['next_cursor'] ?? null;
            } else {
                $cursor = null;
            }
            if ($cursor === '') {
                $cursor = null;
            }
            if ($cursor !== null) {
                if (!CursorValidator::isCanonicalBase64Url($cursor)) {
                    throw new \RuntimeException('API retornou cursor invalido durante paginacao.');
                }
                if (isset($seenCursors[$cursor])) {
                    throw new \RuntimeException('API retornou cursor repetido durante paginacao.');
                }
                $seenCursors[$cursor] = true;
            }

            if (!array_key_exists('data', $response)
                || !is_array($response['data'])
                || !array_is_list($response['data'])
                || count($response['data']) > $effectiveLimit) {
                throw new \RuntimeException('API retornou pagina de dados invalida.');
            }
            $items = [];
            foreach ($response['data'] as $item) {
                if (!is_array($item)) {
                    throw new \RuntimeException('API retornou item de pagina invalido.');
                }
                $items[] = $this->itemValidator === null
                    ? $item
                    : ($this->itemValidator)($item);
            }
            foreach ($items as $item) {
                yield $item;
            }

            if ($cursor !== null) {
                $params['cursor'] = $cursor;
                continue;
            }

            $cursor = null;
        } while ($cursor !== null);
    }
}
