<?php

declare(strict_types=1);

namespace MuPag\Sdk;

enum Environment: string
{
    case Test = 'test';
    case Prd = 'prd';

    public function baseUrl(): string
    {
        return match ($this) {
            self::Test => 'https://api.sandbox.mupag.com.br',
            self::Prd => 'https://api.mupag.com.br',
        };
    }
}
