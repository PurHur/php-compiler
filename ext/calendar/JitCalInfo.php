<?php

declare(strict_types=1);

namespace PHPCompiler\ext\calendar;

use PHPCompiler\ext\standard\JitChr;
use PHPCompiler\JIT\Builtin\CalInfoRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for cal_info() via CalInfoRuntime (#27354). */
final class JitCalInfo
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc > 1) {
            throw new \ArgumentCountError(
                'cal_info() accepts at most 1 argument, '.$argc.' given'
            );
        }

        if (0 === $argc) {
            return CalInfoRuntime::emitAll($context);
        }

        $folded = self::compileTimeLongArg($args[0]);
        if (null !== $folded) {
            // php-src calendar.c — -1 all-calendars sentinel (#28907)
            if (-1 === $folded) {
                return CalInfoRuntime::emitAll($context);
            }

            return CalInfoRuntime::emitOne($context, $folded);
        }

        $calendar = JitChr::lowerZParamLongArg($context, $args[0], 'cal_info', 1, 'calendar');

        return CalInfoRuntime::invoke($context, $calendar);
    }

    private static function compileTimeLongArg(JITVariable $arg): ?int
    {
        if (JITVariable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false)) {
            return null;
        }
        if (null !== $arg->compileTimeLong) {
            return $arg->compileTimeLong;
        }
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type && JITVariable::KIND_VALUE === $arg->kind) {
            try {
                return (int) $arg->value->getConstantValue();
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }
}
