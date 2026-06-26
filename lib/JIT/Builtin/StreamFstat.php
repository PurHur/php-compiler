<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/** JIT LLVM bridge for fstat() via FstatJitHelper PHP (#10460). */
final class StreamFstat
{
    public static function ensureLinked(Context $context): void
    {
        StreamFstatRuntime::implement($context);
    }
}
