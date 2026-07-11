<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT LLVM bodies for gzcompress/gzuncompress/gzdeflate/gzinflate/gzencode/gzdecode (#3194, #6791, #9879).
 *
 * PHP lowering via {@see ZlibRuntime} → {@see \PHPCompiler\ext\standard\ZlibJitHelper} (#13347, #13858).
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
