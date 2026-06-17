<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/**
 * JIT/AOT link for mb_check_encoding() UTF-8 validation — Utf8JitHelper (#4571, #9246).
 */
final class StringUtf8Valid
{
    public static function ensureLinked(Context $context): void
    {
        StringUtf8Runtime::ensureValidLinked($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        StringUtf8Runtime::ensureValidLinked($context);
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
