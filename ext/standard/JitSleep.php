<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for sleep()/usleep() via libc sleep(3) and usleep(3). */
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
}
