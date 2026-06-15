<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Build a Set-Cookie response header line (setcookie / setrawcookie; issue #1170, #1368).
 */
final class SetcookieLine
{
    public static function build(
        string $name,
        string $value = '',
        int $expires = 0,
        string $path = '',
        string $domain = '',
        bool $secure = false,
        bool $httponly = false,
        string $samesite = '',
        bool $partitioned = false
    ): string {
        $parts = [$name.'='.$value];
        if ($expires > 0) {
            $parts[] = 'expires='.gmdate('D, d-M-Y H:i:s', $expires).' GMT';
        }
        if ('' !== $path) {
            $parts[] = 'path='.$path;
        }
        if ('' !== $domain) {
            $parts[] = 'domain='.$domain;
        }
        if ($secure) {
            $parts[] = 'secure';
        }
        if ($httponly) {
            $parts[] = 'httponly';
        }
        if ('' !== $samesite) {
            $parts[] = 'samesite='.$samesite;
        }
        if ($partitioned) {
            $parts[] = 'partitioned';
        }

        return 'Set-Cookie: '.implode('; ', $parts);
    }
}
