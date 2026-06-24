<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Block;
use PHPLLVM\Value;

/**
 * Dispatch property get/set hooks — thin trampoline to {@see PropertyHookDispatchLlvm}
 * + {@see \PHPCompiler\VM\PropertyHookJitHelper} (#10112).
 *
 * php-src: Zend/zend_property_hooks.c
 */
final class PropertyHookDispatch
{
    public static function emitSetHookIfNeeded(
        Context $context,
        Variable $lvalue,
        Variable $value,
        ?Block $enclosingBlock,
        ?\PHPCompiler\JIT $jit = null
    ): bool {
        return PropertyHookDispatchLlvm::emitSetHookIfNeeded($context, $lvalue, $value, $enclosingBlock, $jit);
    }

    public static function tryEmitPropertyGet(
        Context $context,
        Value $receiver,
        string $declaringClass,
        string $propertyName,
        ?Block $enclosingBlock
    ): ?Value {
        return PropertyHookDispatchLlvm::tryEmitPropertyGet($context, $receiver, $declaringClass, $propertyName, $enclosingBlock);
    }

    public static function tryEmitPropertyIsSet(
        Context $context,
        Value $receiver,
        string $declaringClass,
        string $propertyName,
        ?Block $enclosingBlock
    ): ?Value {
        return PropertyHookDispatchLlvm::tryEmitPropertyIsSet($context, $receiver, $declaringClass, $propertyName, $enclosingBlock);
    }

    public static function hookedPropertyBackingName(
        Context $context,
        string $declaringClass,
        string $propertyName
    ): ?string {
        return PropertyHookDispatchLlvm::hookedPropertyBackingName($context, $declaringClass, $propertyName);
    }

    public static function tryEmitStaticPropertyGet(
        Context $context,
        string $declaringClass,
        string $propertyName,
        ?Block $enclosingBlock
    ): ?Value {
        return PropertyHookDispatchLlvm::tryEmitStaticPropertyGet($context, $declaringClass, $propertyName, $enclosingBlock);
    }

    public static function staticPropertyHasSetHook(
        Context $context,
        string $declaringClass,
        string $propertyName
    ): bool {
        return PropertyHookDispatchLlvm::staticPropertyHasSetHook($context, $declaringClass, $propertyName);
    }

    public static function emitStaticSetHookIfNeeded(
        Context $context,
        Variable $lvalue,
        Variable $value,
        ?Block $enclosingBlock,
        ?\PHPCompiler\JIT $jit = null
    ): bool {
        return PropertyHookDispatchLlvm::emitStaticSetHookIfNeeded($context, $lvalue, $value, $enclosingBlock, $jit);
    }

    public static function emitVirtualHookUnsetGuard(
        Context $context,
        string $className,
        string $propertyName,
        ?\PHPCompiler\JIT $jit = null
    ): bool {
        return PropertyHookDispatchLlvm::emitVirtualHookUnsetGuard($context, $className, $propertyName, $jit);
    }

    public static function emitWriteOnlyVirtualReadGuard(
        Context $context,
        ?\PHPCompiler\JIT $jit,
        string $className,
        string $propertyName,
        bool $staticProperty = false
    ): bool {
        return PropertyHookDispatchLlvm::emitWriteOnlyVirtualReadGuard($context, $jit, $className, $propertyName, $staticProperty);
    }
}
