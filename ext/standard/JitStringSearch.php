<?php

declare(strict_types=1);

/**
 * Binary-safe substring search for JIT/AOT — mirrors VmString (#4146, #15287).
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringFindSubstr;
use PHPCompiler\JIT\Builtin\StringStrpos;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class JitStringSearch
{
    public const NOT_FOUND = -1;

    public static function ensureLinked(Context $context): void
    {
        StringFindSubstr::ensureLinked($context);
    }

    public static function ensureCiLinked(Context $context): void
    {
        StringFindSubstr::ensureCiLinked($context);
    }

    /**
     * Raw byte offset as i32, or {@see self::NOT_FOUND} (-1) when absent.
     */
    public static function findOffsetI32(
        Context $context,
        Value $haystack,
        Value $needle,
        ?Value $offset = null,
        bool $caseInsensitive = false
    ): Value {
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $off = null === $offset
            ? $i64->constInt(0, false)
            : $context->builder->zExt($context->builder->trunc($offset, $i32), $i64);

        return StringFindSubstr::invokeFindOffsetI32($context, $haystack, $needle, $off, $caseInsensitive);
    }

    /**
     * strpos()/stripos() — byte offset or NOT_FOUND (-1 miss sentinel).
     */
    public static function find(
        Context $context,
        Value $haystack,
        Value $needle,
        ?Value $offset = null,
        bool $caseInsensitive = false
    ): Value {
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $found = self::findOffsetI32($context, $haystack, $needle, $offset, $caseInsensitive);
        $notFound = $context->builder->icmp(
            Builder::INT_EQ,
            $found,
            $i32->constInt(self::NOT_FOUND, true)
        );
        $pos = $context->builder->zExt($found, $i64);
        $sentinel = $i64->constInt(StringStrpos::NOT_FOUND, false);

        return $context->builder->select($notFound, $sentinel, $pos);
    }
}
