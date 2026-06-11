<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ext\standard\JitStringSearch;
use PHPCompiler\JIT\Builtin\StringUtf8Valid;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT helpers for grapheme_str_contains() (issue #7128).
 *
 * php-src: ext/intl/grapheme/grapheme_string.c — grapheme cluster substring test.
 * VM: {@see VmGrapheme}; runtime LLVM mirrors VmGrapheme fallback (UTF-8 valid + search).
 */
final class JitGrapheme
{
    /**
     * @param JITVariable[] $args
     */
    public static function tryContainsFold(Context $context, array $args): ?Value
    {
        $hay = self::compileTimeString($args, 0);
        $needle = self::compileTimeString($args, 1);
        if (null === $hay || null === $needle) {
            return null;
        }

        return $context->constantFromBool(VmGrapheme::strContains($hay, $needle));
    }

    /**
     * @param JITVariable[] $args
     */
    public static function tryLevenshteinFold(Context $context, array $args): ?Value
    {
        $string1 = self::compileTimeString($args, 0);
        $string2 = self::compileTimeString($args, 1);
        if (null === $string1 || null === $string2) {
            return null;
        }

        return $context->constantFromInteger(
            VmGrapheme::levenshtein($string1, $string2),
            'int64'
        );
    }

    public static function contains(Context $context, Value $haystack, Value $needle): Value
    {
        $map = $context->structFieldMap['__string__'];
        $needleLen = $context->builder->load(
            $context->builder->structGep($needle, $map['length'])
        );
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $isEmptyNeedle = $context->builder->icmp(Builder::INT_EQ, $needleLen, $zero);
        $hayValid = StringUtf8Valid::validFromPtr($context, $haystack);
        $needleValid = StringUtf8Valid::validFromPtr($context, $needle);
        $i64 = $context->getTypeFromString('int64');
        $zeroI64 = $i64->constInt(0, false);
        $bothValid = $context->builder->and(
            $context->builder->icmp(Builder::INT_NE, $hayValid, $zeroI64),
            $context->builder->icmp(Builder::INT_NE, $needleValid, $zeroI64)
        );
        $found = JitStringSearch::contains($context, $haystack, $needle);

        return $context->builder->select(
            $isEmptyNeedle,
            $context->constantFromBool(true),
            $context->builder->select($bothValid, $found, $context->constantFromBool(false))
        );
    }

    /**
     * @param JITVariable[] $args
     */
    private static function compileTimeString(array $args, int $index): ?string
    {
        if (!isset($args[$index])) {
            return null;
        }
        if (JITVariable::TYPE_STRING !== $args[$index]->type) {
            return null;
        }

        return $args[$index]->compileTimeString ?? null;
    }
}
