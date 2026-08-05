<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Value;

/**
 * LLVM lowering for realpath cache introspection (#3463, #27664, #27665).
 *
 * JIT/AOT realpath() uses libc directly without PHP cache bookkeeping — return empty snapshot.
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(realpath_cache_get/size)
 */
final class JitRealpathCache
{
    public static function size(Context $context): Value
    {
        // Empty-snapshot size is 0 — JIT/AOT realpath() does not bookkeep a PHP cache (#3463, #27664).
        // Use __value__writeLong (there is no __value__writeInt).
        $slot = JitValueBox::alloc($context);
        $i64 = $context->getTypeFromString('int64');
        JitValueBox::writeLong($context, $slot, $i64->constInt(0, false));

        return JitValueBox::pointer($context, $slot);
    }

    public static function get(Context $context): Value
    {
        return ArrayBuiltinHelper::emptyArray($context);
    }
}
