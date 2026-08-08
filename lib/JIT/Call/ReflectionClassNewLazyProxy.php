<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Builtin\ReflectionNative;
use PHPCompiler\JIT\Builtin\ReflectionRuntime;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\ClosureHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\LazyObjectHelper;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** ReflectionClass::newLazyProxy(callable) — MCJIT instance ABI (#4940, #5318, #22527). */
final class ReflectionClassNewLazyProxy implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        // Instance: [ReflectionClass $this, callable $factory, ?int $options]
        if (\count($args) < 2) {
            throw new \ArgumentCountError(
                'ReflectionClass::newLazyProxy() expects at least 1 argument, 0 given'
            );
        }
        ReflectionRuntime::ensureLinked($context);
        ReflectionNative::registerDeclarations($context);

        $initProxy = ClosureHelper::resolveCall($context, $args[1]);
        if (null === $initProxy) {
            throw new \LogicException('ReflectionClass::newLazyProxy() expects a callable');
        }
        $initIndex = LazyObjectHelper::registerInitProxy($context, $initProxy, $args[1]);

        $classIdVal = self::loadClassIdFromReflection($context, $args[0]);
        $obj = $context->type->object->allocateForRuntimeClassId($classIdVal);
        LazyObjectHelper::registerLazyObjectForRuntimeClass($context, $obj, $initIndex, false, $classIdVal);

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
        return self::loadClassIdFromLazyFactoryArg($context, $receiver);
    }

    public static function loadClassIdFromLazyFactoryArg(Context $context, Variable $arg): Value
    {
        if (Variable::TYPE_STRING === ($arg->type & ~Variable::IS_REFCOUNTED)) {
            [$cstr, $len] = ReflectionSetup::stringVarAsCstr($context, $arg);

            return $context->type->object->classIdFromRuntimeName($cstr, $len);
        }

        $obj = ReflectionSetup::loadObjectFromArg($context, $arg);
        [$cstr, $len] = ReflectionSetup::reflectionClassNameAsCstr($context, $obj);

        return $context->type->object->classIdFromRuntimeName($cstr, $len);
    }
}
