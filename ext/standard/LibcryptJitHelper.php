<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\NestedJitCompileScope;

/**
 * __compiler_libcrypt() for compiled JIT/AOT modules (#9275, php-in-PHP).
 *
 * Host crypt(3) only — must not recurse through __compiler_libcrypt during nested JIT.
 * SSOT: {@see __compiler_libcrypt}, {@see VmPasswordPure}
 * php-src: ext/standard/crypt.c
 */
final class LibcryptJitHelper
{
    /**
     * @return string|null null when crypt fails (JIT ABI uses null __string__*)
     *
     * NestedJIT (isActive folded true under helper compile — #26773): thin
     * {@see phpc_libcrypt_kernel} → libc crypt(3). Host unit tests / VM-adjacent:
     * PHP crypt(). Never call crypt() under NestedJIT — that lowers into
     * {@see PasswordJitHelper} and nulls AOT password_hash.
     */
    public static function cryptArgv(string $key, string $salt): ?string
    {
        if (NestedJitCompileScope::isActive()) {
            $result = \phpc_libcrypt_kernel($key, $salt);
        } elseif (\function_exists('crypt')) {
            $result = \crypt($key, $salt);
        } else {
            return null;
        }
        if (!\is_string($result) || '' === $result || '*' === $result[0]) {
            return null;
        }

        return $result;
    }
}
