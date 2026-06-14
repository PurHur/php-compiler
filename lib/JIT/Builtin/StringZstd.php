<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT LLVM bodies for zstd_compress/zstd_decompress (#6387, #8564).
 *
 * PHP lowering via {@see StringZstdJit}; links libzstd at AOT link time.
 */
final class StringZstd
{
    public static function ensureLinked(Context $context): void
    {
        self::preloadLibzstd();
        StringZstdJit::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::preloadLibzstd();
        StringZstdJit::implement($context);
    }

    /** MCJIT resolves libzstd symbols from the host process (#8564). */
    public static function preloadLibzstd(): void
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
            foreach (['libzstd.so.1', 'libzstd.so'] as $lib) {
                if (null !== $dl->dlopen($lib, 0x101)) {
                    break;
                }
            }
        } catch (\Throwable) {
            // Best-effort: AOT links -lzstd explicitly.
        }
        $loaded = 1;
    }
}
