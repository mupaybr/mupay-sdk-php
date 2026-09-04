# MuPag SDK para PHP

Integre pagamentos MuPag em poucos minutos com um SDK pequeno, tipado e feito para quem quer criar uma cobranca sem estudar a API inteira primeiro.

```bash
composer require mupag/mupag-sdk
```

> Compatibilidade: o pacote, o namespace e a classe cliente principal foram renomeados: de
> `mupaybr/mupay-sdk`, `Mupay\Sdk\Mupay` para `mupag/mupag-sdk`, `MuPag\Sdk\MuPagClient`.

Com isso voce ganha:

- API idiomatica: `$mupag->charges->create(...)`
- sandbox pronto com `MuPagClient::test(...)`
- `Idempotency-Key` automatica por invocacao e chave de negocio explicita para fluxos financeiros
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

use MuPag\Sdk\MuPagClient;

$mupag = MuPagClient::test(getenv('MUPAG_API_KEY'));

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
    idempotencyKey: 'order_123_charge_1',
);

echo $charge['charge_id'] . PHP_EOL;
```

Pronto: voce recebe o objeto da cobranca e pode mostrar o QR Code ou link de pagamento retornado pela API.

## Configuracao

Use `MuPagClient::test()` para sandbox (`sk_test_*`) e `MuPagClient::prd()` para producao (`sk_prd_*`).

```php
use MuPag\Sdk\Http\RetryPolicy;
use MuPag\Sdk\MuPagClient;

$mupag = MuPagClient::test(
    apiKey: getenv('MUPAG_API_KEY'),
    retryPolicy: new RetryPolicy(maxRetries: 2, baseDelayMs: 200),
    timeoutSeconds: 10.0,
);
```

Se seu app ja usa um client HTTP PSR-18 ou logger PSR-3, injete direto no factory. O SDK nao salva chave em arquivo, cache ou log.

## Criar cobranca

```php
$charge = $mupag->charges->create(
    [
        'amount_cents' => 14990,
        'payment_method' => 'pix',
        'customer' => [
            'id' => 'customer_123',
            'name' => 'Ana Silva',
            'email' => 'ana@example.test',
            'tax_id' => '12345678901',
        ],
    ],
    idempotencyKey: 'order_123_charge_1',
);
```

Se voce nao passar `idempotencyKey`, o SDK gera uma chave segura e a reutiliza nos retries internos
daquela invocacao. Uma nova chamada gera outra chave. Em pagamentos, use desde o inicio uma chave
estavel derivada do ID imutavel da operacao de negocio.

Para cartão, envie também `payer_ip` com o IP literal do pagador observado no checkout (não o IP
do servidor do merchant). O contrato atual aceita uma parcela, rejeita `soft_descriptor` e falha
fechado quando o merchant exige 3DS.

## Listar cobrancas sem pensar em pagina

```php
foreach ($mupag->charges->all(['limit' => 50]) as $charge) {
    echo $charge['charge_id'] . PHP_EOL;
}
```

O iterator busca a proxima pagina apenas quando necessario. Seu codigo continua simples mesmo com milhares de cobrancas.

## Cancelar assinatura

```php
$subscription = $mupag->subscriptions->cancel(
    id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
    mode: 'immediate',
    reason: 'pedido do cliente',
    idempotencyKey: 'cancel_sub_123',
);
```

O cancelamento usa `POST /v1/subscriptions/{id}/cancel` com um payload contendo `mode` e `reason`.
O backend decide a transicao de estado e preserva auditoria.

## Verificar webhook

Nunca confie no payload antes de validar assinatura:

```php
$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_MUPAG_SIGNATURE'] ?? '';

$event = $mupag->webhooks->constructEvent(
    payload: $payload,
    signatureHeader: $signature,
    secret: getenv('MUPAG_WEBHOOK_SECRET'),
);
```

O payload assinado e `{timestamp}.{raw_json_body}`. A assinatura usa HMAC-SHA256 e comparacao em tempo constante com `hash_equals`; o JSON segue `{ "id": string, "type": string, "data": object }`.

## Tratar erros sem adivinhar

```php
use MuPag\Sdk\Exception\ApiException;
use MuPag\Sdk\Exception\OutcomeUnknownException;
use MuPag\Sdk\Exception\RateLimitException;

try {
    $mupag->charges->create(
        [
            'amount_cents' => 9900,
            'payment_method' => 'pix',
            'customer' => [
                'name' => 'Cliente Exemplo',
                'email' => 'cliente@example.test',
                'tax_id' => '00000000000',
            ],
        ],
        idempotencyKey: 'order_123_error_handling',
    );
} catch (OutcomeUnknownException $exception) {
    persist_for_reconciliation($exception->idempotencyKey());
} catch (RateLimitException $exception) {
    retry_later($exception->retryAfterSeconds());
} catch (ApiException $exception) {
    error_log($exception->apiCode() . ' request_id=' . $exception->requestId());
}
```

`ApiException` preserva status HTTP, codigo estavel da API, sugestao, URL de documentacao e `request_id`. Isso reduz tentativa e erro durante integracao.

`OutcomeUnknownException` significa que a mutacao pode ter sido aceita, mas a resposta final nao foi
confirmada. `idempotencyKey()` retorna exatamente a chave enviada: persista-a e reconcilie ou repita
somente o mesmo payload. Retries limitados cobrem transporte, `408`, `425`, `429`, `5xx` e
`409/idempotency_in_progress`, usando backoff exponencial com jitter e `Retry-After` limitado.
`409/idempotency_outcome_unknown` e qualquer `409` sem codigo reconhecidamente definitivo ficam
desconhecidos imediatamente. Somente `fingerprint_conflict`, `idempotency_fingerprint_conflict` e
`idempotency_key_reused` sao definitivos quando nenhuma tentativa anterior ficou ambigua. Depois de uma
ambiguidade, apenas um `2xx` JSON estrutural e financeiramente valido confirma o resultado; um `4xx`,
`409` ou `429` posterior nao o redefine como definitivo.

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

## Publicacao no Packagist

Nome do pacote Composer: `mupag/mupag-sdk`.

O repositorio de publicacao e [mupaybr/mupag-sdk-php](https://github.com/mupaybr/mupag-sdk-php). Para ficar publico para qualquer dev PHP usar com `composer require mupag/mupag-sdk`, o pacote precisa estar no Packagist e o `composer.json` precisa estar na raiz desse repositorio.

### Migracao para MuPag 0.2.0

Atualize a dependencia, o namespace e a classe cliente juntos:

Antes:

```bash
composer require mupaybr/mupay-sdk
```

```php
use Mupay\Sdk\Mupay;

$mupay = Mupay::test(getenv('MUPAY_API_KEY'));
```

Depois:

```bash
composer remove mupaybr/mupay-sdk
composer require mupag/mupag-sdk:^0.2.0
```

```php
use MuPag\Sdk\MuPagClient;
```

O pacote e o namespace antigos nao fazem parte da API publica da versao 0.2.0.

Fluxo recomendado:

1. Manter o conteudo publicavel do SDK na raiz de `mupaybr/mupag-sdk-php`.
2. Criar a tag SemVer `v0.2.0`.
3. Entrar no Packagist, clicar em Submit e informar a URL publica do repositorio.
4. Habilitar hook/auto-update do Packagist para novas tags.

Depois disso, qualquer projeto PHP instala assim:

```bash
composer require mupag/mupag-sdk
```
