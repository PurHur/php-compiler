<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNativeString;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

final class ReflectionClassGetMethod implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        [$classSafe, $classLen] = ReflectionSetup::reflectionClassNameAsCstr($context, $obj);

        $methodVar = JitNativeString::coerce($context, $args[1]);
        $methodStr = $context->helper->loadValue($methodVar);
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $raw = $context->builder->pointerCast($methodStr, $i8p);
        $lenPtr = $context->builder->pointerCast(
            $context->builder->gep($raw, $context->constantFromInteger(8, 'size_t')),
            $context->getTypeFromString('int64*')
        );
        $len = $context->builder->load($lenPtr);
        $methodData = $context->builder->gep($raw, $context->constantFromInteger(16, 'size_t'));
        $methodLen = $context->builder->zExt($len, $sizeT);

        $rmClassId = $context->type->object->lookup('ReflectionMethod');
        $rmObj = $context->type->object->allocate($rmClassId);
        ReflectionSetup::emitSetStringPropertyFromCstr(
            $context,
            $rmObj,
            'ReflectionMethod',
            'class',
            $classSafe,
            $classLen
        );
        ReflectionSetup::emitSetStringPropertyFromCstr(
            $context,
            $rmObj,
            'ReflectionMethod',
            'name',
            $context->builder->pointerCast($methodData, $i8p),
            $methodLen
        );
        ReflectionSetup::markConstructed($context, $rmObj);

        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            JitValueBox::pointer($context, $slot),
            $rmObj
        );

        return JitValueBox::pointer($context, $slot);
    }
}
