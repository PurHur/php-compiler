<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitStreamContextKernel;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for stream context ABI via StreamContextJitHelper PHP (#9340, #12895, #19817).
 *
 * Thin orchestrator — NestedJIT bridges live in {@see JitStreamContextKernel}.
 */
final class StreamContextRuntime
{
    public static function ensureLinked(Context $context): void
    {
        JitStreamContextKernel::ensureLinked($context);
    }

    public static function implement(Context $context): void
    {
        JitStreamContextKernel::implement($context);
    }

    public static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        return JitStreamContextKernel::helperFunction($context, $logical);
    }
}
