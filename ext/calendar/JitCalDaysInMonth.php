<?php

declare(strict_types=1);

namespace PHPCompiler\ext\calendar;

use PHPCompiler\ext\standard\JitChr;
use PHPCompiler\ext\standard\JitIntdiv;
use PHPCompiler\JIT\Builtin\CalDaysInMonthRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for cal_days_in_month() via CalDaysInMonthRuntime (#27310). */
final class JitCalDaysInMonth
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (3 !== \count($args)) {
            throw new \LogicException(
                'cal_days_in_month() expects exactly 3 arguments in this compiler build'
            );
        }

        $folded = self::tryFoldCompileTime($context, $args);
        if (null !== $folded) {
            return $folded;
        }

        $calendar = JitChr::lowerZParamLongArg($context, $args[0], 'cal_days_in_month', 1, 'calendar');
        $month = JitChr::lowerZParamLongArg($context, $args[1], 'cal_days_in_month', 2, 'month');
        $year = JitChr::lowerZParamLongArg($context, $args[2], 'cal_days_in_month', 3, 'year');

        return CalDaysInMonthRuntime::invoke($context, $calendar, $month, $year);
    }

    /**
     * @param list<JITVariable> $args
     */
    private static function tryFoldCompileTime(Context $context, array $args): ?Value
    {
        $calendar = self::compileTimeLongArg($context, $args[0], 'cal_days_in_month', 1, 'calendar');
        if (null === $calendar) {
            return null;
        }
        $month = self::compileTimeLongArg($context, $args[1], 'cal_days_in_month', 2, 'month');
        if (null === $month) {
            return null;
        }
        $year = self::compileTimeLongArg($context, $args[2], 'cal_days_in_month', 3, 'year');
        if (null === $year) {
            return null;
        }

        $days = CalDaysInMonthJitHelper::calDaysInMonthArgv($calendar, $month, $year);

        return $context->constantFromInteger($days, 'int64');
    }

    private static function compileTimeLongArg(
        Context $context,
        JITVariable $arg,
        string $function,
        int $userArgIndex,
        string $paramName
    ): ?int {
        if (self::isCompileTimeNull($arg)) {
            if ($context->callerStrictTypes) {
                return null;
            }
            // Soft-null DEP+coerce → 0 (Z_PARAM_LONG; #24967).
            JitIntdiv::emitNullIntDeprecation($context, $function, $userArgIndex, $paramName);

            return 0;
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

    private static function isCompileTimeNull(JITVariable $arg): bool
    {
        return JITVariable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false);
    }
}
