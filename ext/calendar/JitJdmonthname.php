<?php

declare(strict_types=1);

namespace PHPCompiler\ext\calendar;

use PHPCompiler\ext\standard\JitChr;
use PHPCompiler\ext\standard\JitIntdiv;
use PHPCompiler\JIT\Builtin\JdmonthnameRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for jdmonthname() via JdmonthnameRuntime (#27360). */
final class JitJdmonthname
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \ArgumentCountError(
                'jdmonthname() expects exactly 2 arguments, '.\count($args).' given'
            );
        }

        $folded = self::tryFoldCompileTime($context, $args);
        if (null !== $folded) {
            return $folded;
        }

        $julday = JitChr::lowerZParamLongArg($context, $args[0], 'jdmonthname', 1, 'julian_day');
        $mode = JitChr::lowerZParamLongArg($context, $args[1], 'jdmonthname', 2, 'mode');

        return JdmonthnameRuntime::invoke($context, $julday, $mode);
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
        $mode = self::compileTimeLongArg($context, $args[1], 2, 'mode');
        if (null === $mode) {
            return null;
        }

        $result = JdmonthnameJitHelper::jdmonthnameArgv($julday, $mode);

        return $context->builder->load($context->constantStringFromString($result));
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
            JitIntdiv::emitNullIntDeprecation($context, 'jdmonthname', $userArgIndex, $paramName);

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
