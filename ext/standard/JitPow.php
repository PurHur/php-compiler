<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\MathFpow;
use PHPCompiler\JIT\Builtin\PowIntRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitEnumNumericOperandGuard;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitPowNumericOperandGuard;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitValueNumeric;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for pow() int/float return (issue #3678, #35058). */
final class JitPow
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('pow() requires exactly two arguments');
        }

        // Zend pow_function and ** share the integer fast path (zend_operators.c).
        // TYPE_POW already set powReturnValueBox; pow() FUNCCALL must not skip it —
        // leftover float-only path made AOT var_dump(pow(2,3)) print float(8) (#33848 / #3678).
        return self::invokeBoxedIntAware($context, ...$args);
    }

    /**
     * pow() / ** — preserve int in the value box when both operands are long.
     */
    private static function invokeBoxedIntAware(Context $context, JITVariable ...$args): Value
    {
        JitPowNumericOperandGuard::guardOperands($context, $args[0], $args[1]);
        JitEnumNumericOperandGuard::guardPow($context, $args[0], $args[1]);
        $slot = JitValueBox::alloc($context);
        $slotPtr = JitValueBox::pointer($context, $slot);

        if (self::preferIntegerPowPath(...$args)) {
            $folded = self::tryFoldCompileTimeIntegerPow($context, $slot, $slotPtr, $args[0], $args[1]);
            if (null !== $folded) {
                return $folded;
            }
            PowIntRuntime::ensureLinked($context);
            $baseL = JitLongArg::lower($context, $args[0], 'pow() base');
            $expL = JitLongArg::lower($context, $args[1], 'pow() exponent');
            $context->builder->call(
                $context->lookupFunction('__phpc_pow_int'),
                $slotPtr,
                $baseL,
                $expL
            );

            return $slotPtr;
        }

        // Numeric strings and mixed boxed/long operands — zend_operators.c pow_function (#35344).
        if (self::preferLongCoercionPowPath(...$args)) {
            if (self::needsRuntimeLongFloatPowDispatch(...$args)) {
                return self::invokeRuntimeLongFloatPowDispatch($context, $slotPtr, $args[0], $args[1]);
            }
            PowIntRuntime::ensureLinked($context);
            $baseL = JitLongArg::lower($context, $args[0], 'pow() base');
            $expL = JitLongArg::lower($context, $args[1], 'pow() exponent');
            $context->builder->call(
                $context->lookupFunction('__phpc_pow_int'),
                $slotPtr,
                $baseL,
                $expL
            );

            return $slotPtr;
        }

        return self::writeLibcPowToSlot($context, $slotPtr, ...$args);
    }

    /**
     * Runtime long/float dispatch — boxed locals and integral numeric strings (#35058, #35344).
     */
    private static function invokeRuntimeLongFloatPowDispatch(
        Context $context,
        Value $slotPtr,
        JITVariable $base,
        JITVariable $exp
    ): Value {
        PowIntRuntime::ensureLinked($context);
        MathFpow::ensureLinked($context);

        $bothCoercible = $context->builder->and(
            self::emitOperandIntPowCoercible($context, $base),
            self::emitOperandIntPowCoercible($context, $exp)
        );
        $intBlock = BasicBlockHelper::append($context, 'pow_runtime_int');
        $floatBlock = BasicBlockHelper::append($context, 'pow_runtime_float');
        $done = BasicBlockHelper::append($context, 'pow_runtime_done');
        $context->builder->branchIf($bothCoercible, $intBlock, $floatBlock);

        $context->builder->positionAtEnd($intBlock);
        $baseL = JitLongArg::lower($context, $base, 'pow() base');
        $expL = JitLongArg::lower($context, $exp, 'pow() exponent');
        $context->builder->call(
            $context->lookupFunction('__phpc_pow_int'),
            $slotPtr,
            $baseL,
            $expL
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($floatBlock);
        $double = $context->getTypeFromString('double');
        $baseD = pow::toJitDouble($context, $base, $double);
        $expD = pow::toJitDouble($context, $exp, $double);
        $result = MathFpow::invoke($context, $baseD, $expD);
        $context->builder->call(
            $context->lookupFunction('__value__writeDouble'),
            $slotPtr,
            $result
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);

        return $slotPtr;
    }

    /**
     * True when operand is not a boxed double and (long | bool | null | integral numeric string).
     */
    private static function emitOperandIntPowCoercible(Context $context, JITVariable $arg): Value
    {
        if (!JitValueBox::isValueOperand($arg)) {
            if (JITVariable::TYPE_STRING === $arg->type) {
                $s = $arg->compileTimeString;

                return $context->getTypeFromString('int1')->constInt(
                    null !== $s && \PHPCompiler\VM\Variable::isIntegralNumericString($s) ? 1 : 0,
                    false
                );
            }

            return $context->getTypeFromString('int1')->constInt(
                self::operandIsFloatLike($arg) ? 0 : 1,
                false
            );
        }

        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $i1 = $context->getTypeFromString('int1');

        $isDouble = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(JITVariable::TYPE_NATIVE_DOUBLE, false)
        );
        $notCoercible = BasicBlockHelper::append($context, 'pow_coerc_false');
        $afterNotDouble = BasicBlockHelper::append($context, 'pow_coerc_after_not_double');
        $done = BasicBlockHelper::append($context, 'pow_coerc_done');
        $context->builder->branchIf($isDouble, $notCoercible, $afterNotDouble);

        $context->builder->positionAtEnd($notCoercible);
        $falseVal = $i1->constInt(0, false);
        $falseEnd = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterNotDouble);
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(JITVariable::TYPE_NATIVE_LONG, false)
        );
        $isBool = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(JITVariable::TYPE_NATIVE_BOOL, false)
        );
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(JITVariable::TYPE_NULL, false)
        );
        $longBoolNull = $context->builder->or(
            $isLong,
            $context->builder->or($isBool, $isNull)
        );
        $isString = JitValueNumeric::valueIsString($context, $arg);
        $stringBlock = BasicBlockHelper::append($context, 'pow_coerc_string');
        $afterString = BasicBlockHelper::append($context, 'pow_coerc_after_string');
        $context->builder->branchIf($isString, $stringBlock, $afterString);

        $context->builder->positionAtEnd($stringBlock);
        $strPtr = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $valuePtr
        );
        $stringCoerc = self::emitNativeStringIsIntegralNumeric($context, $strPtr);
        $stringEnd = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterString);
        $longBoolNullEnd = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $phi = $context->builder->phi($i1, 'pow_coerc_phi');
        $phi->addIncoming($falseVal, $falseEnd);
        $phi->addIncoming($stringCoerc, $stringEnd);
        $phi->addIncoming($longBoolNull, $longBoolNullEnd);

        return $phi;
    }

    /**
     * Zend _is_numeric_string_ex IS_LONG arm — strtod(3) equals (double)strtol(3) (#35344).
     */
    private static function emitNativeStringIsIntegralNumeric(Context $context, Value $strPtr): Value
    {
        $f64 = $context->getTypeFromString('double');
        $asDouble = JitLongArg::lowerStringToDouble($context, $strPtr);
        $asLong = JitLongArg::lowerStringValue($context, $strPtr);
        $longAsDouble = $context->builder->siToFp($asLong, $f64);

        return $context->builder->fcmp(Builder::REAL_OEQ, $asDouble, $longAsDouble);
    }

    private static function writeLibcPowToSlot(Context $context, Value $slotPtr, JITVariable ...$args): Value
    {
        JitPowNumericOperandGuard::guardOperands($context, $args[0], $args[1]);
        JitEnumNumericOperandGuard::guardPow($context, $args[0], $args[1]);
        $double = $context->getTypeFromString('double');
        $baseD = pow::toJitDouble($context, $args[0], $double);
        $expD = pow::toJitDouble($context, $args[1], $double);
        $result = MathFpow::invoke($context, $baseD, $expD);
        $context->builder->call(
            $context->lookupFunction('__value__writeDouble'),
            $slotPtr,
            $result
        );

        return $slotPtr;
    }

    /**
     * Both operands compile-time ints — Zend `**` at emit time (#31966).
     */
    private static function tryFoldCompileTimeIntegerPow(
        Context $context,
        Value $slot,
        Value $slotPtr,
        JITVariable $base,
        JITVariable $exp
    ): ?Value {
        if (null === $base->compileTimeLong || null === $exp->compileTimeLong) {
            return null;
        }
        if (null !== $base->compileTimeFloat || null !== $exp->compileTimeFloat) {
            return null;
        }
        $result = $base->compileTimeLong ** $exp->compileTimeLong;
        if (\is_int($result)) {
            JitValueBox::writeLong($context, $slot, $context->constantFromInteger($result));
        } else {
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $context->constantFromFloat((float) $result)
            );
        }

        return $slotPtr;
    }

    /**
     * Zend pow_function / ** operator: integer fast path only when both are known longs.
     * Boxed TYPE_VALUE may hold floats — do not truncate via JitLongArg (#35058).
     */
    private static function preferIntegerPowPath(JITVariable $base, JITVariable $exp): bool
    {
        if (JITVariable::TYPE_NATIVE_DOUBLE === $base->type
            || JITVariable::TYPE_NATIVE_DOUBLE === $exp->type
            || null !== $base->compileTimeFloat
            || null !== $exp->compileTimeFloat) {
            return false;
        }
        if (JITVariable::TYPE_OBJECT === $base->type
            || JITVariable::TYPE_OBJECT === $exp->type
            || JITVariable::TYPE_HASHTABLE === $base->type
            || JITVariable::TYPE_HASHTABLE === $exp->type) {
            return false;
        }
        if (JITVariable::TYPE_NATIVE_LONG === $base->type
            && JITVariable::TYPE_NATIVE_LONG === $exp->type) {
            return true;
        }
        if (null !== $base->compileTimeLong && null !== $exp->compileTimeLong) {
            return true;
        }

        return false;
    }

    /**
     * Long-coercion pow when neither side is float-like (numeric strings, boxed locals).
     */
    private static function preferLongCoercionPowPath(JITVariable $base, JITVariable $exp): bool
    {
        if (self::operandIsFloatLike($base) || self::operandIsFloatLike($exp)) {
            return false;
        }
        if (JITVariable::TYPE_OBJECT === $base->type
            || JITVariable::TYPE_OBJECT === $exp->type
            || JITVariable::TYPE_HASHTABLE === $base->type
            || JITVariable::TYPE_HASHTABLE === $exp->type) {
            return false;
        }

        return true;
    }

    private static function needsRuntimeLongFloatPowDispatch(JITVariable $base, JITVariable $exp): bool
    {
        if (JitValueBox::isValueOperand($base) || JitValueBox::isValueOperand($exp)) {
            return true;
        }
        if (JITVariable::TYPE_STRING === $base->type || JITVariable::TYPE_STRING === $exp->type) {
            return true;
        }

        return false;
    }

    private static function operandIsFloatLike(JITVariable $var): bool
    {
        if (JITVariable::TYPE_NATIVE_DOUBLE === $var->type) {
            return true;
        }
        if (null !== $var->compileTimeFloat) {
            return true;
        }

        return self::numericStringPromotesToDouble($var);
    }

    /**
     * Zend `_is_numeric_string_ex`: overflow / exponent / fractional strings are IS_DOUBLE (#32432).
     */
    private static function numericStringPromotesToDouble(JITVariable $var): bool
    {
        $s = $var->compileTimeString;
        if (null === $s || !is_numeric($s)) {
            return false;
        }

        return !\PHPCompiler\VM\Variable::isIntegralNumericString($s);
    }
}
