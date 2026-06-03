<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
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
        $slot = JitValueBox::alloc($context);
        $slotPtr = JitValueBox::pointer($context, $slot);

        if (self::preferIntegerPowPath(...$args)) {
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
        $double = $context->getTypeFromString('double');
        $baseD = pow::toJitDouble($context, $args[0], $double);
        $expD = pow::toJitDouble($context, $args[1], $double);
        $result = $context->builder->call($context->lookupFunction('pow'), $baseD, $expD);
        $context->builder->call(
            $context->lookupFunction('__value__writeDouble'),
            $slotPtr,
            $result
        );

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
