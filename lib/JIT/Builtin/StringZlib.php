<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT LLVM bodies for gzcompress/gzuncompress/gzdeflate/gzinflate/gzencode/gzdecode (#3194, #6791, #9879, #26864).
 *
 * Routes through {@see ZlibRuntime} → thin libz {@see StringZlibJit} (NestedJIT of VmZlibCore
 * SEGV under thin AOT — #26864). VM SSOT: {@see \PHPCompiler\ext\standard\VmZlibCore}.
 */
final class StringZlib
{
    public static function ensureLinked(Context $context): void
    {
        ZlibRuntime::ensureLinked($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        ZlibRuntime::ensureStandaloneBodies($context);
    }
}
