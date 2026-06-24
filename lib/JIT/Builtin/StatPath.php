<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT/AOT file predicates + stat fields — LLVM mirror of {@see \PHPCompiler\ext\standard\VmStatPath} (#9112).
 */
final class StatPath
{
    public static function ensureLinked(Context $context): void
    {
        StatPathRuntime::ensureLinked($context);
    }
}
