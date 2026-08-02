<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * idate() — AOT/JIT compute in {@see \PHPCompiler\ext\standard\JitIdate} IR (#26900).
 * NestedJIT / helper-runtime of IdateJitHelper is unsafe under thin AOT.
 */
final class StringIdate
{
    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        // Intentionally empty — see class docblock (#26900).
    }
}
