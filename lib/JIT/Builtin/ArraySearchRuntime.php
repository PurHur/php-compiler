<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\ArraySearchLlvm;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * JIT/AOT for array_search() via {@see ArraySearchLlvm} (#12514, #27133).
 *
 * Emits the packed-table scan inline into the caller (not NestedJIT → VmArray::searchKey —
 * that call was an external stub returning silent null under thin standalone AOT; peer
 * InArray #27120 / ArraySum #24167).
 *
 * Host/VM SSOT remains {@see \PHPCompiler\ext\standard\VmArray::searchKey()} /
 * {@see \PHPCompiler\ext\standard\ArraySearchJitHelper}.
 * php-src: ext/standard/array.c — PHP_FUNCTION(array_search)
 */
final class ArraySearchRuntime
{
    public static function search(
        Context $context,
        JITVariable $needle,
        JITVariable $haystack,
        Value $strict
    ): Value {
        $needlePtr = JitValueBox::valuePtrFromVariable($context, $needle);
        $ht = ArrayBuiltinHelper::isNativeArray($haystack->type)
            ? ArrayBuiltinHelper::nativeListToHashTable($context, $haystack)
            : ArrayBuiltinHelper::loadHashTable($context, $haystack);

        return ArraySearchLlvm::searchKey($context, $needlePtr, $ht, $strict);
    }

    public static function ensureLinked(Context $context): void
    {
        // Inline emission in search() — nothing to pre-link (#27133).
        unset($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }
}
