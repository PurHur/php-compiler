<?php

declare(strict_types=1);

namespace PHPCompiler\ext\calendar;

use PHPCompiler\ext\standard\JitChr;
use PHPCompiler\ext\standard\JitIntdiv;
use PHPCompiler\JIT\Builtin\CalFromJdRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for cal_from_jd() via CalFromJdRuntime (#27359). */
final class JitCalFromJd
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \ArgumentCountError(
                'cal_from_jd() expects exactly 2 arguments, '.\count($args).' given'
            );
        }

        $jd = self::compileTimeLongArg($context, $args[0], 1, 'julian_day');
        $cal = self::compileTimeLongArg($context, $args[1], 2, 'calendar');
        if (null !== $jd && null !== $cal) {
            return CalFromJdRuntime::emit($context, $jd, $cal);
        }

        $jdVal = JitChr::lowerZParamLongArg($context, $args[0], 'cal_from_jd', 1, 'julian_day');
        $calVal = JitChr::lowerZParamLongArg($context, $args[1], 'cal_from_jd', 2, 'calendar');

        return CalFromJdRuntime::invoke($context, $jdVal, $calVal);
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
            JitIntdiv::emitNullIntDeprecation($context, 'cal_from_jd', $userArgIndex, $paramName);

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
