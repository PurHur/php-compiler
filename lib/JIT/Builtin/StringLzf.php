<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT LLVM bodies for lzf_compress/lzf_decompress (#6384 phase 2).
 *
 * PHP lowering via {@see StringLzfJit}; links bundled liblzf at AOT link time.
 */
final class StringLzf
{
    public static function ensureLinked(Context $context): void
    {
        self::preloadLiblzf();
        StringLzfJit::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::preloadLiblzf();
        StringLzfJit::implement($context);
    }

    public static function preloadLiblzf(): void
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
        $root = \dirname(__DIR__, 3);
        $bundled = $root.'/.libs/liblzf.so';
        try {
            $dl = \FFI::cdef('void *dlopen(const char *filename, int flags);', 'libdl.so.2');
            if (\is_file($bundled) && null !== $dl->dlopen($bundled, 0x101)) {
                $loaded = 1;

                return;
            }
            foreach (['liblzf.so', 'liblzf.so.0'] as $lib) {
                if (null !== $dl->dlopen($lib, 0x101)) {
                    break;
                }
            }
        } catch (\Throwable) {
        }
        $loaded = 1;
    }
}
