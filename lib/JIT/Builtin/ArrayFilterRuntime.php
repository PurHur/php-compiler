<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\ArrayFilterCallbackPolicy;
use PHPCompiler\JIT\ArrayFilterLlvm;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedClosureInvokeLlvm;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * JIT/AOT link for array_filter() (#12370, #17852, #32672, #34897).
 *
 * No-callback / null-callback: pure {@see ArrayFilterLlvm::filterDefault} (NestedJIT helper
 * bridge SIGSEGVs under thin AOT — #34897). Closure callbacks: packed walk via
 * {@see ArrayFilterLlvm::filterPackedWithClosure}.
 * SSOT: {@see \PHPCompiler\ext\standard\array_filter}
 * php-src: ext/standard/array.c — php_array_filter()
 */
final class ArrayFilterRuntime
{
    public static function filterDefault(Context $context, JITVariable $array): Value
    {
        return ArrayFilterLlvm::filterDefault($context, self::argToHashtable($context, $array));
    }

    public static function filterWithClosure(
        Context $context,
        JITVariable $array,
        JITVariable $callback
    ): Value {
        if (!ArrayFilterCallbackPolicy::isClosureJitLowerable($callback)) {
            throw new \LogicException(ArrayFilterCallbackPolicy::jitRejectionMessage());
        }
        NestedClosureInvokeLlvm::ensureLinked($context);
        $ht = self::argToHashtable($context, $array);

        return ArrayFilterLlvm::filterPackedWithClosure($context, $ht, $callback);
    }

    private static function argToHashtable(Context $context, JITVariable $arg): Value
    {
        if (ArrayBuiltinHelper::isNativeArray($arg->type)) {
            return ArrayBuiltinHelper::nativeListToHashTable($context, $arg);
        }

        return ArrayBuiltinHelper::loadHashTable($context, $arg);
    }
}
