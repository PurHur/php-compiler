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
        if (self::needsBoxedPowLowering(...$args)) {
            if (JitValueBox::isValueOperand($args[0]) && JitValueBox::isValueOperand($args[1])) {
                return JitValueNumeric::powValueOperands($context, $args[0], $args[1]);
            }

            return self::invokeMixedBoxedPow($context, $args[0], $args[1]);
        }
        $slot = JitValueBox::alloc($context);
        $slotPtr = JitValueBox::pointer($context, $slot);

        if (self::preferIntegerPowPath(...$args)) {
            $folded = self::tryFoldCompileTimeIntegerPow($context, $slot, $slotPtr, $args[0], $args[1]);
            if (null !== $folded) {
                return $folded;
            }
            self::emitIntegerPowViaMathFpow($context, $slotPtr, $args[0], $args[1]);

            return $slotPtr;
        }

        // Runtime int vs float dispatch (numeric strings, boxed locals, floats — #35058, #35337).
        return self::invokeBoxedRuntimeDispatch($context, $slotPtr, $args[0], $args[1]);
    }

    /**
     * Runtime IS_LONG×IS_LONG → int fast path; otherwise float pow (php-src zend_operators.c).
     */
    private static function invokeBoxedRuntimeDispatch(
        Context $context,
        Value $slotPtr,
        JITVariable $base,
        JITVariable $exp
    ): Value {
        PowIntRuntime::ensureLinked($context);
        MathFpow::ensureLinked($context);

        $bothIntegral = $context->builder->and(
            self::operandIsIntegralForPow($context, $base),
            self::operandIsIntegralForPow($context, $exp)
        );
        $intBlock = BasicBlockHelper::append($context, 'pow_runtime_int');
        $floatBlock = BasicBlockHelper::append($context, 'pow_runtime_float');
        $done = BasicBlockHelper::append($context, 'pow_runtime_done');
        $context->builder->branchIf($bothIntegral, $intBlock, $floatBlock);

        $context->builder->positionAtEnd($intBlock);
        self::emitIntegerPowViaMathFpow($context, $slotPtr, $base, $exp);
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
     * Property/dim/by-ref boxed operands and runtime locals — {@see __phpc_pow_int} mis-reads
     * dynamic i64 from value boxes (mul/** divergence; #35978 / leftover #35984).
     */
    private static function needsBoxedPowLowering(JITVariable ...$args): bool
    {
        foreach ($args as $arg) {
            if (!JitValueBox::isValueOperand($arg)) {
                continue;
            }
            if (
                null !== $arg->objectPropertySlot
                || null !== $arg->writableHt
                || null !== $arg->valueBoxAliasPtr
                || $arg->assignRefLvalueAlias
                || $arg->borrowedValueEntry
            ) {
                return true;
            }
        }

        return JitValueBox::isValueOperand($args[0]) || JitValueBox::isValueOperand($args[1]);
    }

    /** Integer ** via MathFpow — avoids broken __phpc_pow_int on boxed operands (#35978). */
    private static function emitIntegerPowViaMathFpow(
        Context $context,
        Value $slotPtr,
        JITVariable $base,
        JITVariable $exp
    ): void {
        MathFpow::ensureLinked($context);
        $baseL = JitLongArg::lower($context, $base, 'pow() base');
        $expL = JitLongArg::lower($context, $exp, 'pow() exponent');
        $double = $context->getTypeFromString('double');
        $i64 = $context->getTypeFromString('int64');
        $baseD = $context->builder->siToFp($baseL, $double);
        $expD = $context->builder->siToFp($expL, $double);
        $fres = MathFpow::invoke($context, $baseD, $expD);
        $longRes = $context->builder->fpToSi($fres, $i64);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $slotPtr,
            $longRes
        );
    }

    /** Property/native mix — never valuePtrFromVariable the native long literal (#35978). */
    private static function invokeMixedBoxedPow(
        Context $context,
        JITVariable $base,
        JITVariable $exp
    ): Value {
        $slot = JitValueBox::alloc($context);
        $slotPtr = JitValueBox::pointer($context, $slot);
        PowIntRuntime::ensureLinked($context);
        MathFpow::ensureLinked($context);

        $boxed = JitValueBox::isValueOperand($base) ? $base : $exp;
        $other = $boxed === $base ? $exp : $base;
        $boxedTy = JitValueNumeric::valueIsDouble($context, $boxed);
        $i1 = $context->getTypeFromString('int1');
        $otherIsDouble = JITVariable::TYPE_NATIVE_DOUBLE === $other->type
            ? $i1->constInt(1, false)
            : $i1->constInt(0, false);
        $needsFloat = $context->builder->or($boxedTy, $otherIsDouble);
        $intBlock = BasicBlockHelper::append($context, 'pow_mixed_int');
        $floatBlock = BasicBlockHelper::append($context, 'pow_mixed_float');
        $done = BasicBlockHelper::append($context, 'pow_mixed_done');
        $context->builder->branchIf($needsFloat, $floatBlock, $intBlock);

        $context->builder->positionAtEnd($intBlock);
        $baseL = JitLongArg::lower($context, $base, 'pow() base');
        $expL = JitLongArg::lower($context, $exp, 'pow() exponent');
        $double = $context->getTypeFromString('double');
        $i64 = $context->getTypeFromString('int64');
        $baseD = $context->builder->siToFp($baseL, $double);
        $expD = $context->builder->siToFp($expL, $double);
        $fres = MathFpow::invoke($context, $baseD, $expD);
        $longRes = $context->builder->fpToSi($fres, $i64);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $slotPtr,
            $longRes
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

    private static function valueIsNativeLong(Context $context, JITVariable $boxed): Value
    {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $boxed);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');

        return $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(JITVariable::TYPE_NATIVE_LONG, false)
        );
    }

    /**
     * Zend pow_function integer fast path — operand coerces to int, not float (#35337).
     */
    private static function operandIsIntegralForPow(Context $context, JITVariable $arg): Value
    {
        $i1 = $context->getTypeFromString('int1');
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type) {
            return $i1->constInt(1, false);
        }
        if (JITVariable::TYPE_NATIVE_DOUBLE === $arg->type) {
            return $i1->constInt(0, false);
        }
        if (JITVariable::TYPE_NATIVE_BOOL === $arg->type || JITVariable::TYPE_NULL === $arg->type) {
            return $i1->constInt(1, false);
        }
        if (JITVariable::TYPE_STRING === $arg->type && null !== $arg->compileTimeString) {
            return $i1->constInt(
                \PHPCompiler\VM\Variable::isIntegralNumericString($arg->compileTimeString) ? 1 : 0,
                false
            );
        }
        if (JITVariable::TYPE_STRING === $arg->type) {
            return self::nativeStringIsIntegralForPow($context, $arg);
        }
        if (
            null !== $arg->compileTimeLong
            && null === $arg->compileTimeFloat
            && !JitValueBox::isValueOperand($arg)
        ) {
            return $i1->constInt(1, false);
        }
        if (!JitValueBox::isValueOperand($arg)) {
            return $i1->constInt(0, false);
        }

        $isLong = self::valueIsNativeLong($context, $arg);
        $isBool = JitValueNumeric::valueIsBool($context, $arg);
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(JITVariable::TYPE_NULL, false)
        );
        $stringIntegral = self::boxedStringIsIntegralForPow($context, $arg);

        return $context->builder->or(
            $isLong,
            $context->builder->or(
                $isBool,
                $context->builder->or($isNull, $stringIntegral)
            )
        );
    }

    /** i1: 1 when boxed operand is an integral numeric string, else 0. */
    private static function boxedStringIsIntegralForPow(Context $context, JITVariable $boxed): Value
    {
        $i1 = $context->getTypeFromString('int1');
        $falseVal = $i1->constInt(0, false);
        $isString = JitValueNumeric::valueIsString($context, $boxed);
        $entryEnd = $context->builder->getInsertBlock();
        $strBlock = BasicBlockHelper::append($context, 'pow_str_integral');
        $done = BasicBlockHelper::append($context, 'pow_str_integral_done');
        $context->builder->branchIf($isString, $strBlock, $done);

        $context->builder->positionAtEnd($strBlock);
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $boxed);
        $strPtr = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $valuePtr
        );
        $strOk = self::stringStructIsIntegralForPow($context, $strPtr);
        $strEnd = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $phi = $context->builder->phi($i1, 'pow_str_integral_phi');
        $phi->addIncoming($falseVal, $entryEnd);
        $phi->addIncoming($strOk, $strEnd);

        return $phi;
    }

    /** i1: 1 when a native __string__* operand is an integral numeric string. */
    private static function nativeStringIsIntegralForPow(Context $context, JITVariable $strVar): Value
    {
        $strPtr = $context->helper->loadValue($strVar);

        return self::stringStructIsIntegralForPow($context, $strPtr);
    }

    /**
     * Zend _is_numeric_string_ex IS_LONG shape for pow — no '.' or exponent (#35337).
     *
     * Matches {@see \PHPCompiler\VM\Variable::isIntegralNumericString} for common cases
     * (e.g. "2" vs "2.5"); overflow digit strings fall through to float via strtod mismatch.
     */
    private static function stringStructIsIntegralForPow(Context $context, Value $strPtr): Value
    {
        $i8ptr = self::stringDataPtr($context, $strPtr);
        $i1 = $context->getTypeFromString('int1');
        $i32 = $context->getTypeFromString('int32');
        $null = $i8ptr->typeOf()->constNull();
        $hasDot = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->call(
                $context->lookupFunction('strchr'),
                $i8ptr,
                $i32->constInt(ord('.'), false)
            ),
            $null
        );
        $hasExp = $context->builder->or(
            $context->builder->icmp(
                Builder::INT_NE,
                $context->builder->call(
                    $context->lookupFunction('strchr'),
                    $i8ptr,
                    $i32->constInt(ord('e'), false)
                ),
                $null
            ),
            $context->builder->icmp(
                Builder::INT_NE,
                $context->builder->call(
                    $context->lookupFunction('strchr'),
                    $i8ptr,
                    $i32->constInt(ord('E'), false)
                ),
                $null
            )
        );
        $hasFractionalSyntax = $context->builder->or($hasDot, $hasExp);

        return $context->builder->xor($hasFractionalSyntax, $i1->constInt(1, false));
    }

    private static function stringDataPtr(Context $context, Value $strPtr): Value
    {
        $map = $context->structFieldMap['__string__'];

        return $context->builder->pointerCast(
            $context->builder->structGep($strPtr, $map['value']),
            $context->getTypeFromString('int8*')
        );
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
        $baseLong = self::compileTimeIntegralLong($base);
        $expLong = self::compileTimeIntegralLong($exp);
        if (null === $baseLong || null === $expLong) {
            return null;
        }
        if (null !== $base->compileTimeFloat || null !== $exp->compileTimeFloat) {
            return null;
        }
        $result = $baseLong ** $expLong;
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
     * Zend pow_function / ** : integer fast path when both operands are integral.
     * Includes integer-shaped numeric strings ({@code "2"**3} → int(8)); float-shaped
     * strings ({@code "2.5"}) stay on the float path (#35344, peer #35337).
     * Boxed TYPE_VALUE may hold floats — do not truncate via JitLongArg (#35058).
     */
    private static function preferIntegerPowPath(JITVariable $base, JITVariable $exp): bool
    {
        return self::isIntegerPowOperand($base) && self::isIntegerPowOperand($exp);
    }

    private static function isIntegerPowOperand(JITVariable $operand): bool
    {
        if (JITVariable::TYPE_NATIVE_DOUBLE === $operand->type
            || null !== $operand->compileTimeFloat) {
            return false;
        }
        if (JITVariable::TYPE_OBJECT === $operand->type
            || JITVariable::TYPE_HASHTABLE === $operand->type) {
            return false;
        }
        if (
            null !== $operand->compileTimeLong
            && JitValueBox::isValueOperand($operand)
        ) {
            return false;
        }
        if (JITVariable::TYPE_NATIVE_LONG === $operand->type
            || JITVariable::TYPE_NATIVE_BOOL === $operand->type
            || null !== $operand->compileTimeLong) {
            return true;
        }
        // Compile-time integer numeric string — zend converts to IS_LONG before pow (#35344).
        if (null !== $operand->compileTimeString) {
            return \PHPCompiler\VM\Variable::isIntegralNumericString($operand->compileTimeString);
        }

        return false;
    }

    /** Compile-time long for fold — includes integral numeric strings (#35344). */
    private static function compileTimeIntegralLong(JITVariable $operand): ?int
    {
        if (
            null !== $operand->compileTimeLong
            && JitValueBox::isValueOperand($operand)
        ) {
            return null;
        }
        if (null !== $operand->compileTimeLong) {
            return $operand->compileTimeLong;
        }
        if (null !== $operand->compileTimeString
            && \PHPCompiler\VM\Variable::isIntegralNumericString($operand->compileTimeString)
        ) {
            return (int) $operand->compileTimeString;
        }

        return null;
    }
}
