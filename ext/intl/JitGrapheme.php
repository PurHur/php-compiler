<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\JIT\Builtin\StringStrContains;
use PHPCompiler\JIT\Builtin\StringUtf8Runtime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
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
    public static function tryStrlenFold(Context $context, array $args): ?Value
    {
        $string = self::compileTimeString($args, 0);
        if (null === $string) {
            return null;
        }
        $result = VmGrapheme::strlen($string);
        if (false === $result) {
            return $context->getTypeFromString('bool')->constInt(0, false);
        }

        return $context->constantFromInteger($result, 'int64');
    }

    /**
     * @param JITVariable[] $args
     */
    public static function tryStrSplitFold(Context $context, array $args): ?Value
    {
        $string = self::compileTimeString($args, 0);
        if (null === $string) {
            return null;
        }
        $length = 1;
        if (isset($args[1])) {
            $lengthCt = self::compileTimeInt($args, 1);
            if (null === $lengthCt) {
                return null;
            }
            $length = $lengthCt;
        }
        $parts = VmGrapheme::strSplit($string, $length);
        if (false === $parts) {
            return $context->getTypeFromString('bool')->constInt(0, false);
        }
        $ht = self::hashTableFromStringList($parts);
        $cacheKey = 'grapheme_str_split_'.md5($string."\0".$length);

        return $context->constantArrayFromVmHashTable($cacheKey, $ht);
    }

    /**
     * @param JITVariable[] $args
     */
    public static function tryExtractFold(Context $context, array $args): ?Value
    {
        if (isset($args[4])) {
            return null;
        }
        $haystack = self::compileTimeString($args, 0);
        if (null === $haystack) {
            return null;
        }
        if (!isset($args[1])) {
            return null;
        }
        $size = self::compileTimeInt($args, 1);
        if (null === $size) {
            return null;
        }
        $extractType = VmGrapheme::EXTR_COUNT;
        if (isset($args[2])) {
            $extractTypeCt = self::compileTimeInt($args, 2);
            if (null === $extractTypeCt) {
                return null;
            }
            $extractType = $extractTypeCt;
        }
        $start = 0;
        if (isset($args[3])) {
            $startCt = self::compileTimeInt($args, 3);
            if (null === $startCt) {
                return null;
            }
            $start = $startCt;
        }
        $result = VmGrapheme::extract($haystack, $size, $extractType, $start);
        if (false === $result) {
            return $context->getTypeFromString('bool')->constInt(0, false);
        }

        return $context->builder->load($context->constantStringFromString($result));
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
        $ins = 1;
        $rep = 1;
        $del = 1;
        if (isset($args[2])) {
            $insCt = self::compileTimeInt($args, 2);
            if (null === $insCt) {
                return null;
            }
            $ins = $insCt;
        }
        if (isset($args[3])) {
            $repCt = self::compileTimeInt($args, 3);
            if (null === $repCt) {
                return null;
            }
            $rep = $repCt;
        }
        if (isset($args[4])) {
            $delCt = self::compileTimeInt($args, 4);
            if (null === $delCt) {
                return null;
            }
            $del = $delCt;
        }
        if (isset($args[5])) {
            // Locale must be a compile-time string for fold; value unused (canonical equality).
            if (null === self::compileTimeString($args, 5)) {
                return null;
            }
        }
        $result = VmGrapheme::levenshtein($string1, $string2, $ins, $rep, $del);
        if (false === $result) {
            return $context->getTypeFromString('bool')->constInt(0, false);
        }

        return $context->constantFromInteger($result, 'int64');
    }

    /**
     * @param JITVariable[] $args
     */
    public static function tryStrposFold(Context $context, array $args): ?Value
    {
        return self::tryPosFoldInternal($context, $args, static fn (string $h, string $n, int $o): int|false => VmGrapheme::strpos($h, $n, $o));
    }

    /**
     * @param JITVariable[] $args
     */
    public static function tryStrimwidthFold(Context $context, array $args): ?Value
    {
        $string = self::compileTimeString($args, 0);
        if (null === $string) {
            return null;
        }
        $start = self::compileTimeInt($args, 1);
        if (null === $start) {
            return null;
        }
        $width = self::compileTimeInt($args, 2);
        if (null === $width) {
            return null;
        }
        $encoding = null;
        if (isset($args[3])) {
            $encoding = self::compileTimeString($args, 3);
            if (null === $encoding) {
                return null;
            }
        }
        $result = VmGrapheme::strimwidth($string, $start, $width, $encoding);
        if (false === $result) {
            return $context->getTypeFromString('bool')->constInt(0, false);
        }

        return $context->builder->load($context->constantStringFromString($result));
    }

    /**
     * @param JITVariable[] $args
     */
    public static function trySubstrFold(Context $context, array $args): ?Value
    {
        $string = self::compileTimeString($args, 0);
        if (null === $string) {
            return null;
        }
        $start = self::compileTimeInt($args, 1);
        if (null === $start) {
            return null;
        }
        $length = null;
        if (isset($args[2])) {
            $length = self::compileTimeInt($args, 2);
            if (null === $length) {
                return null;
            }
        }
        $result = VmGrapheme::substr($string, $start, $length);
        if (false === $result) {
            return $context->getTypeFromString('bool')->constInt(0, false);
        }

        return $context->builder->load($context->constantStringFromString($result));
    }

    /**
     * @param JITVariable[] $args
     */
    public static function tryStriposFold(Context $context, array $args): ?Value
    {
        return self::tryPosFoldInternal($context, $args, static fn (string $h, string $n, int $o): int|false => VmGrapheme::stripos($h, $n, $o));
    }

    /**
     * @param JITVariable[] $args
     */
    public static function tryStrrposFold(Context $context, array $args): ?Value
    {
        return self::tryPosFoldInternal($context, $args, static fn (string $h, string $n, int $o): int|false => VmGrapheme::strrpos($h, $n, $o));
    }

    /**
     * @param JITVariable[] $args
     */
    public static function tryStrriposFold(Context $context, array $args): ?Value
    {
        return self::tryPosFoldInternal($context, $args, static fn (string $h, string $n, int $o): int|false => VmGrapheme::strripos($h, $n, $o));
    }

    /**
     * @param JITVariable[] $args
     * @param callable(string, string, int): (int|false) $search
     */
    private static function tryPosFoldInternal(Context $context, array $args, callable $search): ?Value
    {
        $hay = self::compileTimeString($args, 0);
        $needle = self::compileTimeString($args, 1);
        if (null === $hay || null === $needle) {
            return null;
        }
        $offset = self::compileTimeInt($args, 2);
        if (null === $offset) {
            return null;
        }
        try {
            $result = $search($hay, $needle, $offset);
        } catch (\ValueError $e) {
            // Invalid constant offset — leave to runtime (php-src-strict ValueError).
            return null;
        }
        if (false === $result) {
            return $context->getTypeFromString('bool')->constInt(0, false);
        }

        return $context->constantFromInteger($result, 'int64');
    }

    /**
     * @param JITVariable[] $args
     */
    public static function tryStrstrFold(Context $context, array $args, bool $caseInsensitive): ?Value
    {
        $hay = self::compileTimeString($args, 0);
        $needle = self::compileTimeString($args, 1);
        if (null === $hay || null === $needle) {
            return null;
        }
        $beforeNeedle = self::compileTimeBool($args, 2);
        if (null === $beforeNeedle) {
            return null;
        }
        $result = $caseInsensitive
            ? VmGrapheme::stristr($hay, $needle, $beforeNeedle)
            : VmGrapheme::strstr($hay, $needle, $beforeNeedle);
        if (false === $result) {
            return $context->getTypeFromString('bool')->constInt(0, false);
        }

        return $context->builder->load($context->constantStringFromString($result));
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
        $hayValid = StringUtf8Runtime::validFromPtr($context, $haystack);
        $needleValid = StringUtf8Runtime::validFromPtr($context, $needle);
        $i64 = $context->getTypeFromString('int64');
        $zeroI64 = $i64->constInt(0, false);
        $bothValid = $context->builder->and(
            $context->builder->icmp(Builder::INT_NE, $hayValid, $zeroI64),
            $context->builder->icmp(Builder::INT_NE, $needleValid, $zeroI64)
        );
        $found = StringStrContains::invokeContains($context, $haystack, $needle);

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

    /**
     * @param JITVariable[] $args
     */
    private static function compileTimeInt(array $args, int $index): ?int
    {
        if (!isset($args[$index])) {
            return 0;
        }
        $arg = $args[$index];
        if (JITVariable::TYPE_NATIVE_LONG !== $arg->type || JITVariable::KIND_VALUE !== $arg->kind) {
            return null;
        }
        $const = $arg->value;
        if ($const instanceof Value && $const->isConstant()) {
            return (int) $const->constInt();
        }

        return null;
    }

    /**
     * @param JITVariable[] $args
     */
    private static function compileTimeBool(array $args, int $index): ?bool
    {
        if (!isset($args[$index])) {
            return false;
        }
        if (JITVariable::TYPE_NATIVE_BOOL !== $args[$index]->type) {
            return null;
        }

        return (bool) ($args[$index]->compileTimeBool ?? false);
    }

    /**
     * @param list<string> $parts
     */
    private static function hashTableFromStringList(array $parts): HashTable
    {
        $ht = new HashTable();
        foreach ($parts as $part) {
            $stored = new Variable();
            $stored->string($part);
            $ht->append($stored);
        }

        return $ht;
    }
}
