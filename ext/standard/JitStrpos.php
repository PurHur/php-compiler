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
            return self::findCaseInsensitive($context, $haystack, $needle, $searchOffset, $i64);
        }

        return JitStringSearch::find($context, $haystack, $needle, $searchOffset);
    }

    private static function findCaseInsensitive(
        Context $context,
        Value $haystack,
        Value $needle,
        ?Value $searchOffset,
        \PHPLLVM\Type $i64
    ): Value {
        $map = $context->structFieldMap['__string__'];
        $hayPtr = $context->builder->structGep($haystack, $map['value']);
        $needlePtr = $context->builder->structGep($needle, $map['value']);
        $searchPtr = $hayPtr;
        if (null !== $searchOffset) {
            $searchPtr = $context->builder->inBoundsGEP($hayPtr, $searchOffset);
        }
        $found = $context->builder->call(
            $context->lookupFunction('strcasestr'),
            $searchPtr,
            $needlePtr
        );
        $null = $context->getTypeFromString('int8*')->constNull();
        $isNull = $context->builder->icmp(Builder::INT_EQ, $found, $null);
        $foundInt = $context->builder->ptrToInt($found, $i64);
        $baseInt = $context->builder->ptrToInt($hayPtr, $i64);
        $pos = $context->builder->sub($foundInt, $baseInt);
        $sentinel = $i64->constInt(self::NOT_FOUND, false);

        return $context->builder->select($isNull, $sentinel, $pos);
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
