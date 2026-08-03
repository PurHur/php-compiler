<?php

declare(strict_types=1);

namespace PHPCompiler\ext\calendar;

use PHPCompiler\ext\standard\JitChr;
use PHPCompiler\ext\standard\JitIntdiv;
use PHPCompiler\JIT\Builtin\GregoriantojdRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for gregoriantojd() via GregoriantojdRuntime (#27386). */
final class JitGregoriantojd
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (3 !== \count($args)) {
            throw new \ArgumentCountError(
                'gregoriantojd() expects exactly 3 arguments, '.\count($args).' given'
            );
        }

        $folded = self::tryFoldCompileTime($context, $args);
        if (null !== $folded) {
            return $folded;
        }

        $month = JitChr::lowerZParamLongArg($context, $args[0], 'gregoriantojd', 1, 'month');
        $day = JitChr::lowerZParamLongArg($context, $args[1], 'gregoriantojd', 2, 'day');
        $year = JitChr::lowerZParamLongArg($context, $args[2], 'gregoriantojd', 3, 'year');

        return GregoriantojdRuntime::invoke($context, $month, $day, $year);
    }

    /**
     * @param list<JITVariable> $args
     */
    private static function tryFoldCompileTime(Context $context, array $args): ?Value
    {
        $month = self::compileTimeLongArg($context, $args[0], 1, 'month');
        if (null === $month) {
            return null;
        }
        $day = self::compileTimeLongArg($context, $args[1], 2, 'day');
        if (null === $day) {
            return null;
        }
        $year = self::compileTimeLongArg($context, $args[2], 3, 'year');
        if (null === $year) {
            return null;
        }

        $jd = GregoriantojdJitHelper::gregoriantojdArgv($month, $day, $year);

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
            JitIntdiv::emitNullIntDeprecation($context, 'gregoriantojd', $userArgIndex, $paramName);

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
