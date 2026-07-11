<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

/**
 * OpenSSL error queue drain via libcrypto FFI (php-src ext/openssl/openssl.c — openssl_error_string).
 */
final class VmOpensslErrorNative
{
    /** @var \FFI|null */
    private static $ffi = null;

    private static bool $ffiUnavailable = false;

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    public static function errorString(): string|false
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $err = (int) $ffi->ERR_get_error();
        if (0 === $err) {
            return false;
        }

        $buf = $ffi->new('char[256]');
        $ffi->ERR_error_string_n($err, $buf, 256);

        $message = \FFI::string($buf);
        if ('' === $message) {
            return false;
        }

        return $message;
    }

    /** @return \FFI|null */
    private static function ffi()
    {
        if (!self::ffiEnabled()) {
            return null;
        }
        if (self::$ffiUnavailable) {
            return null;
        }
        if (null !== self::$ffi) {
            return self::$ffi;
        }
        if (!\extension_loaded('ffi')) {
            self::$ffiUnavailable = true;

            return null;
        }

        $cdef = <<<'CDEF'
unsigned long ERR_get_error(void);
void ERR_error_string_n(unsigned long e, char *buf, size_t len);
char *ERR_error_string(unsigned long e, char *buf);
CDEF;

        foreach (['libcrypto.so.3', 'libcrypto.so'] as $lib) {
            try {
                self::$ffi = \FFI::cdef($cdef, $lib);

                return self::$ffi;
            } catch (\Throwable) {
            }
        }

        self::$ffiUnavailable = true;

        return null;
    }

    private static function ffiEnabled(): bool
    {
        $v = getenv('PHP_COMPILER_DISABLE_FFI');
        if (false !== $v && '' !== $v && '0' !== $v && 'false' !== strtolower($v)) {
            return false;
        }

        return true;
    }
}
