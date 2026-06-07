<?php

declare(strict_types=1);

namespace PHPCompiler\ext\hash;

use PHPCompiler\JIT\Builtin\StringHashCrypto;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Value;

/** LLVM lowering for hash_algos() (#6937). */
final class JitHashAlgos
{
    public static function invoke(Context $context): Value
    {
        StringHashCrypto::ensureLinked($context);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        // Same digest set as hash_hmac_algos() for VmHashNative-supported algorithms.
        $raw = $context->builder->call(
            $context->lookupFunction('__compiler_hash_hmac_algos')
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $raw
        );

        return $ptr;
    }
}
