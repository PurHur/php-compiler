<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Builtin\ReflectionNative;
use PHPCompiler\JIT\Builtin\ReflectionRuntime;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\ClosureHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\LazyObjectHelper;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** ReflectionClass::newLazyGhost(callable) — MCJIT instance ABI (#4940, #5318, #22527). */
final class ReflectionClassNewLazyGhost implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        // Instance: [ReflectionClass $this, callable $initializer, ?int $options]
        if (\count($args) < 2) {
            throw new \ArgumentCountError(
                'ReflectionClass::newLazyGhost() expects at least 1 argument, 0 given'
            );
        }
        ReflectionRuntime::ensureLinked($context);
        ReflectionNative::registerDeclarations($context);

        $initProxy = ClosureHelper::resolveCall($context, $args[1]);
        if (null === $initProxy) {
            throw new \LogicException('ReflectionClass::newLazyGhost() expects a callable');
        }
        $initIndex = LazyObjectHelper::registerInitProxy($context, $initProxy, $args[1]);

        $classIdVal = ReflectionClassNewLazyProxy::loadClassIdFromLazyFactoryArg($context, $args[0]);
        $obj = $context->type->object->allocateForRuntimeClassId($classIdVal);
        $context->type->object->resetInstancePropertySlots($obj, $classIdVal);
        LazyObjectHelper::registerLazyObjectForRuntimeClass($context, $obj, $initIndex, true, $classIdVal);

        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            JitValueBox::pointer($context, $slot),
            $obj
        );

        return $slot;
    }
}
