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
        // Full ext/hash registry (issue #11463); distinct from hash_hmac_algos HMAC subset.
        $raw = $context->builder->call(
            $context->lookupFunction('__compiler_hash_algos')
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $raw
        );

        return $ptr;
    }
}
