<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT/AOT stat/realpath cache — LLVM mirror of {@see \PHPCompiler\ext\standard\VmStatCache} (#9110).
 */
final class StatCache
{
    public static function ensureLinked(Context $context): void
    {
        StatCacheRuntime::ensureLinked($context);
    }
}
