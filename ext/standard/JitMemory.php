<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\MemoryRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitMemoryUsageArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for memory_get_usage/memory_get_peak_usage (issue #3134, #5377, #9377). */
final class JitMemory
{
    public static function getUsage(Context $context, ?JITVariable $realUsage = null): Value
    {
        $real = self::resolveRealUsage($context, $realUsage, 'memory_get_usage');
        $usage = MemoryRuntime::getUsageValue($context, $real);

        return self::boxLong($context, $usage);
    }

    public static function getPeakUsage(Context $context, ?JITVariable $realUsage = null): Value
    {
        $real = self::resolveRealUsage($context, $realUsage, 'memory_get_peak_usage');
        $peak = MemoryRuntime::getPeakUsageValue($context, $real);

        return self::boxLong($context, $peak);
    }

    /**
     * memory_reset_peak_usage() — zero args, void (#26104 / php-src info.c).
     *
     * @param JITVariable ...$args
     */
    public static function resetPeakUsage(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (0 !== $argc) {
            $slot = JitValueBox::alloc($context);
            $result = JitValueBox::pointer($context, $slot);
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                'memory_reset_peak_usage() expects exactly 0 arguments, '.$argc.' given'
            );

            return $result;
        }
        MemoryRuntime::resetPeakUsage($context);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);

        return $ptr;
    }

    private static function resolveRealUsage(Context $context, ?JITVariable $realUsage, string $fn): Value
    {
        return JitMemoryUsageArg::lower($context, $realUsage, $fn);
    }

    private static function boxLong(Context $context, Value $longVal): Value
    {
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeLong($context, $slot, $longVal);

        return JitValueBox::pointer($context, $slot);
    }
}
