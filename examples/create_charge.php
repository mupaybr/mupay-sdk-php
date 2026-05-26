<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Mupay\Sdk\Mupay;

$mupay = Mupay::test((string) getenv('MUPAY_API_KEY'));

$charge = $mupay->charges->create(
    [
        'amount' => 9900,
        'currency' => 'BRL',
        'payment_method' => 'pix',
        'customer' => [
            'name' => 'Ana Silva',
            'email' => 'ana@example.test',
        ],
    ],
    'example_order_123'
);

echo json_encode($charge, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
