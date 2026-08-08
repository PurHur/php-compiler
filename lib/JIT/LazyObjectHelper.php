<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Call;
use PHPLLVM\Value;

/**
 * MCJIT lazy object init on first use — thin trampoline to {@see LazyObjectHelperLlvm} (#4940, #5318, #10267).
 *
 * VM SSOT: {@see \PHPCompiler\VM\LazyObjectSupport} · header fields: {@see \PHPCompiler\VM\VmLazyObject}
 * php-src: Zend/zend_lazy_objects.c
 */
final class LazyObjectHelper
{
    public static function registerInitProxy(
        Context $context,
        Call $proxy,
        ?Variable $closure = null,
        ?string $className = null
    ): int {
        $index = \count($context->lazyInitProxies);
        $context->lazyInitProxies[$index] = $proxy;
        if (null !== $closure) {
            $context->lazyInitClosures[$index] = $closure;
        }
        if (null !== $className && '' !== $className) {
            $context->lazyInitProxyClassNames[$index] = $className;
        }

        return $index;
    }

    /** Record proxy class name for Zend TypeError text when known at lower time (#29170). */
    public static function setInitProxyClassName(Context $context, int $index, string $className): void
    {
        if ('' !== $className) {
            $context->lazyInitProxyClassNames[$index] = $className;
        }
    }

    public static function registerLazyObject(
        Context $context,
        Value $obj,
        int $initIndex,
        bool $ghost
    ): void {
        LazyObjectHelperLlvm::registerLazyObject($context, $obj, $initIndex, $ghost);
    }

    /**
     * Mark lazy only when the runtime class has declared instance properties (#21570).
     *
     * @see Zend/zend_lazy_objects.c zend_object_make_lazy — zero-prop early return
     */
    public static function registerLazyObjectForRuntimeClass(
        Context $context,
        Value $obj,
        int $initIndex,
        bool $ghost,
        Value $classIdVal
    ): void {
        LazyObjectHelperLlvm::registerLazyObjectForRuntimeClass(
            $context,
            $obj,
            $initIndex,
            $ghost,
            $classIdVal
        );
    }

    public static function emitEnsureInitialized(Context $context, Value $obj): void
    {
        LazyObjectHelperLlvm::emitEnsureInitialized($context, $obj);
    }
}
