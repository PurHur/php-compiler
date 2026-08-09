<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * __compiler_libcrypt() for compiled JIT/AOT modules (#9275, #29545, php-in-PHP).
 *
 * Leaf is `@crypt` → NestedJIT whitelist {@see crypt} →
 * {@see crypt::call} → {@see JitLibcryptKernel} libc crypt(3)
 * (no kernel Internal; random_bytes #29531 / gethostname #29364 shape).
 * SSOT: {@see __compiler_libcrypt}, {@see VmPasswordPure}
 * php-src: ext/standard/crypt.c
 */
final class LibcryptJitHelper
{
    /**
     * @return string|null null when crypt fails (JIT ABI uses null __string__*)
     */
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
