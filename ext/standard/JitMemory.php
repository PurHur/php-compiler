<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\MemoryRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for memory_get_usage/memory_get_peak_usage (issue #3134, #5377). */
final class JitMemory
{
    public static function getUsage(Context $context, ?JITVariable $realUsage = null): Value
    {
        $real = self::resolveRealUsage($context, $realUsage);
        $usage = self::readUsage($context, $real);
        self::updatePeak($context, $real, $usage);

        return self::boxLong($context, $usage);
    }

    public static function getPeakUsage(Context $context, ?JITVariable $realUsage = null): Value
    {
        $real = self::resolveRealUsage($context, $realUsage);
        $usage = self::readUsage($context, $real);
        self::updatePeak($context, $real, $usage);
        $peak = self::loadPeak($context, $real);

        return self::boxLong($context, $peak);
    }

    public static function resetPeakUsage(Context $context): Value
    {
        MemoryRuntime::ensureLinked($context);
        $currentEmalloc = MemoryRuntime::readEmallocBytes($context);
        $currentRss = MemoryRuntime::readRssBytes($context);
        $context->builder->store($currentEmalloc, MemoryRuntime::peakGlobal($context, false));
        $context->builder->store($currentRss, MemoryRuntime::peakGlobal($context, true));

        return $context->getTypeFromString('int32')->constInt(0, false);
    }

    private static function resolveRealUsage(Context $context, ?JITVariable $realUsage): Value
    {
        if (null === $realUsage) {
            return $context->constantFromBool(false);
        }

        return JitBoolArg::lower($context, $realUsage, 'memory_get_*() real_usage');
    }

    private static function readUsage(Context $context, Value $realUsage): Value
    {
        $rss = MemoryRuntime::readRssBytes($context);
        $emalloc = MemoryRuntime::readEmallocBytes($context);

        return $context->builder->select($realUsage, $rss, $emalloc);
    }

    private static function updatePeak(Context $context, Value $realUsage, Value $usage): void
    {
        $peakReal = MemoryRuntime::peakGlobal($context, true);
        $peakEmalloc = MemoryRuntime::peakGlobal($context, false);
        $oldReal = $context->builder->load($peakReal);
        $oldEmalloc = $context->builder->load($peakEmalloc);
        $oldPeak = $context->builder->select($realUsage, $oldReal, $oldEmalloc);
        $isGreater = $context->builder->icmp(Builder::INT_SGT, $usage, $oldPeak);
        $newPeak = $context->builder->select($isGreater, $usage, $oldPeak);
        $newReal = $context->builder->select($realUsage, $newPeak, $oldReal);
        $newEmalloc = $context->builder->select($realUsage, $oldEmalloc, $newPeak);
        $context->builder->store($newReal, $peakReal);
        $context->builder->store($newEmalloc, $peakEmalloc);
    }

    private static function loadPeak(Context $context, Value $realUsage): Value
    {
        $peakReal = $context->builder->load(MemoryRuntime::peakGlobal($context, true));
        $peakEmalloc = $context->builder->load(MemoryRuntime::peakGlobal($context, false));

        return $context->builder->select($realUsage, $peakReal, $peakEmalloc);
    }

    private static function boxLong(Context $context, Value $longVal): Value
    {
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeLong($context, $slot, $longVal);

        return JitValueBox::pointer($context, $slot);
    }
}
