<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bcmath;

use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\ext\standard\strval as StrvalBuiltin;
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
        \PHPCompiler\JIT\Builtin\Bcmath::ensureLinked($context);
        $obj = self::loadObjectFromArg($context, $receiver);
        $valueStr = (new StrvalBuiltin())->call($context, $numArg);
        $canonical = $context->builder->call(
            $context->lookupFunction('__compiler_bcmath_number_canonical'),
            $valueStr
        );
        $scale = $context->builder->call(
            $context->lookupFunction('__compiler_bcmath_number_decimal_scale'),
            $valueStr
        );
        self::storeValueAndScale($context, $obj, $canonical, $scale);
        $context->type->object->markObjectConstructed($obj);
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
    public static function boxNewNumber(Context $context, Value $valueStr, Value $scaleLong): Variable
    {
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

        return new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $slot);
    }
}
