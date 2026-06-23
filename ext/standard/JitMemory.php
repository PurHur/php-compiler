<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\MemoryRuntime;
use PHPCompiler\JIT\Context;
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

    public static function resetPeakUsage(Context $context, ?JITVariable $realUsage = null): Value
    {
        $real = self::resolveRealUsage($context, $realUsage, 'memory_reset_peak_usage');
        MemoryRuntime::resetPeakUsage($context, $real);

        return $context->constantFromBool(true);
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
