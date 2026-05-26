# Mupay SDK para PHP

Integre pagamentos Mupay em poucos minutos com um SDK pequeno, tipado e feito para quem quer criar uma cobranca sem estudar a API inteira primeiro.

```bash
composer require mupay/mupay-sdk
```

Com isso voce ganha:

- API idiomatica: `$mupay->charges->create(...)`
- sandbox pronto com `Mupay::test(...)`
- `Idempotency-Key` automatica em chamadas mutaveis
- retries com backoff para falhas transientes e `429`
- erros tipados com `request_id`, `code`, `suggestion` e link de documentacao
- paginacao automatica por `Iterator`
- verificacao de webhooks com HMAC-SHA256 e tolerancia de timestamp
- suporte a PSR-18 para cliente HTTP e PSR-3 para logger

## Pague em 5 minutos

Crie uma cobranca PIX de teste:

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Mupay\Sdk\Mupay;

$mupay = Mupay::test(getenv('MUPAY_API_KEY'));

$charge = $mupay->charges->create([
    'amount' => 9900,
    'currency' => 'BRL',
    'payment_method' => 'pix',
    'customer' => [
        'name' => 'Ana Silva',
        'email' => 'ana@example.test',
    ],
]);

echo $charge['id'] . PHP_EOL;
```

Pronto: voce recebe o objeto da cobranca e pode mostrar o QR Code ou link de pagamento retornado pela API.

## Configuracao

Use `Mupay::test()` para sandbox (`sk_test_*`) e `Mupay::live()` para producao (`sk_live_*`).

```php
use Mupay\Sdk\Http\RetryPolicy;
use Mupay\Sdk\Mupay;

$mupay = Mupay::test(
    apiKey: getenv('MUPAY_API_KEY'),
    retryPolicy: new RetryPolicy(maxRetries: 2, baseDelayMs: 200),
    timeoutSeconds: 10.0,
);
```

Se seu app ja usa um client HTTP PSR-18 ou logger PSR-3, injete direto no factory. O SDK nao salva chave em arquivo, cache ou log.

## Criar cobranca

```php
$charge = $mupay->charges->create(
    [
        'amount' => 14990,
        'currency' => 'BRL',
        'payment_method' => 'pix',
    ],
    idempotencyKey: 'order_123_charge_1',
);
```

Se voce nao passar `idempotencyKey`, o SDK gera uma chave segura para a requisicao. Isso deixa retry seguro para lojistas e plugins.

## Listar cobrancas sem pensar em pagina

```php
foreach ($mupay->charges->all(['limit' => 50]) as $charge) {
    echo $charge['id'] . PHP_EOL;
}
```

O iterator busca a proxima pagina apenas quando necessario. Seu codigo continua simples mesmo com milhares de cobrancas.

## Cancelar assinatura

```php
$subscription = $mupay->subscriptions->cancel('sub_123', 'cancel_sub_123');
```

O cancelamento usa `POST /v1/subscriptions/{id}/cancel` sem payload. O backend decide a transicao de estado e preserva auditoria.

## Verificar webhook

Nunca confie no payload antes de validar assinatura:

```php
$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_GATEWAY_SIGNATURE'] ?? '';

$event = $mupay->webhooks->constructEvent(
    payload: $payload,
    signatureHeader: $signature,
    secret: getenv('MUPAY_WEBHOOK_SECRET'),
);
```

O payload assinado e `{timestamp}.{raw_json_body}`. A assinatura usa HMAC-SHA256 e comparacao em tempo constante com `hash_equals`.

## Tratar erros sem adivinhar

```php
use Mupay\Sdk\Exception\ApiException;
use Mupay\Sdk\Exception\RateLimitException;

try {
    $mupay->charges->create(['amount' => 9900]);
} catch (RateLimitException $exception) {
    retry_later($exception->retryAfterSeconds());
} catch (ApiException $exception) {
    error_log($exception->apiCode() . ' request_id=' . $exception->requestId());
}
```

`ApiException` preserva status HTTP, codigo estavel da API, sugestao, URL de documentacao e `request_id`. Isso reduz tentativa e erro durante integracao.

## Exemplos

- `examples/create_charge.php`: cria cobranca PIX
- `examples/list_charges.php`: lista cobrancas com iterator
- `examples/verify_webhook.php`: valida assinatura de webhook

## Desenvolvimento

```bash
composer install
composer validate --strict
composer test
composer run lint
composer test:coverage
```
