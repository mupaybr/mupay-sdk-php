<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MuPag\Sdk\Exception\WebhookVerificationException;
use MuPag\Sdk\MuPagClient;

$mupag = MuPagClient::test((string) getenv('MUPAG_API_KEY'));
$payload = (string) file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_MUPAG_SIGNATURE'] ?? '';

try {
    $event = $mupag->webhooks->constructEvent($payload, $signature, (string) getenv('MUPAG_WEBHOOK_SECRET'));
    http_response_code(200);
    echo 'received ' . $event['id'] . PHP_EOL;
} catch (WebhookVerificationException) {
    http_response_code(400);
    echo 'invalid signature' . PHP_EOL;
}
