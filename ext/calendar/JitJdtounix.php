<?php

declare(strict_types=1);

namespace PHPCompiler\ext\calendar;

use PHPCompiler\ext\standard\JitChr;
use PHPCompiler\ext\standard\JitIntdiv;
use PHPCompiler\JIT\Builtin\JdtounixRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for jdtounix() via JdtounixRuntime (#27387). */
final class JitJdtounix
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \ArgumentCountError(
                'jdtounix() expects exactly 1 argument, '.\count($args).' given'
            );
        }

        $folded = self::tryFoldCompileTime($context, $args[0]);
        if (null !== $folded) {
            return $folded;
        }

        $julday = JitChr::lowerZParamLongArg($context, $args[0], 'jdtounix', 1, 'julian_day');

        return JdtounixRuntime::invoke($context, $julday);
    }

    private static function tryFoldCompileTime(Context $context, JITVariable $arg): ?Value
    {
        $julday = self::compileTimeLongArg($context, $arg);
        if (null === $julday) {
            return null;
        }

        try {
            $result = JdtounixJitHelper::jdtounixArgv($julday);
        } catch (\ValueError) {
            // Out-of-range JD: emit NestedJIT call so ValueError fires at run time.
            return null;
        }

        return $context->constantFromInteger($result, 'int64');
    }

    private static function compileTimeLongArg(Context $context, JITVariable $arg): ?int
    {
        if (self::isCompileTimeNull($arg)) {
            if ($context->callerStrictTypes) {
                return null;
            }
            JitIntdiv::emitNullIntDeprecation($context, 'jdtounix', 1, 'julian_day');

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
