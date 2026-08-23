<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ReflectionNative;
use PHPCompiler\JIT\Builtin\ReflectionRuntime;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/**
 * ReflectionClass::newInstanceWithoutConstructor() — thin AOT / MCJIT (#34078, #5443).
 *
 * Peer of ReflectionClassNewLazyGhost: resolve class id from the ReflectionClass
 * receiver, allocate with property defaults, do not run __construct.
 * php-src: zim_ReflectionClass_newInstanceWithoutConstructor (exact arity 0, #30923).
 */
final class ReflectionClassNewInstanceWithoutConstructor implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        // Instance ABI: [ReflectionClass $this, ...userArgs]
        $userArgCount = \count($args) - 1;
        if (0 !== $userArgCount) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                VmClassMethod::exactUserArgCountMessage(
                    'ReflectionClass::newInstanceWithoutConstructor',
                    0,
                    max(0, $userArgCount)
                )
            );
            $unreachable = BasicBlockHelper::append(
                $context,
                'refl_class_niwc_argc_unreach'
            );
            $context->builder->positionAtEnd($unreachable);

            return JitValueBox::alloc($context);
        }
        if ([] === $args) {
            throw new \LogicException(
                'ReflectionClass::newInstanceWithoutConstructor() requires an object receiver'
            );
        }

        ReflectionRuntime::ensureLinked($context);
        ReflectionNative::registerDeclarations($context);

        $classIdVal = ReflectionClassNewLazyProxy::loadClassIdFromLazyFactoryArg($context, $args[0]);
        // Property defaults via allocate(); constructed stays 0 when the class has a ctor.
        $obj = $context->type->object->allocateForRuntimeClassId($classIdVal);

        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            JitValueBox::pointer($context, $slot),
            $obj
        );

        return $slot;
    }
}
