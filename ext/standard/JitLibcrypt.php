<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\LibcryptRuntime;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/** LLVM invoke for __compiler_libcrypt() — LibcryptJitHelper PHP bridge (#9275). */
final class JitLibcrypt
{
    public static function invoke(Context $context, Value $key, Value $salt): Value
    {
        LibcryptRuntime::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__compiler_libcrypt'),
            $key,
            $salt
        );
    }
}
