<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/**
 * ReflectionClass::newInstanceWithoutConstructor() — JIT/AOT (#34078, ext/reflection/php_reflection.c).
 *
 * Thin AOT previously had no proxy; ExternalMethod aborted (rc=134). Allocate via
 * {@see \PHPCompiler\JIT\Builtin\Type\Object_::allocateForRuntimeClassId} so property
 * defaults apply and `__construct` is not invoked (peer {@see ReflectionClassNewLazyGhost}).
 *
 * php-src: zim_ReflectionClass_newInstanceWithoutConstructor
 */
final class ReflectionClassNewInstanceWithoutConstructor implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        // php-src: zim_ReflectionClass_newInstanceWithoutConstructor — 0 args; $args[0] is $this (#30923)
        $userArgCount = \count($args) - 1;
        if (0 !== $userArgCount) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                VmClassMethod::exactUserArgCountMessage(
                    'ReflectionClass::newInstanceWithoutConstructor',
                    0,
                    $userArgCount
                )
            );
            $unreachable = BasicBlockHelper::append(
                $context,
                'refl_class_new_instance_wo_ctor_argc_unreach'
            );
            $context->builder->positionAtEnd($unreachable);

            return JitValueBox::alloc($context);
        }

        $classIdVal = ReflectionClassNewLazyProxy::loadClassIdFromLazyFactoryArg(
            $context,
            $args[0]
        );
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
