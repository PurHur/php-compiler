<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT LLVM bodies for bzcompress/bzdecompress (#3402, #8868).
 *
 * PHP lowering via {@see Bz2Runtime} → {@see \PHPCompiler\ext\bz2\Bz2JitHelper}.
 */
final class StringBz2
{
    public static function ensureLinked(Context $context): void
    {
        Bz2Runtime::ensureLinked($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        Bz2Runtime::implement($context);
    }
}
