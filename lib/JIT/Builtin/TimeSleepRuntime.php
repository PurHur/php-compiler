<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;

/**
 * JIT/AOT link for __compiler_time_nanosleep / __compiler_time_sleep_until via SleepJitHelper (#9378, #21289).
 *
 * Same {@see JitVmHelperLink::ensureBridge} shape as {@see MathSleep} (#15212) — no hand-rolled
 * NestedJIT; NestedJitCompileScope comes from JitVmHelperLink.
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(time_nanosleep), time_sleep_until
 */
final class TimeSleepRuntime
{
    private const HELPER_PATH = '/ext/standard/SleepJitHelper.php';

    private const NANOSLEEP_HELPER = 'PHPCompiler\\ext\\standard\\SleepJitHelper::timeNanosleep';

    private const UNTIL_HELPER = 'PHPCompiler\\ext\\standard\\SleepJitHelper::timeSleepUntil';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::NANOSLEEP_HELPER,
        self::UNTIL_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implementNanosleepBridge($context);
        self::implementUntilBridge($context);
    }

    private static function implementNanosleepBridge(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        JitVmHelperLink::ensureBridge(
            $context,
            '__compiler_time_nanosleep',
            'time_nanosleep_bridge_entry',
            [$i64, $i64],
            $i32,
            self::NANOSLEEP_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#21289'
        );
    }

    private static function implementUntilBridge(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $double = $context->getTypeFromString('double');
        $i32 = $context->getTypeFromString('int32');
        JitVmHelperLink::ensureBridge(
            $context,
            '__compiler_time_sleep_until',
            'time_sleep_until_bridge_entry',
            [$double],
            $i32,
            self::UNTIL_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#21289'
        );
    }
}
