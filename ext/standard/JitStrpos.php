<?php

declare(strict_types=1);

/**
 * LLVM JIT helper for strpos() — binary-safe search via JitStringSearch (#4146).
 *
 * Not found is represented as 0 (native long). VM mode returns boolean false instead.
 * JIT tests use == false for not-found checks (boxed long 0).
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class JitStrpos
{
    public const NOT_FOUND = 0;

    public static function find(
        Context $context,
        Value $haystack,
        Value $needle,
        ?Value $offset = null,
        bool $caseInsensitive = false
    ): Value {
        $map = $context->structFieldMap['__string__'];
        $hayLen = $context->builder->load(
            $context->builder->structGep($haystack, $map['length'])
        );
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $searchOffset = null;
        if (null !== $offset) {
            $searchOffset = self::normalizeSearchOffset($context, $offset, $zero, $hayLen);
        }

        if ($caseInsensitive) {
            return JitStringSearch::find($context, $haystack, $needle, $searchOffset, true);
        }

        return JitStringSearch::find($context, $haystack, $needle, $searchOffset);
    }

    /**
     * Zend strpos offset: negative counts from end, then clamp for safe GEP (php-src string.c).
     */
    private static function normalizeSearchOffset(Context $context, Value $index, Value $min, Value $max): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $isNegative = $context->builder->icmp(Builder::INT_SLT, $index, $zero);
        $maxI64 = $context->builder->zExt($max, $i64);
        $negAdjusted = $context->builder->add($maxI64, $index);
        $adjusted = $context->builder->select($isNegative, $negAdjusted, $index);

        return self::minValue($context, self::maxValue($context, $adjusted, $min), $maxI64);
    }

    private static function minValue(Context $context, Value $a, Value $b): Value
    {
        $cmp = $context->builder->icmp(Builder::INT_SLT, $a, $b);

        return $context->builder->select($cmp, $a, $b);
    }

    private static function maxValue(Context $context, Value $a, Value $b): Value
    {
        $cmp = $context->builder->icmp(Builder::INT_SGT, $a, $b);

        return $context->builder->select($cmp, $a, $b);
    }
}
