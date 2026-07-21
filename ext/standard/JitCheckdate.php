<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\CheckdateRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for checkdate() via CheckdateRuntime (#3292). */
final class JitCheckdate
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (3 !== \count($args)) {
            throw new \LogicException('checkdate() expects exactly 3 arguments in this compiler build');
        }

        // Compile-time fold for literal args — keeps AOT green when nested helper
        // bridge is unused (#21594 soft-null; fixture #3292).
        $folded = self::tryFoldCompileTime($context, $args);
        if (null !== $folded) {
            return $folded;
        }

        CheckdateRuntime::ensureLinked($context);

        // Z_PARAM_LONG — soft-null DEP+coerce on 8.4 (#21594, peer mktime/chr).
        $month = JitChr::lowerZParamLongArg($context, $args[0], 'checkdate', 1, 'month');
        $day = JitChr::lowerZParamLongArg($context, $args[1], 'checkdate', 2, 'day');
        $year = JitChr::lowerZParamLongArg($context, $args[2], 'checkdate', 3, 'year');

        $valid = $context->builder->call(
            $context->lookupFunction('__compiler_checkdate'),
            $month,
            $day,
            $year
        );

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        JitValueBox::writeBool($context, $slot, $valid);

        return $ptr;
    }

    /**
     * @param list<JITVariable> $args
     */
    private static function tryFoldCompileTime(Context $context, array $args): ?Value
    {
        $month = self::compileTimeLongArg($context, $args[0], 'checkdate', 1, 'month');
        if (null === $month) {
            return null;
        }
        $day = self::compileTimeLongArg($context, $args[1], 'checkdate', 2, 'day');
        if (null === $day) {
            return null;
        }
        $year = self::compileTimeLongArg($context, $args[2], 'checkdate', 3, 'year');
        if (null === $year) {
            return null;
        }

        $valid = VmDate::checkdate($month, $day, $year);

        // Native bool like class_exists AOT ABI — value-box write is JIT-shaped (#21594).
        return $context->constantFromBool($valid);
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
            // Soft-null DEP+coerce → 0 (#21594).
            JitIntdiv::emitNullIntDeprecation($context, $function, $userArgIndex, $paramName);

            return 0;
        }
        if (null !== $arg->compileTimeLong) {
            return $arg->compileTimeLong;
        }

        return null;
    }

    private static function isCompileTimeNull(JITVariable $arg): bool
    {
        return JITVariable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false);
    }
}
