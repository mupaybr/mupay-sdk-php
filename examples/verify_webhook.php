<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Mupay\Sdk\Exception\WebhookVerificationException;
use Mupay\Sdk\Mupay;

$mupay = Mupay::test((string) getenv('MUPAY_API_KEY'));
$payload = (string) file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_GATEWAY_SIGNATURE'] ?? '';

try {
    $event = $mupay->webhooks->constructEvent($payload, $signature, (string) getenv('MUPAY_WEBHOOK_SECRET'));
    http_response_code(200);
    echo 'received ' . $event['id'] . PHP_EOL;
} catch (WebhookVerificationException) {
    http_response_code(400);
    echo 'invalid signature' . PHP_EOL;
}
