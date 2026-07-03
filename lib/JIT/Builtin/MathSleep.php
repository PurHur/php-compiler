<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for sleep()/usleep() via SleepJitHelper PHP (#15212).
 *
 * Replaces libc sleep/usleep LLVM lookup in ext/standard/JitSleep.php.
 * SSOT: {@see \PHPCompiler\ext\standard\VmSleepPure}.
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(sleep), usleep
 */
final class MathSleep
{
    private const ABI_SLEEP = 'phpc_sleep';

    private const ABI_USLEEP = 'phpc_usleep';

    private const HELPER_PATH = '/ext/standard/SleepJitHelper.php';

    private const SLEEP_HELPER = 'PHPCompiler\\ext\\standard\\SleepJitHelper::sleepArgv';

    private const USLEEP_HELPER = 'PHPCompiler\\ext\\standard\\SleepJitHelper::usleepArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::SLEEP_HELPER,
        self::USLEEP_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implementSleep($context);
        self::implementUsleep($context);
    }

    public static function invokeSleep(Context $context, Value $seconds): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_SLEEP),
            $seconds
        );
    }

    public static function invokeUsleep(Context $context, Value $microseconds): void
    {
        self::ensureLinked($context);
        $context->builder->call(
            $context->lookupFunction(self::ABI_USLEEP),
            $microseconds
        );
    }

    private static function implementSleep(Context $context): void
    {
        $i64 = $context->getTypeFromString('int64');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_SLEEP,
            'sleep_bridge_entry',
            [$i64],
            $i64,
            self::SLEEP_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#15212'
        );
    }

    private static function implementUsleep(Context $context): void
    {
        $i64 = $context->getTypeFromString('int64');
        $void = $context->getTypeFromString('void');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_USLEEP,
            'usleep_bridge_entry',
            [$i64],
            $void,
            self::USLEEP_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#15212'
        );
    }
}
