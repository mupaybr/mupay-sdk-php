<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MuPag\Sdk\MuPagClient;

$mupag = MuPagClient::test((string) getenv('MUPAG_API_KEY'));

$charge = $mupag->charges->create(
    [
        'amount_cents' => 9900,
        'payment_method' => 'pix',
        'customer' => [
            'name' => 'Ana Silva',
            'email' => 'ana@example.test',
            'tax_id' => '12345678901',
        ],
    ],
    'example_order_123'
);

echo json_encode($charge, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
