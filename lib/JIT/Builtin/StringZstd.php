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
        NativeDlopen::preloadLibraries(['libzstd.so.1', 'libzstd.so']);
        $loaded = 1;
    }
}
