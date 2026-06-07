<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT LLVM bodies for glob()/scandir() vec collectors (#5459).
 */
final class StringFsGlob
{
    public static function ensureLinked(Context $context): void
    {
        StringFsGlobVecJit::implement($context);
    }

    public static function implement(Context $context): void
    {
        self::ensureLinked($context);
    }
}
