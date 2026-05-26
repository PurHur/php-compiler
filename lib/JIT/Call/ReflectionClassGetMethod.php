<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNativeString;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class ReflectionClassGetMethod implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        $objPtr = $context->builder->pointerCast($obj, $context->getTypeFromString('__object__*'));
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');

        $outClassLen = BasicBlockHelper::entryAlloca($context, $sizeT);
        $classCstr = $context->builder->call($context->lookupFunction('phpc_reflect_get_class_name'), $objPtr, $outClassLen);
        $classLen = $context->builder->load($outClassLen);
        $classNull = $context->builder->icmp(Builder::INT_EQ, $classCstr, $classCstr->typeOf()->constNull());
        $empty = $context->builder->pointerCast($context->constantFromString(''), $i8p);
        $classSafe = $context->builder->select($classNull, $empty, $classCstr);
        $classLen = $context->builder->select($classNull, $sizeT->constInt(0, false), $classLen);

        $methodVar = JitNativeString::coerce($context, $args[1]);
        $methodStr = $context->helper->loadValue($methodVar);
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
        $context->builder->call(
            $context->lookupFunction('phpc_reflect_set_method'),
            $context->builder->pointerCast($rmObj, $context->getTypeFromString('__object__*')),
            $classSafe,
            $classLen,
            $context->builder->pointerCast($methodData, $i8p),
            $methodLen
        );

        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            JitValueBox::pointer($context, $slot),
            $rmObj
        );

        return JitValueBox::pointer($context, $slot);
    }
}
