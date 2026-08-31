<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MuPag\Sdk\MuPagClient;

$mupag = MuPagClient::test((string) getenv('MUPAG_API_KEY'));

foreach ($mupag->charges->all(['limit' => 25]) as $charge) {
    echo $charge['id'] . ' ' . ($charge['status'] ?? 'unknown') . PHP_EOL;
}
