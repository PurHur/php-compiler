<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\LazyObjectNative;
use PHPCompiler\JIT\Builtin\LazyObjectRuntime;
use PHPCompiler\JIT\Builtin\ReflectionNative;
use PHPCompiler\JIT\Builtin\ReflectionRuntime;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\ClosureHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\LazyObjectHelper;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** ReflectionClass::newLazyProxy(callable) — MCJIT (#4940). */
final class ReflectionClassNewLazyProxy implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('ReflectionClass::newLazyProxy() expects an initializer callable');
        }
        ReflectionRuntime::ensureLinked($context);
        ReflectionNative::registerDeclarations($context);
        LazyObjectNative::registerDeclarations($context);
        LazyObjectRuntime::ensureLinked($context);

        $initProxy = ClosureHelper::resolveCall($context, $args[1]);
        if (null === $initProxy) {
            throw new \LogicException('ReflectionClass::newLazyProxy() expects a callable');
        }
        $initIndex = LazyObjectHelper::registerInitProxy($context, $initProxy);

        $classIdVal = self::loadClassIdFromReflection($context, $args[0]);
        $obj = $context->type->object->allocateForRuntimeClassId($classIdVal);
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $context->builder->call(
            $context->lookupFunction('phpc_lazy_register'),
            $context->builder->pointerCast($obj, $i8p),
            $context->constantFromInteger($initIndex, 'int32'),
            $i32->constInt(0, false)
        );

        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            JitValueBox::pointer($context, $slot),
            $obj
        );

        return $slot;
    }

    public static function loadClassIdFromReflection(Context $context, Variable $receiver): Value
    {
        $obj = ReflectionSetup::loadObjectFromArg($context, $receiver);
        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');
        $objArg = $context->builder->pointerCast($obj, $i8p);
        $outLen = BasicBlockHelper::entryAlloca($context, $sizeT);
        $namePtr = $context->builder->call(
            $context->lookupFunction('phpc_reflect_get_class_name'),
            $objArg,
            $outLen
        );
        $isNull = $context->builder->icmp(Builder::INT_EQ, $namePtr, $namePtr->typeOf()->constNull());
        $empty = $context->builder->pointerCast($context->constantFromString(''), $i8p);
        $cstr = $context->builder->select($isNull, $empty, $namePtr);
        $len = $context->builder->load($outLen);

        return $context->type->object->classIdFromRuntimeName($cstr, $len);
    }
}
