<?php

declare(strict_types=1);

namespace MuPag\Sdk\Pagination;

/** @internal */
final class CursorValidator
{
    public static function isCanonicalBase64Url(mixed $cursor): bool
    {
        if (!is_string($cursor)
            || preg_match('/\A[A-Za-z0-9_-]{1,256}\z/D', $cursor) !== 1) {
            return false;
        }

        $remainder = strlen($cursor) % 4;
        if ($remainder === 1) {
            return false;
        }
        $padded = strtr($cursor, '-_', '+/') . str_repeat('=', (4 - $remainder) % 4);
        $decoded = base64_decode($padded, true);
        if ($decoded === false) {
            return false;
        }

        return rtrim(strtr(base64_encode($decoded), '+/', '-_'), '=') === $cursor;
    }
}
