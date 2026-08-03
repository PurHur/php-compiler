<?php

declare(strict_types=1);

namespace PHPCompiler\ext\calendar;

use PHPCompiler\ext\standard\JitChr;
use PHPCompiler\ext\standard\JitIntdiv;
use PHPCompiler\JIT\Builtin\CalToJdRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for cal_to_jd() via CalToJdRuntime (#27366). */
final class JitCalToJd
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (4 !== \count($args)) {
            throw new \ArgumentCountError(
                'cal_to_jd() expects exactly 4 arguments, '.\count($args).' given'
            );
        }

        $folded = self::tryFoldCompileTime($context, $args);
        if (null !== $folded) {
            return $folded;
        }

        $calendar = JitChr::lowerZParamLongArg($context, $args[0], 'cal_to_jd', 1, 'calendar');
        $month = JitChr::lowerZParamLongArg($context, $args[1], 'cal_to_jd', 2, 'month');
        $day = JitChr::lowerZParamLongArg($context, $args[2], 'cal_to_jd', 3, 'day');
        $year = JitChr::lowerZParamLongArg($context, $args[3], 'cal_to_jd', 4, 'year');

        return CalToJdRuntime::invoke($context, $calendar, $month, $day, $year);
    }

    /**
     * @param list<JITVariable> $args
     */
    private static function tryFoldCompileTime(Context $context, array $args): ?Value
    {
        $calendar = self::compileTimeLongArg($context, $args[0], 1, 'calendar');
        if (null === $calendar) {
            return null;
        }
        $month = self::compileTimeLongArg($context, $args[1], 2, 'month');
        if (null === $month) {
            return null;
        }
        $day = self::compileTimeLongArg($context, $args[2], 3, 'day');
        if (null === $day) {
            return null;
        }
        $year = self::compileTimeLongArg($context, $args[3], 4, 'year');
        if (null === $year) {
            return null;
        }

        $jd = CalToJdJitHelper::calToJdArgv($calendar, $month, $day, $year);

        return $context->constantFromInteger($jd, 'int64');
    }

    private static function compileTimeLongArg(
        Context $context,
        JITVariable $arg,
        int $userArgIndex,
        string $paramName
    ): ?int {
        if (self::isCompileTimeNull($arg)) {
            if ($context->callerStrictTypes) {
                return null;
            }
            JitIntdiv::emitNullIntDeprecation($context, 'cal_to_jd', $userArgIndex, $paramName);

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
