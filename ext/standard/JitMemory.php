<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringMemory;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for memory_get_usage/memory_get_peak_usage (issue #3134). */
final class JitMemory
{
    public static function getUsage(Context $context, ?JITVariable $realUsage = null): Value
    {
        return self::invoke($context, '__compiler_memory_get_usage', $realUsage);
    }

    public static function getPeakUsage(Context $context, ?JITVariable $realUsage = null): Value
    {
        return self::invoke($context, '__compiler_memory_get_peak_usage', $realUsage);
    }

    public static function resetPeakUsage(Context $context): Value
    {
        StringMemory::ensureLinked($context);

        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $context->builder->call(
            $context->lookupFunction('__compiler_memory_reset_peak_usage'),
            $zero
        );
        $context->builder->call(
            $context->lookupFunction('__compiler_memory_reset_peak_usage'),
            $one
        );

        return $context->getTypeFromString('int32')->constInt(0, false);
    }

    private static function invoke(Context $context, string $symbol, ?JITVariable $realUsage): Value
    {
        StringMemory::ensureLinked($context);

        $i64 = $context->getTypeFromString('int64');
        if (null !== $realUsage) {
            $realVal = $context->builder->zExt(
                JitBoolArg::lower($context, $realUsage, 'memory_get_*() real_usage'),
                $i64
            );
        } else {
            $realVal = $i64->constInt(0, false);
        }

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction($symbol),
            $realVal,
            $ptr
        );

        return $ptr;
    }
}
