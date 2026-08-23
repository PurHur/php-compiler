<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/**
 * ReflectionExtension::isTemporary() — JIT/AOT (#34154, ext/reflection/php_reflection.c).
 *
 * Thin AOT previously had no proxy; ExternalMethod → NULL.
 * We never load via dl() → always false
 * ({@see \PHPCompiler\ext\standard\VmReflection::reflectionExtensionIsTemporary}).
 * Peer {@see ReflectionExtensionIsPersistent}; VM #22247.
 */
final class ReflectionExtensionIsTemporary implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        $userArgCount = \count($args) - 1;
        if (0 !== $userArgCount) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                VmClassMethod::exactUserArgCountMessage(
                    'ReflectionExtension::isTemporary',
                    0,
                    $userArgCount
                )
            );
            $unreachable = BasicBlockHelper::append(
                $context,
                'refl_ext_istemporary_argc_unreach'
            );
            $context->builder->positionAtEnd($unreachable);

            return JitValueBox::alloc($context);
        }

        // Touch $this so unbound property reads still validate the receiver.
        ReflectionSetup::loadObjectFromArg($context, $args[0]);

        $resultSlot = JitValueBox::alloc($context);
        JitValueBox::writeBool(
            $context,
            $resultSlot,
            $context->getTypeFromString('int1')->constInt(0, false)
        );

        return $resultSlot;
    }
}
