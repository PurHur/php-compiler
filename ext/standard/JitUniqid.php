<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringUniqid;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Value;

/** LLVM lowering for uniqid() via __compiler_uniqid (issue #2219). */
final class JitUniqid
{
    public static function invoke(Context $context, Value $prefixStr, Value $moreEntropy): Value
    {
        StringUniqid::ensureLinked($context);

        $i8 = $context->getTypeFromString('int8');
        $entropyI8 = $context->builder->zExt($moreEntropy, $i8);
        $raw = $context->builder->call(
            $context->lookupFunction('__compiler_uniqid'),
            $prefixStr,
            $entropyI8
        );
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $raw
        );

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $owned);

        return $ptr;
    }
}
