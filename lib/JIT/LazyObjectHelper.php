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
    public static function registerInitProxy(Context $context, Call $proxy): int
    {
        $index = \count($context->lazyInitProxies);
        $context->lazyInitProxies[$index] = $proxy;

        return $index;
    }

    public static function registerLazyObject(
        Context $context,
        Value $obj,
        int $initIndex,
        bool $ghost
    ): void {
        LazyObjectHelperLlvm::registerLazyObject($context, $obj, $initIndex, $ghost);
    }

    public static function emitEnsureInitialized(Context $context, Value $obj): void
    {
        LazyObjectHelperLlvm::emitEnsureInitialized($context, $obj);
    }
}
