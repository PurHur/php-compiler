<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InArrayLlvm;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * JIT/AOT for in_array() via {@see InArrayLlvm} (#12503, #27120).
 *
 * Emits the packed-table scan inline into the caller (not NestedJIT → VmArray::contains —
 * that call was an external stub returning silent false under thin standalone AOT; peer
 * ArraySum #24167 / ArrayIsList foreach #20652).
 *
 * Host/VM SSOT remains {@see \PHPCompiler\ext\standard\VmArray::contains()} /
 * {@see \PHPCompiler\ext\standard\InArrayJitHelper}.
 * php-src: ext/standard/array.c — PHP_FUNCTION(in_array)
 */
final class InArrayRuntime
{
    public static function inArray(
        Context $context,
        JITVariable $needle,
        JITVariable $haystack,
        Value $strict
    ): Value {
        $needlePtr = JitValueBox::valuePtrFromVariable($context, $needle);
        $ht = ArrayBuiltinHelper::isNativeArray($haystack->type)
            ? ArrayBuiltinHelper::nativeListToHashTable($context, $haystack)
            : ArrayBuiltinHelper::loadHashTable($context, $haystack);

        return InArrayLlvm::contains($context, $needlePtr, $ht, $strict);
    }

    public static function ensureLinked(Context $context): void
    {
        // Inline emission in inArray() — nothing to pre-link (#27120).
        unset($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }
}
