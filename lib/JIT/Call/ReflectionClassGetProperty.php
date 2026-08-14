<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNativeString;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\ReflectionSupport;
use PHPLLVM\Value;

/**
 * ReflectionClass::getProperty($name) — AOT (#30910 / #4395).
 *
 * Thin AOT previously hit ExternalMethod null; setAccessible/getValue then SEGV on NULL.
 */
final class ReflectionClassGetProperty implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \ArgumentCountError(
                'ReflectionClass::getProperty() expects exactly 1 argument, '.(\count($args) - 1).' given'
            );
        }
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        [$classSafe, $classLen] = ReflectionSetup::reflectionClassNameAsCstr($context, $obj);

        $propVar = JitNativeString::coerce($context, $args[1]);
        $propStr = $context->helper->loadValue($propVar);
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $raw = $context->builder->pointerCast($propStr, $i8p);
        $lenPtr = $context->builder->pointerCast(
            $context->builder->gep($raw, $context->constantFromInteger(8, 'size_t')),
            $context->getTypeFromString('int64*')
        );
        $len = $context->builder->load($lenPtr);
        $propData = $context->builder->gep($raw, $context->constantFromInteger(16, 'size_t'));
        $propLen = $context->builder->zExt($len, $sizeT);

        $rpClassId = $context->type->object->lookup('ReflectionProperty');
        $rpObj = $context->type->object->allocate($rpClassId);
        // Zend public surface: $name = property, $class = declaring class (#22504).
        ReflectionSetup::emitSetStringPropertyFromCstr(
            $context,
            $rpObj,
            'ReflectionProperty',
            ReflectionSupport::PROP_PROPERTY_NAME,
            $context->builder->pointerCast($propData, $i8p),
            $propLen
        );
        ReflectionSetup::emitSetStringPropertyFromCstr(
            $context,
            $rpObj,
            'ReflectionProperty',
            ReflectionSupport::PROP_DECLARING_CLASS_NAME,
            $classSafe,
            $classLen
        );
        ReflectionSetup::markConstructed($context, $rpObj);

        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            JitValueBox::pointer($context, $slot),
            $rpObj
        );

        return JitValueBox::pointer($context, $slot);
    }
}
