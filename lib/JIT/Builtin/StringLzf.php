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
        $root = \dirname(__DIR__, 3);
        $bundled = $root.'/.libs/liblzf.so';
        $candidates = [];
        if (\is_file($bundled)) {
            $candidates[] = $bundled;
        }
        NativeDlopen::preloadLibraries(array_merge($candidates, ['liblzf.so', 'liblzf.so.0']));
        $loaded = 1;
    }
}
