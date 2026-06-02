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

/** ReflectionClass::getReflectionConstant() — JIT (#4136). */
final class ReflectionClassGetReflectionConstant implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('ReflectionClass::getReflectionConstant() expects a constant name');
        }
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');
        $objArg = $context->builder->pointerCast($obj, $i8p);
        $outClassLen = BasicBlockHelper::entryAlloca($context, $sizeT);
        $classCstr = $context->builder->call($context->lookupFunction('phpc_reflect_get_class_name'), $objArg, $outClassLen);
        $classLen = $context->builder->load($outClassLen);
        $classNull = $context->builder->icmp(Builder::INT_EQ, $classCstr, $classCstr->typeOf()->constNull());
        $empty = $context->builder->pointerCast($context->constantFromString(''), $i8p);
        $classSafe = $context->builder->select($classNull, $empty, $classCstr);
        $classLen = $context->builder->select($classNull, $sizeT->constInt(0, false), $classLen);

        $constVar = JitNativeString::coerce($context, $args[1]);
        $rcClassId = $context->type->object->lookup('ReflectionConstant');
        $rcObj = $context->type->object->allocate($rcClassId);
        ReflectionSetup::markConstructed($context, $rcObj);
        ReflectionSetup::emitSetStringPropertyFromCstr(
            $context,
            $rcObj,
            'ReflectionConstant',
            'name',
            $classSafe,
            $classLen
        );
        $constStr = $context->helper->loadValue($constVar);
        $raw = $context->builder->pointerCast($constStr, $i8p);
        $lenPtr = $context->builder->pointerCast(
            $context->builder->gep($raw, $context->constantFromInteger(8, 'size_t')),
            $context->getTypeFromString('int64*')
        );
        $len = $context->builder->load($lenPtr);
        $data = $context->builder->gep($raw, $context->constantFromInteger(16, 'size_t'));
        ReflectionSetup::emitSetStringPropertyFromCstr(
            $context,
            $rcObj,
            'ReflectionConstant',
            'constant',
            $context->builder->pointerCast($data, $i8p),
            $context->builder->zExt($len, $sizeT)
        );

        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            JitValueBox::pointer($context, $slot),
            $rcObj
        );

        return JitValueBox::pointer($context, $slot);
    }
}
