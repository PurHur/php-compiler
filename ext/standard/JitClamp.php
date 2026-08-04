<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\MathClamp;
use PHPCompiler\JIT\Builtin\MathIsNan;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * clamp() JIT/AOT lowering (ext/standard/math.c php_math_clamp).
 *
 * SSOT: {@see VmMath::clamp()}
 */
final class JitClamp
{
    private static int $seq = 0;

    public static function invoke(
        Context $context,
        JITVariable $value,
        JITVariable $min,
        JITVariable $max
    ): Value {
        if (self::allNativeLong($value, $min, $max)) {
            return self::clampNativeLong($context, $value, $min, $max);
        }
        if (self::allNativeNumeric($value, $min, $max)) {
            return self::clampNativeDouble($context, $value, $min, $max);
        }

        $valuePtr = JitValueBox::normalizeValuePtr(
            $context,
            JitValueBox::valuePtrFromVariable($context, $value)
        );
        $minPtr = JitValueBox::normalizeValuePtr(
            $context,
            JitValueBox::valuePtrFromVariable($context, $min)
        );
        $maxPtr = JitValueBox::normalizeValuePtr(
            $context,
            JitValueBox::valuePtrFromVariable($context, $max)
        );

        return MathClamp::invoke($context, $valuePtr, $minPtr, $maxPtr);
    }

    private static function allNativeLong(JITVariable $value, JITVariable $min, JITVariable $max): bool
    {
        return JITVariable::TYPE_NATIVE_LONG === $value->type
            && JITVariable::TYPE_NATIVE_LONG === $min->type
            && JITVariable::TYPE_NATIVE_LONG === $max->type;
    }

    private static function allNativeNumeric(JITVariable $value, JITVariable $min, JITVariable $max): bool
    {
        foreach ([$value, $min, $max] as $arg) {
            if (JITVariable::TYPE_NATIVE_LONG !== $arg->type
                && JITVariable::TYPE_NATIVE_DOUBLE !== $arg->type) {
                return false;
            }
        }

        return true;
    }

    private static function clampNativeLong(
        Context $context,
        JITVariable $value,
        JITVariable $min,
        JITVariable $max
    ): Value {
        $val = $context->helper->loadValue($value);
        $lo = $context->helper->loadValue($min);
        $hi = $context->helper->loadValue($max);

        self::rejectOrderedLongBounds($context, $lo, $hi);

        $aboveMax = $context->builder->icmp(Builder::INT_SGT, $val, $hi);
        $belowMin = $context->builder->icmp(Builder::INT_SLT, $val, $lo);
        $picked = $context->builder->select($aboveMax, $hi, $val);

        return $context->builder->select($belowMin, $lo, $picked);
    }

    private static function clampNativeDouble(
        Context $context,
        JITVariable $value,
        JITVariable $min,
        JITVariable $max
    ): Value {
        $double = $context->getTypeFromString('double');
        $val = self::toJitDouble($context, $value, $double);
        $lo = self::toJitDouble($context, $min, $double);
        $hi = self::toJitDouble($context, $max, $double);

        self::rejectNanDoubleBound($context, $lo, 2, '$min');
        self::rejectNanDoubleBound($context, $hi, 3, '$max');
        self::rejectOrderedDoubleBounds($context, $lo, $hi);

        $aboveMax = $context->builder->fcmp(Builder::REAL_OGT, $val, $hi);
        $belowMin = $context->builder->fcmp(Builder::REAL_OLT, $val, $lo);
        $picked = $context->builder->select($aboveMax, $hi, $val);
        $picked = $context->builder->select($belowMin, $lo, $picked);

        if (JITVariable::TYPE_NATIVE_LONG === $value->type
            && JITVariable::TYPE_NATIVE_LONG === $min->type
            && JITVariable::TYPE_NATIVE_LONG === $max->type) {
            return $context->builder->fptosi($picked, $context->getTypeFromString('int64'));
        }

        return $picked;
    }

    private static function toJitDouble(Context $context, JITVariable $arg, $double): Value
    {
        if (JITVariable::TYPE_NATIVE_DOUBLE === $arg->type) {
            return $context->helper->loadValue($arg);
        }

        return $context->builder->sitofp($context->helper->loadValue($arg), $double);
    }

    private static function rejectOrderedLongBounds(Context $context, Value $lo, Value $hi): void
    {
        $ok = $context->builder->icmp(Builder::INT_SLE, $lo, $hi);
        TypeErrorRaise::emitBranchOrAbortOnValueErrorFailure(
            $context,
            $ok,
            'clamp_min_gt_max_'.(++self::$seq),
            'clamp(): Argument #2 ($min) must be smaller than or equal to argument #3 ($max)'
        );
    }

    private static function rejectOrderedDoubleBounds(Context $context, Value $lo, Value $hi): void
    {
        $ok = $context->builder->fcmp(Builder::REAL_OLE, $lo, $hi);
        TypeErrorRaise::emitBranchOrAbortOnValueErrorFailure(
            $context,
            $ok,
            'clamp_min_gt_max_d_'.(++self::$seq),
            'clamp(): Argument #2 ($min) must be smaller than or equal to argument #3 ($max)'
        );
    }

    private static function rejectNanDoubleBound(
        Context $context,
        Value $bound,
        int $argNum,
        string $paramName
    ): void {
        $isNan = MathIsNan::invoke($context, $bound);
        $ok = $context->builder->not($isNan);
        TypeErrorRaise::emitBranchOrAbortOnValueErrorFailure(
            $context,
            $ok,
            'clamp_nan_'.(++self::$seq),
            'clamp(): Argument #'.$argNum.' ('.$paramName.') must not be NAN'
        );
    }
}
