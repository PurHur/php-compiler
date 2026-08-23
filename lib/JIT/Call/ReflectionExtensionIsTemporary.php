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
 * Thin AOT previously had no proxy; ExternalMethod → NULL. Peer
 * {@see ReflectionExtensionIsPersistent} (#34154); VM #22247.
 *
 * Temporary = MODULE_TEMPORARY (dl()); we never load via dl(), so always false —
 * {@see \PHPCompiler\ext\standard\VmReflection::reflectionExtensionIsTemporary}.
 *
 * php-src: zim_ReflectionExtension_isTemporary
 */
final class ReflectionExtensionIsTemporary implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        // php-src: zim_ReflectionExtension_isTemporary — 0 user args
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
        // Touch $this so malformed receivers still go through object load.
        ReflectionSetup::loadObjectFromArg($context, $args[0]);

        $i1 = $context->getTypeFromString('int1');
        $resultSlot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $resultSlot, $i1->constInt(0, false));

        return $resultSlot;
    }
}
