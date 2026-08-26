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

        // Boxed locals may be int or float — zend_pow_function branches at runtime (#35058).
        if (JitValueBox::isValueOperand($args[0]) && JitValueBox::isValueOperand($args[1])) {
            return self::invokeBoxedRuntimeDispatch($context, $slotPtr, $args[0], $args[1]);
        }

        return self::writeLibcPowToSlot($context, $slotPtr, ...$args);
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

        $bothLong = $context->builder->and(
            self::valueIsNativeLong($context, $base),
            self::valueIsNativeLong($context, $exp)
        );
        $intBlock = BasicBlockHelper::append($context, 'pow_runtime_int');
        $floatBlock = BasicBlockHelper::append($context, 'pow_runtime_float');
        $done = BasicBlockHelper::append($context, 'pow_runtime_done');
        $context->builder->branchIf($bothLong, $intBlock, $floatBlock);

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
}
