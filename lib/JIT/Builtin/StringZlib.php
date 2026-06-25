<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT LLVM bodies for gzcompress/gzuncompress/gzdeflate/gzinflate/gzencode/gzdecode (#3194, #6791, #9879).
 *
 * PHP lowering via {@see ZlibRuntime} → {@see \PHPCompiler\ext\standard\ZlibJitHelper}.
 * Standalone AOT keeps {@see StringZlibJit} until nested helper link is reliable.
 */
final class StringZlib
{
    public static function ensureLinked(Context $context): void
    {
        self::preloadLibz();
        ZlibRuntime::ensureLinked($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::preloadLibz();
        ZlibRuntime::implement($context);
    }

    /** MCJIT resolves libz symbols from the host process (issue #3194). */
    public static function preloadLibz(): void
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
            foreach (['libz.so.1', 'libz.so'] as $lib) {
                if (null !== $dl->dlopen($lib, 0x101)) {
                    break;
                }
            }
        } catch (\Throwable $e) {
            // Best-effort: AOT links -lz explicitly.
        }
        $loaded = 1;
    }
}
