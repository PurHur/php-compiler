<?php

declare(strict_types=1);

namespace PHPCompiler\ext\calendar;

use PHPCompiler\ext\standard\JitChr;
use PHPCompiler\ext\standard\JitIntdiv;
use PHPCompiler\JIT\Builtin\EasterDaysRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for easter_days() via EasterDaysRuntime (#27358). */
final class JitEasterDays
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc > 2) {
            throw new \ArgumentCountError(
                'easter_days() accepts at most 2 arguments, '.$argc.' given'
            );
        }

        $folded = self::tryFoldCompileTime($context, $args);
        if (null !== $folded) {
            return $folded;
        }

        $i64 = $context->getTypeFromString('int64');
        $defaultMode = $i64->constInt(CalendarConstants::CAL_EASTER_DEFAULT, false);

        if (0 === $argc || self::isCompileTimeNull($args[0])) {
            $mode = (0 === $argc || 1 === $argc)
                ? $defaultMode
                : JitChr::lowerZParamLongArg($context, $args[1], 'easter_days', 2, 'mode');

            return EasterDaysRuntime::invokeCurrentYear($context, $mode);
        }

        $year = JitChr::lowerZParamLongArg($context, $args[0], 'easter_days', 1, 'year');
        $mode = $argc < 2
            ? $defaultMode
            : JitChr::lowerZParamLongArg($context, $args[1], 'easter_days', 2, 'mode');

        return EasterDaysRuntime::invoke($context, $year, $mode);
    }

    /**
     * @param list<JITVariable> $args
     */
    private static function tryFoldCompileTime(Context $context, array $args): ?Value
    {
        $argc = \count($args);
        if (0 === $argc || self::isCompileTimeNull($args[0] ?? null)) {
            return null;
        }
        $year = self::compileTimeLongArg($context, $args[0], 'easter_days', 1, 'year');
        if (null === $year) {
            return null;
        }
        $mode = CalendarConstants::CAL_EASTER_DEFAULT;
        if ($argc >= 2) {
            $modeOpt = self::compileTimeLongArg($context, $args[1], 'easter_days', 2, 'mode');
            if (null === $modeOpt) {
                return null;
            }
            $mode = $modeOpt;
        }

        $days = EasterDaysJitHelper::easterDaysArgv($year, $mode);

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

    private static function isCompileTimeNull(?JITVariable $arg): bool
    {
        if (null === $arg) {
            return true;
        }

        return JITVariable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false);
    }
}
