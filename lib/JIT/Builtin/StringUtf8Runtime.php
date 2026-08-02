<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/**
 * JIT/AOT link for __compiler_utf8_strlen / __compiler_utf8_valid (#9246, #9273, #27051).
 *
 * Thin AOT cannot use NestedJIT Utf8JitHelper→VmString: that path treats {@see __string__*}
 * args as boxed {@see __value__*} and returns 0 (#27051). ABI bodies are LLVM walks on
 * {@see __string__*} ({@see StringUtf8StrlenJit} / {@see StringUtf8ValidJit}); PHP SSOT remains
 * {@see \PHPCompiler\ext\standard\VmString} / {@see \PHPCompiler\ext\standard\Utf8JitHelper}.
 * php-src: ext/standard/utf8.c, ext/mbstring/mbstring.c
 */
final class StringUtf8Runtime
{
    public static function ensureLinked(Context $context): void
    {
        $resume = null;
        try {
            $resume = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }
        self::implement($context);
        if (null !== $resume) {
            $context->builder->positionAtEnd($resume);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    public static function ensureStrlenLinked(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function ensureValidLinked(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function validFromPtr(Context $context, Value $strPtr): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__compiler_utf8_valid'),
            $strPtr
        );
    }

    public static function implement(Context $context): void
    {
        StringUtf8StrlenJit::implement($context);
        StringUtf8ValidJit::implement($context);
    }
}
