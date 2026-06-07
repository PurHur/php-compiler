<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * unlink(2) for VM — libc FFI when allowed; host unlink() otherwise (#5063, #7314).
 *
 * php-src: ext/standard/filestat.c — php_unlink
 * JIT/AOT: ext/standard/JitUnlink.php calls libc unlink(2) directly.
 */
final class VmFsUnlink
{
    private static ?\FFI $ffi = null;

    public static function unlink(string $path): bool
    {
        if (str_contains($path, "\0")) {
            return false;
        }
        if (self::ffiEnabled()) {
            $ffi = self::ffi();
            if (null !== $ffi) {
                return 0 === (int) $ffi->unlink($path);
            }
        }
        if (\function_exists('unlink')) {
            return @\unlink($path);
        }

        return false;
    }

    private static function ffiEnabled(): bool
    {
        $v = getenv('PHP_COMPILER_DISABLE_FFI');
        if (false !== $v && '' !== $v && '0' !== $v && 'false' !== strtolower($v)) {
            return false;
        }

        return true;
    }

    private static function ffi(): ?\FFI
    {
        if (null !== self::$ffi) {
            return self::$ffi;
        }
        if (!\extension_loaded('ffi')) {
            return null;
        }
        $cdef = <<<'CDEF'
int unlink(const char *pathname);
CDEF;

        foreach (['libc.so.6', 'libc.so'] as $lib) {
            try {
                self::$ffi = \FFI::cdef($cdef, $lib);

                return self::$ffi;
            } catch (\Throwable) {
            }
        }

        return null;
    }
}
