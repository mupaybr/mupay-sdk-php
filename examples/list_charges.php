<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Mupay\Sdk\Mupay;

$mupay = Mupay::test((string) getenv('MUPAY_API_KEY'));

foreach ($mupay->charges->all(['limit' => 25]) as $charge) {
    echo $charge['id'] . ' ' . ($charge['status'] ?? 'unknown') . PHP_EOL;
}
