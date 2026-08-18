<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\MathFpow;
use PHPCompiler\JIT\Builtin\PowIntRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitEnumNumericOperandGuard;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitPowNumericOperandGuard;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for pow() int/float return (issue #3678). */
final class JitPow
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('pow() requires exactly two arguments');
        }

        if ($context->powReturnValueBox) {
            return self::invokeBoxedIntAware($context, ...$args);
        }

        return self::invokeBoxedLibcPow($context, ...$args);
    }

    /**
     * Power operator ** — preserve int in the value box when both operands are long.
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

        return self::writeLibcPowToSlot($context, $slotPtr, ...$args);
    }

    /** pow() FUNCCALL — boxed double (matches gettimeofday float path for assign). */
    private static function invokeBoxedLibcPow(Context $context, JITVariable ...$args): Value
    {
        $slot = JitValueBox::alloc($context);
        $slotPtr = JitValueBox::pointer($context, $slot);

        return self::writeLibcPowToSlot($context, $slotPtr, ...$args);
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
     * Zend pow_function / ** operator: numeric strings promote to int when both operands are integral.
     */
    private static function preferIntegerPowPath(JITVariable $base, JITVariable $exp): bool
    {
        if (JITVariable::TYPE_NATIVE_DOUBLE === $base->type
            || JITVariable::TYPE_NATIVE_DOUBLE === $exp->type) {
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
}
