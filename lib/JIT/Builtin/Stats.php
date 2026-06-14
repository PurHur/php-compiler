<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/** JIT LLVM bodies for ext/stats builtins (#5748). */
final class Stats
{
    public static function ensureLinked(Context $context): void
    {
        StatsJit::implement($context);
    }

    public static function implement(Context $context): void
    {
        self::ensureLinked($context);
    }
}
