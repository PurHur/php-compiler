<?php

declare(strict_types=1);

namespace PHPCompiler\ext\calendar;

use PHPCompiler\ext\standard\JitChr;
use PHPCompiler\ext\standard\JitIntdiv;
use PHPCompiler\JIT\Builtin\JdtojewishRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for jdtojewish() via JdtojewishRuntime (#27368). */
final class JitJdtojewish
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 3) {
            throw new \ArgumentCountError(
                'jdtojewish() expects at most 3 arguments, '.$argc.' given'
            );
        }

        $folded = self::tryFoldCompileTime($context, $args);
        if (null !== $folded) {
            return $folded;
        }

        $julday = JitChr::lowerZParamLongArg($context, $args[0], 'jdtojewish', 1, 'julian_day');
        $i64 = $context->getTypeFromString('int64');
        $modeConst = self::resolveModeCompileTime($context, $args);
        // Issue #27368 repro is 1-arg numeric form (mode 0). Optional hebrew/flags fold when constant.
        $mode = $i64->constInt($modeConst ?? 0, false);

        return JdtojewishRuntime::invoke($context, $julday, $mode);
    }

    /**
     * @param list<JITVariable> $args
     */
    private static function tryFoldCompileTime(Context $context, array $args): ?Value
    {
        $julday = self::compileTimeLongArg($context, $args[0], 1, 'julian_day');
        if (null === $julday) {
            return null;
        }
        $mode = self::resolveModeCompileTime($context, $args);
        if (null === $mode) {
            return null;
        }

        $result = JdtojewishJitHelper::jdtojewishArgv($julday, $mode);

        return $context->builder->load($context->constantStringFromString($result));
    }

    /**
     * @param list<JITVariable> $args
     */
    private static function resolveModeCompileTime(Context $context, array $args): ?int
    {
        $argc = \count($args);
        if ($argc < 2) {
            return 0;
        }
        $hebrew = self::compileTimeBoolArg($context, $args[1], 2, 'hebrew');
        if (null === $hebrew) {
            return null;
        }
        if (!$hebrew) {
            return 0;
        }
        if ($argc < 3) {
            return 0;
        }
        $flags = self::compileTimeLongArg($context, $args[2], 3, 'flags');
        if (null === $flags) {
            return null;
        }

        return $flags;
    }

    private static function compileTimeBoolArg(
        Context $context,
        JITVariable $arg,
        int $userArgIndex,
        string $paramName
    ): ?bool {
        if (self::isCompileTimeNull($arg)) {
            if ($context->callerStrictTypes) {
                return null;
            }
            JitIntdiv::emitNullIntDeprecation($context, 'jdtojewish', $userArgIndex, $paramName);

            return false;
        }
        if (JITVariable::TYPE_NATIVE_BOOL === $arg->type && JITVariable::KIND_VALUE === $arg->kind) {
            try {
                return (bool) $arg->value->getConstantValue();
            } catch (\Throwable) {
                return null;
            }
        }
        if (null !== $arg->compileTimeLong) {
            return 0 !== $arg->compileTimeLong;
        }
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type && JITVariable::KIND_VALUE === $arg->kind) {
            try {
                return 0 !== (int) $arg->value->getConstantValue();
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
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
            JitIntdiv::emitNullIntDeprecation($context, 'jdtojewish', $userArgIndex, $paramName);

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
