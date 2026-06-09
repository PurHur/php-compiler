<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/**
 * JIT/AOT link for mb_check_encoding() UTF-8 validation — LLVM from StringUtf8ValidJit (#4571).
 */
final class StringUtf8Valid
{
    public static function ensureLinked(Context $context): void
    {
        StringUtf8ValidJit::implement($context);
    }

    public static function validFromPtr(Context $context, Value $strPtr): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__compiler_utf8_valid'),
            $strPtr
        );
    }
}
