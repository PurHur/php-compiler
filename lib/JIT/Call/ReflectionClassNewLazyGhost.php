<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Builtin\LazyObjectNative;
use PHPCompiler\JIT\Builtin\LazyObjectRuntime;
use PHPCompiler\JIT\Builtin\ReflectionNative;
use PHPCompiler\JIT\Builtin\ReflectionRuntime;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\ClosureHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\LazyObjectHelper;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** ReflectionClass::newLazyGhost(callable) — MCJIT (#4940). */
final class ReflectionClassNewLazyGhost implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('ReflectionClass::newLazyGhost() expects an initializer callable');
        }
        ReflectionRuntime::ensureLinked($context);
        ReflectionNative::registerDeclarations($context);
        LazyObjectNative::registerDeclarations($context);
        LazyObjectRuntime::ensureLinked($context);

        $initProxy = ClosureHelper::resolveCall($context, $args[1]);
        if (null === $initProxy) {
            throw new \LogicException('ReflectionClass::newLazyGhost() expects a callable');
        }
        $initIndex = LazyObjectHelper::registerInitProxy($context, $initProxy);

        $classIdVal = ReflectionClassNewLazyProxy::loadClassIdFromReflection($context, $args[0]);
        $obj = $context->type->object->allocateForRuntimeClassId($classIdVal);
        $context->type->object->resetInstancePropertySlots($obj, $classIdVal);
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $context->builder->call(
            $context->lookupFunction('phpc_lazy_register'),
            $context->builder->pointerCast($obj, $i8p),
            $context->constantFromInteger($initIndex, 'int32'),
            $i32->constInt(1, false)
        );

        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            JitValueBox::pointer($context, $slot),
            $obj
        );

        return $slot;
    }
}
