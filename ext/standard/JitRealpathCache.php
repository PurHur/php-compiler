<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Value;

/**
 * LLVM lowering for realpath cache introspection (#3463, #27665).
 *
 * JIT/AOT realpath() uses libc directly without PHP cache bookkeeping — return empty snapshot.
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(realpath_cache_get)
 */
final class JitRealpathCache
{
    public static function size(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $i64 = $context->getTypeFromString('int64');
        $context->builder->call(
            $context->lookupFunction('__value__writeInt'),
            $ptr,
            $i64->constInt(0, false, true)
        );

        return $ptr;
    }

    public static function get(Context $context): Value
    {
        // Peer get_included_files / password_algos — box empty HT as __value__*
        // so FUNCCALL_EXEC_RETURN assign works under thin AOT (#27665).
        $ht = ArrayBuiltinHelper::emptyArray($context);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $ht
        );

        return $ptr;
    }
}
