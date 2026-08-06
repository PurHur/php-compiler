<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\ArrayUserSetOpsKeyLlvm;
use PHPCompiler\JIT\ArrayUserSetOpsUassocLlvm;
use PHPCompiler\JIT\ArrayUserSetOpsValueLlvm;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\UsortCallbackPolicy;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * JIT/AOT link for array_udiff()/array_uintersect()/array_diff_ukey()/array_intersect_ukey()/uassoc (#18515, #27228, #27243, #27533).
 *
 * Value / key / dual comparators: pure LLVM {@see ArrayUserSetOpsValueLlvm} /
 * {@see ArrayUserSetOpsKeyLlvm} / {@see ArrayUserSetOpsUassocLlvm} — NestedJIT of the PHP
 * helper aborts under thin AOT (cross-HT NestedClosureInvoke — #26976 / #27533).
 *
 * SSOT: {@see \PHPCompiler\ext\standard\VmArrayUserSetOps}
 * php-src: ext/standard/array.c
 */
final class ArrayUserSetOpsRuntime
{
    public static function diffByValue(
        Context $context,
        bool $intersect,
        JITVariable $callback,
        JITVariable $first,
        JITVariable ...$others
    ): Value {
        return self::filterByValue($context, $intersect, $callback, $first, ...$others);
    }

    public static function diffByKey(
        Context $context,
        JITVariable $callback,
        JITVariable $first,
        JITVariable ...$others
    ): Value {
        return self::filterByKey($context, false, $callback, $first, ...$others);
    }

    public static function intersectByKey(
        Context $context,
        JITVariable $callback,
        JITVariable $first,
        JITVariable ...$others
    ): Value {
        return self::filterByKey($context, true, $callback, $first, ...$others);
    }

    public static function diffByKeyValue(
        Context $context,
        JITVariable $valueCallback,
        JITVariable $keyCallback,
        JITVariable $first,
        JITVariable ...$others
    ): Value {
        return self::filterByKeyValue($context, false, $valueCallback, $keyCallback, $first, ...$others);
    }

    public static function intersectByKeyValue(
        Context $context,
        JITVariable $valueCallback,
        JITVariable $keyCallback,
        JITVariable $first,
        JITVariable ...$others
    ): Value {
        return self::filterByKeyValue($context, true, $valueCallback, $keyCallback, $first, ...$others);
    }

    private static function filterByValue(
        Context $context,
        bool $intersect,
        JITVariable $callback,
        JITVariable $first,
        JITVariable ...$others
    ): Value {
        self::requireClosureCallback($context, $callback);
        $src = self::argToHashtable($context, $first);
        $packed = self::packOtherHashTables($context, $others);

        return ArrayUserSetOpsValueLlvm::filterByValue(
            $context,
            $intersect,
            $src,
            HashTableHelper::loadHashtablePointer($context, $packed)
        );
    }

    private static function filterByKeyValue(
        Context $context,
        bool $intersect,
        JITVariable $valueCallback,
        JITVariable $keyCallback,
        JITVariable $first,
        JITVariable ...$others
    ): Value {
        self::requireClosureCallback($context, $valueCallback);
        self::requireClosureCallback($context, $keyCallback);
        $src = self::argToHashtable($context, $first);
        $packed = self::packOtherHashTables($context, $others);

        return ArrayUserSetOpsUassocLlvm::filterByKeyValue(
            $context,
            $intersect,
            $src,
            HashTableHelper::loadHashtablePointer($context, $packed)
        );
    }

    private static function filterByKey(
        Context $context,
        bool $intersect,
        JITVariable $callback,
        JITVariable $first,
        JITVariable ...$others
    ): Value {
        self::requireClosureCallback($context, $callback);
        $src = self::argToHashtable($context, $first);
        $packed = self::packOtherHashTables($context, $others);

        return ArrayUserSetOpsKeyLlvm::filterByKey(
            $context,
            $intersect,
            $src,
            HashTableHelper::loadHashtablePointer($context, $packed)
        );
    }

    public static function ensureLinked(Context $context): void
    {
        // Pure LLVM paths — no NestedJIT bridge (#27533).
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        // Pure LLVM paths — no NestedJIT bridge (#27533).
    }

    private static function requireClosureCallback(Context $context, JITVariable $callback): void
    {
        if (!UsortCallbackPolicy::isClosureJitLowerable($callback)) {
            throw new \LogicException(UsortCallbackPolicy::jitRejectionMessage());
        }
    }

    /**
     * @param list<JITVariable> $others
     */
    private static function packOtherHashTables(Context $context, array $others): JITVariable
    {
        $vars = [];
        foreach ($others as $other) {
            $vars[] = new JITVariable(
                $context,
                JITVariable::TYPE_HASHTABLE,
                JITVariable::KIND_VALUE,
                self::argToHashtable($context, $other)
            );
        }

        return HashTableHelper::packVariables($context, $vars);
    }

    private static function argToHashtable(Context $context, JITVariable $arg): Value
    {
        if (ArrayBuiltinHelper::isNativeArray($arg->type)) {
            return ArrayBuiltinHelper::nativeListToHashTable($context, $arg);
        }

        return ArrayBuiltinHelper::loadHashTable($context, $arg);
    }
}
