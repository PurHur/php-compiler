<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringGmmktime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for gmmktime() via StringGmmktime (__compiler_gmmktime, #7001). */
final class JitGmmktime
{
    public static function invoke(
        Context $context,
        JITVariable $hour,
        ?JITVariable $minute,
        ?JITVariable $second,
        ?JITVariable $month,
        ?JITVariable $day,
        ?JITVariable $year,
        int $argc
    ): Value {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);

        // Thin AOT: NestedJIT GmmktimeJitHelper returns an empty box (#27159).
        // Fold compile-time int sextuples via host/VmDatePure at compile time.
        $folded = self::tryFoldCompileTime($hour, $minute, $second, $month, $day, $year, $argc);
        if (null !== $folded) {
            if (false === $folded) {
                $context->builder->call(
                    $context->lookupFunction('__value__writeBool'),
                    $ptr,
                    $context->getTypeFromString('int32')->constInt(0, false)
                );
            } else {
                $i64 = $context->getTypeFromString('int64');
                $context->builder->call(
                    $context->lookupFunction('__value__writeLong'),
                    $ptr,
                    $i64->constInt((int) $folded, false)
                );
            }

            return $ptr;
        }

        StringGmmktime::ensureLinked($context);
        $context->builder->call(
            $context->lookupFunction('__compiler_gmmktime'),
            self::jitIntArg($context, $hour, 1),
            self::jitOptionalIntArg($context, $minute, 2, $argc >= 2),
            self::jitOptionalIntArg($context, $second, 3, $argc >= 3),
            self::jitOptionalIntArg($context, $month, 4, $argc >= 4),
            self::jitOptionalIntArg($context, $day, 5, $argc >= 5),
            self::jitOptionalIntArg($context, $year, 6, $argc >= 6),
            $context->constantFromBool($argc < 2 || self::isNullJitArg($minute)),
            $ptr
        );

        return $ptr;
    }

    /**
     * @return int|false|null null = not foldable at compile time
     */
    private static function tryFoldCompileTime(
        JITVariable $hour,
        ?JITVariable $minute,
        ?JITVariable $second,
        ?JITVariable $month,
        ?JITVariable $day,
        ?JITVariable $year,
        int $argc
    ): int|false|null {
        if ($argc < 6) {
            // Partial arity uses "current UTC" wall-clock — not foldable.
            return null;
        }
        $h = self::compileTimeInt($hour);
        $i = self::compileTimeInt($minute);
        $s = self::compileTimeInt($second);
        $m = self::compileTimeInt($month);
        $d = self::compileTimeInt($day);
        $y = self::compileTimeInt($year);
        if (null === $h || null === $i || null === $s || null === $m || null === $d || null === $y) {
            return null;
        }

        return VmDatePure::gmmktime($h, $i, $s, $m, $d, $y);
    }

    private static function compileTimeInt(?JITVariable $arg): ?int
    {
        if (null === $arg) {
            return null;
        }
        if (null !== $arg->compileTimeLong) {
            return (int) $arg->compileTimeLong;
        }
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type) {
            try {
                return (int) $arg->value->getConstantValue();
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private static function jitIntArg(Context $context, JITVariable $arg, int $position): Value
    {
        $name = match ($position) {
            1 => 'hour',
            2 => 'minute',
            3 => 'second',
            4 => 'month',
            5 => 'day',
            6 => 'year',
            default => 'arg',
        };
        // Z_PARAM_LONG $hour — soft-null DEP+coerce on 8.4 (#21491, reverts #20227 TypeError).
        if (JITVariable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false)) {
            return JitChr::lowerZParamLongArg($context, $arg, 'gmmktime', $position, $name);
        }
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return JitChr::lowerZParamLongArg($context, $arg, 'gmmktime', $position, $name);
        }

        throw new \LogicException('gmmktime() argument #'.$position.' must be an integer in this compiler build');
    }

    private static function jitOptionalIntArg(
        Context $context,
        ?JITVariable $arg,
        int $position,
        bool $passed
    ): Value {
        $i64 = $context->getTypeFromString('int64');
        if (!$passed || null === $arg || self::isNullJitArg($arg)) {
            return $i64->constInt(MktimeJitHelper::ARG_NULL, false);
        }
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readLong'),
                $arg->value
            );
        }

        throw new \LogicException('gmmktime() argument #'.$position.' must be an integer or null in this compiler build');
    }

    private static function isNullJitArg(?JITVariable $arg): bool
    {
        return null === $arg || JITVariable::TYPE_NULL === $arg->type;
    }
}
