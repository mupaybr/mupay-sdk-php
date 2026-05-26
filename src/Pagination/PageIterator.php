<?php

declare(strict_types=1);

namespace Mupay\Sdk\Pagination;

use Mupay\Sdk\Http\ApiClient;

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

        do {
            $response = $this->client->get($this->path, $params);
            $items = $response['data'] ?? [];

            if (is_array($items)) {
                foreach ($items as $item) {
                    if (is_array($item)) {
                        yield $item;
                    }
                }
            }

            $cursor = $response['meta']['next_cursor'] ?? null;
            if (is_string($cursor) && $cursor !== '') {
                $params['cursor'] = $cursor;
                continue;
            }

            $cursor = null;
        } while ($cursor !== null);
    }
}
