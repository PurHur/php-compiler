<?php

declare(strict_types=1);

namespace PHPCompiler\ext\calendar;

use PHPCompiler\ext\standard\JitChr;
use PHPCompiler\ext\standard\JitIntdiv;
use PHPCompiler\JIT\Builtin\JdtojulianRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for jdtojulian() via JdtojulianRuntime (#27388). */
final class JitJdtojulian
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \ArgumentCountError(
                'jdtojulian() expects exactly 1 argument, '.\count($args).' given'
            );
        }

        $folded = self::tryFoldCompileTime($context, $args[0]);
        if (null !== $folded) {
            return $folded;
        }

        $julday = JitChr::lowerZParamLongArg($context, $args[0], 'jdtojulian', 1, 'julian_day');

        return JdtojulianRuntime::invoke($context, $julday);
    }

    private static function tryFoldCompileTime(Context $context, JITVariable $arg): ?Value
    {
        $julday = self::compileTimeLongArg($context, $arg);
        if (null === $julday) {
            return null;
        }

        $result = JdtojulianJitHelper::jdtojulianArgv($julday);

        return $context->builder->load($context->constantStringFromString($result));
    }

    private static function compileTimeLongArg(Context $context, JITVariable $arg): ?int
    {
        if (self::isCompileTimeNull($arg)) {
            if ($context->callerStrictTypes) {
                return null;
            }
            JitIntdiv::emitNullIntDeprecation($context, 'jdtojulian', 1, 'julian_day');

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
