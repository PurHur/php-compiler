<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * libcrypt(3) replacement via host crypt() (#14182, php-in-PHP).
 *
 * php-src: ext/standard/crypt.c, php_crypt_r.c
 */
final class VmPasswordPure
{
    public static function available(): bool
    {
        return \function_exists('crypt');
    }

    public static function crypt(string $key, string $salt): ?string
    {
        if (!\function_exists('crypt')) {
            return null;
        }

        $result = \crypt($key, $salt);
        if (!\is_string($result) || '' === $result) {
            return null;
        }

        return $result;
    }
}
