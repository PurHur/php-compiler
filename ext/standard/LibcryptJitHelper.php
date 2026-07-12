<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * __compiler_libcrypt() for compiled JIT/AOT modules (#9275, php-in-PHP).
 *
 * Host crypt(3) only — must not recurse through __compiler_libcrypt during nested JIT.
 * SSOT: {@see __compiler_libcrypt}, {@see VmPasswordPure}
 * php-src: ext/standard/crypt.c
 */
final class LibcryptJitHelper
{
    /** @return string|null null when crypt fails (JIT ABI uses null __string__*) */
    public static function cryptArgv(string $key, string $salt): ?string
    {
        if (!\function_exists('crypt')) {
            return null;
        }
        $result = \crypt($key, $salt);
        if (!\is_string($result) || '' === $result || '*' === $result[0]) {
            return null;
        }

        return $result;
    }
}
