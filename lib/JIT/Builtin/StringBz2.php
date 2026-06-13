<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT LLVM bodies for bzcompress/bzdecompress (#3402).
 *
 * PHP lowering via {@see StringBz2Jit}; links libbz2 at AOT link time.
 */
final class StringBz2
{
    public static function ensureLinked(Context $context): void
    {
        self::preloadLibbz2();
        StringBz2Jit::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::preloadLibbz2();
        StringBz2Jit::implement($context);
    }

    public static function preloadLibbz2(): void
    {
        static $loaded = 0;
        if ($loaded) {
            return;
        }
        if (!\extension_loaded('FFI')) {
            return;
        }
        $selfHost = getenv('PHP_COMPILER_SELFHOST_AOT');
        if ('1' === $selfHost || 'true' === strtolower((string) $selfHost)) {
            $loaded = 1;

            return;
        }
        try {
            $dl = \FFI::cdef('void *dlopen(const char *filename, int flags);', 'libdl.so.2');
            foreach (['libbz2.so.1.0', 'libbz2.so.1', 'libbz2.so'] as $lib) {
                if (null !== $dl->dlopen($lib, 0x101)) {
                    break;
                }
            }
        } catch (\Throwable) {
        }
        $loaded = 1;
    }
}
