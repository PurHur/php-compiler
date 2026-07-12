<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringUtf8Latin1;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT helper for utf8_encode()/utf8_decode() — Utf8Latin1JitHelper PHP bridge (#9912).
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(utf8_encode/utf8_decode)
 */
final class JitUtf8Latin1
{
    public static function encode(Context $context, Value $src): Value
    {
        StringUtf8Latin1::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__compiler_utf8_encode'),
            $src
        );
    }

    public static function decode(Context $context, Value $src): Value
    {
        StringUtf8Latin1::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__compiler_utf8_decode'),
            $src
        );
    }
}
