<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\ReflectionNative;
use PHPCompiler\JIT\Builtin\ReflectionRuntime;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\ClosureHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\LazyObjectHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for createLazyGhost() (#6708). */
final class JitCreateLazyGhost
{
    private const NAME = 'createLazyGhost';

    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                self::NAME.'() expects at least 2 arguments, '.$argc.' given'
            );

            return JitValueBox::pointer($context, JitValueBox::alloc($context));
        }
        ReflectionRuntime::ensureLinked($context);
        ReflectionNative::registerDeclarations($context);

        $initProxy = ClosureHelper::resolveCall($context, $args[1]);
        if (null === $initProxy) {
            throw new \LogicException(self::NAME.'() expects a callable initializer');
        }
        $initIndex = LazyObjectHelper::registerInitProxy($context, $initProxy, $args[1]);

        [$cstr, $len] = ReflectionSetup::stringVarAsCstr($context, $args[0]);
        $classIdVal = $context->type->object->classIdFromRuntimeName($cstr, $len);
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
