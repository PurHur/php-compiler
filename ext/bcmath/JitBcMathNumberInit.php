<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bcmath;

use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * Shared JIT/AOT init + property access for BcMath\Number (#24683, #7220).
 *
 * php-src: ext/bcmath/bcmath.c — bcmath_number_init / property layout
 * VM SSOT: {@see VmBcMathNumber::initObject}
 */
final class JitBcMathNumberInit
{
    public static function classDisplayName(): string
    {
        return 'BcMath\\Number';
    }

    public static function loadObjectFromArg(Context $context, Variable $receiver): Value
    {
        return ReflectionSetup::loadObjectFromArg($context, $receiver);
    }

    public static function initFromArg(Context $context, Variable $receiver, Variable $numArg): void
    {
        $obj = self::loadObjectFromArg($context, $receiver);
        $lit = \PHPCompiler\JIT\JitStringArg::compileTimeLiteral($numArg);
        if (null === $lit) {
            if (Variable::TYPE_NATIVE_LONG === $numArg->type && null !== ($numArg->compileTimeLong ?? null)) {
                $lit = (string) $numArg->compileTimeLong;
            }
        }
        if (null === $lit) {
            throw new \LogicException('BcMath\\Number::__construct() JIT requires a compile-time string|int in this build (#24683)');
        }
        // Avoid NestedJIT mid-ctor (dominance); fold canonical/scale at compile time.
        $canonical = VmBcmath::canonicalNumberString($lit);
        $scale = VmBcmath::decimalScale($lit);
        $valueStr = $context->builder->load($context->constantStringFromString($canonical));
        $scaleLong = $context->getTypeFromString('int64')->constInt($scale, true);
        self::storeValueAndScale($context, $obj, $valueStr, $scaleLong);
        $context->type->object->markObjectConstructed($obj);
        $receiver->compileTimeBcmathNumber = [
            'value' => $canonical,
            'scale' => $scale,
        ];
    }

    public static function storeValueAndScale(
        Context $context,
        Value $obj,
        Value $valueStr,
        Value $scaleLong
    ): void {
        $object = $context->type->object;
        $class = self::classDisplayName();
        $valueVar = new Variable($context, Variable::TYPE_STRING, Variable::KIND_VALUE, $valueStr);
        $scaleVar = new Variable($context, Variable::TYPE_NATIVE_LONG, Variable::KIND_VALUE, $scaleLong);
        $object->storeInstanceProperty($obj, $class, VmBcMathNumber::PROP_VALUE, $valueVar);
        $object->storeInstanceProperty($obj, $class, VmBcMathNumber::PROP_SCALE, $scaleVar);
    }

    public static function loadValueString(Context $context, Variable $receiver): Value
    {
        $obj = self::loadObjectFromArg($context, $receiver);
        $fetched = $context->type->object->propertyFetch(
            $obj,
            self::classDisplayName(),
            VmBcMathNumber::PROP_VALUE
        );

        return $context->helper->loadValue($fetched);
    }

    public static function loadScaleLong(Context $context, Variable $receiver): Value
    {
        $obj = self::loadObjectFromArg($context, $receiver);
        $fetched = $context->type->object->propertyFetch(
            $obj,
            self::classDisplayName(),
            VmBcMathNumber::PROP_SCALE
        );

        return $context->helper->loadValue($fetched);
    }

    /** Allocate a constructed Number and write it into a fresh value box. */
    public static function boxNewNumber(
        Context $context,
        Value $valueStr,
        Value $scaleLong,
        ?array $compileTime = null
    ): Variable {
        $classId = $context->type->object->lookup(self::classDisplayName());
        $obj = $context->type->object->allocate($classId);
        self::storeValueAndScale($context, $obj, $valueStr, $scaleLong);
        $context->type->object->markObjectConstructed($obj);
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            JitValueBox::pointer($context, $slot),
            $obj
        );

        $var = new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $slot);
        if (null !== $compileTime) {
            $var->compileTimeBcmathNumber = $compileTime;
        }

        return $var;
    }
}
