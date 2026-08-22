<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\DefaultTimezoneCivilRuntime;
use PHPCompiler\JIT\Builtin\StringMktime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for mktime() via StringMktime (__compiler_mktime, #3292). */
final class JitMktime
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

        // Thin AOT: NestedJIT MktimeJitHelper lastTimestamp can stay 0 (#33934).
        // Fold compile-time int sextuples via host/VmDatePure (peer gmmktime #27159).
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

        // Full arity runtime: civil IR + default-TZ offset (skip NestedJIT static, #33934).
        if ($argc >= 6 && null !== $minute && null !== $second && null !== $month && null !== $day && null !== $year) {
            self::writeLocalCivilTimestamp(
                $context,
                $ptr,
                self::jitIntArg($context, $hour, 1),
                self::jitIntArg($context, $minute, 2),
                self::jitIntArg($context, $second, 3),
                self::jitIntArg($context, $month, 4),
                self::jitIntArg($context, $day, 5),
                self::jitIntArg($context, $year, 6)
            );

            return $ptr;
        }

        StringMktime::ensureLinked($context);
        $context->builder->call(
            $context->lookupFunction('__compiler_mktime'),
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
     * Local-wall civil → unix via UTC civil stamp minus default-TZ offset (#33934).
     * Matches {@see DefaultTimezoneCivilJitHelper::localCivilTimestamp} inverse.
     */
    private static function writeLocalCivilTimestamp(
        Context $context,
        Value $outPtr,
        Value $hour,
        Value $minute,
        Value $second,
        Value $month,
        Value $day,
        Value $year
    ): void {
        $asUtc = JitGetdate::timestampFromCivilPublic(
            $context,
            $year,
            $month,
            $day,
            $hour,
            $minute,
            $second
        );
        DefaultTimezoneCivilRuntime::ensureLinked($context);
        $civilShifted = $context->builder->call(
            $context->lookupFunction('__compiler_default_tz_civil_timestamp'),
            $asUtc
        );
        $offset = $context->builder->sub($civilShifted, $asUtc);
        $ts = $context->builder->sub($asUtc, $offset);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $outPtr,
            $ts
        );
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
            // Partial arity uses "current local" wall-clock — not foldable.
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

        return VmDatePure::mktime($h, $i, $s, $m, $d, $y);
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
            return JitChr::lowerZParamLongArg($context, $arg, 'mktime', $position, $name);
        }
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return JitChr::lowerZParamLongArg($context, $arg, 'mktime', $position, $name);
        }

        throw new \LogicException('mktime() argument #'.$position.' must be an integer in this compiler build');
    }

    private static function jitOptionalIntArg(
        Context $context,
        ?JITVariable $arg,
        int $position,
        bool $passed
    ): Value {
        $i64 = $context->getTypeFromString('int64');
        if (!$passed || null === $arg || self::isNullJitArg($arg)) {
            return $i64->constInt(\PHPCompiler\ext\standard\MktimeJitHelper::ARG_NULL, false);
        }
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            // Globals/slots are __value__** — must go through valuePtrFromVariable (#33934).
            return JitChr::lowerZParamLongArg($context, $arg, 'mktime', $position, match ($position) {
                2 => 'minute',
                3 => 'second',
                4 => 'month',
                5 => 'day',
                6 => 'year',
                default => 'arg',
            });
        }

        throw new \LogicException('mktime() argument #'.$position.' must be an integer or null in this compiler build');
    }

    private static function isNullJitArg(?JITVariable $arg): bool
    {
        return null === $arg || JITVariable::TYPE_NULL === $arg->type;
    }
}
