<?php

declare(strict_types=1);

namespace Mupay\Sdk;

enum Environment: string
{
    case Test = 'test';
    case Live = 'live';

    public function baseUrl(): string
    {
        return match ($this) {
            self::Test => 'https://api.sandbox.mupay.com',
            self::Live => 'https://api.mupay.com',
        };
    }
}
