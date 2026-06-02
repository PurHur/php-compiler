<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Value;

/** LLVM lowering for get_resource_type() via __compiler_get_resource_type (#3142). */
final class JitGetResourceType
{
    /** @return Value (string type name) */
    public static function invoke(Context $context, Value $handleLong): Value
    {
        $typeStr = $context->builder->call(
            $context->lookupFunction('__compiler_get_resource_type'),
            $handleLong
        );

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $typeStr
        );
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $owned);

        return $ptr;
    }
}
