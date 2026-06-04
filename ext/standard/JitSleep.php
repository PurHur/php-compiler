<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringTimeSleep;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for sleep()/usleep()/time_nanosleep()/time_sleep_until(). */
final class JitSleep
{
    public static function sleep(Context $context, JITVariable $arg): Value
    {
        $seconds = JitLongArg::lower($context, $arg, 'sleep() seconds');
        $i32 = $context->getTypeFromString('int32');
        $secs = $context->builder->trunc($seconds, $i32);
        $remaining = $context->builder->call($context->lookupFunction('sleep'), $secs);
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->zExt($remaining, $i64);
    }

    public static function usleep(Context $context, JITVariable $arg): Value
    {
        $microseconds = JitLongArg::lower($context, $arg, 'usleep() microseconds');
        $i32 = $context->getTypeFromString('int32');
        $usec = $context->builder->trunc($microseconds, $i32);
        $context->builder->call($context->lookupFunction('usleep'), $usec);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);

        return $ptr;
    }

    public static function timeNanosleep(Context $context, Value $seconds, Value $nanoseconds): Value
    {
        StringTimeSleep::ensureLinked($context);

        $ok = $context->builder->call(
            $context->lookupFunction('__compiler_time_nanosleep'),
            $seconds,
            $nanoseconds
        );
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $i32 = $context->getTypeFromString('int32');
        $isTrue = $context->builder->icmp(Builder::INT_NE, $ok, $i32->constInt(0, false));
        JitValueBox::writeBool($context, $slot, $isTrue);

        return $ptr;
    }

    public static function timeSleepUntil(Context $context, JITVariable $arg): Value
    {
        StringTimeSleep::ensureLinked($context);

        $target = self::lowerDouble($context, $arg, 'time_sleep_until() timestamp');
        $ok = $context->builder->call(
            $context->lookupFunction('__compiler_time_sleep_until'),
            $target
        );
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $i32 = $context->getTypeFromString('int32');
        $isTrue = $context->builder->icmp(Builder::INT_NE, $ok, $i32->constInt(0, false));
        JitValueBox::writeBool($context, $slot, $isTrue);

        return $ptr;
    }

    private static function lowerDouble(Context $context, JITVariable $arg, string $label): Value
    {
        if (JITVariable::TYPE_NATIVE_DOUBLE === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readDouble'),
                $context->helper->loadValue($arg)
            );
        }
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type) {
            $i64 = $context->getTypeFromString('int64');
            $longVal = $context->helper->loadValue($arg);
            $doubleType = $context->getTypeFromString('double');

            return $context->builder->sitofp($longVal, $doubleType);
        }

        throw new \LogicException($label.' must be float or int in this compiler build');
    }
}
