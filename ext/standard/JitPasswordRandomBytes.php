<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\PasswordRandomBytesRuntime;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/** LLVM invoke for __compiler_password_random_bytes() (#9275). */
final class JitPasswordRandomBytes
{
    public static function generate(Context $context, Value $lengthI64): Value
    {
        PasswordRandomBytesRuntime::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__compiler_password_random_bytes'),
            $lengthI64
        );
    }
}
