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
        private readonly array $params = []
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

        if (is_string($initialCursor) && $initialCursor !== '') {
            if (preg_match('/\A[\x21-\x7E]{1,256}\z/D', $initialCursor) !== 1) {
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
            $cursor = $response['next_cursor'] ?? $response['meta']['next_cursor'] ?? null;
            if (is_string($cursor) && $cursor !== '') {
                if (preg_match('/\A[\x21-\x7E]{1,256}\z/D', $cursor) !== 1) {
                    throw new \RuntimeException('API retornou cursor invalido durante paginacao.');
                }
                if (isset($seenCursors[$cursor])) {
                    throw new \RuntimeException('API retornou cursor repetido durante paginacao.');
                }
                $seenCursors[$cursor] = true;
            }

            $items = $response['data'] ?? [];

            if (is_array($items)) {
                foreach ($items as $item) {
                    if (is_array($item)) {
                        yield $item;
                    }
                }
            }

            if (is_string($cursor) && $cursor !== '') {
                $params['cursor'] = $cursor;
                continue;
            }

            $cursor = null;
        } while ($cursor !== null);
    }
}
