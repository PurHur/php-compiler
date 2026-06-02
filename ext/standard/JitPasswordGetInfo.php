<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringPasswordCrypto;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Value;

/** LLVM lowering for password_get_info() (#3649). */
final class JitPasswordGetInfo
{
    public static function invoke(Context $context, Value $hash): Value
    {
        StringPasswordCrypto::ensureLinked($context);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $raw = $context->builder->call(
            $context->lookupFunction('__compiler_password_get_info'),
            $hash
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $raw
        );

        return $ptr;
    }
}
