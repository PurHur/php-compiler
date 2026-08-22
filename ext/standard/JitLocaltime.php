<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\DefaultTimezoneCivilRuntime;
use PHPCompiler\JIT\Builtin\StringLocaltime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for localtime() — civil math + HT assemble in IR (#6812, #33952).
 *
 * NestedJIT / helper-runtime {@see LocaltimeJitHelper} verifies under thin AOT but the
 * linked HashTable* helper returns null (parentless `__compiler_time` was the prior
 * Module.php:180 mask when {@see StringLocaltime} cleared the insert block). Peer
 * {@see JitGetdate} / {@see JitIdate} (#26900).
 *
 * php-src: ext/standard/datetime.c — PHP_FUNCTION(localtime)
 */
final class JitLocaltime
{
    private const ASSOC_KEYS = [
        'tm_sec',
        'tm_min',
        'tm_hour',
        'tm_mday',
        'tm_mon',
        'tm_year',
        'tm_wday',
        'tm_yday',
        'tm_isdst',
    ];

    public static function invoke(Context $context, ?JITVariable $timestamp, Value $associative): Value
    {
        // No-op link kept for Type/String_ compatibility (#33952 / #26900).
        StringLocaltime::ensureLinked($context);

        $ts = null === $timestamp
            ? JitDate::time($context)
            : JitDateTimestampArg::lowerNullable(
                $context,
                $timestamp,
                'localtime',
                1,
                'timestamp',
                JitDate::time($context)
            );

        $parts = JitGetdate::civilPartsPublic($context, $ts);
        $i64 = $context->getTypeFromString('int64');
        $tmMon = $context->builder->sub($parts['month'], $i64->constInt(1, false));
        $tmYear = $context->builder->sub($parts['year'], $i64->constInt(1900, false));

        DefaultTimezoneCivilRuntime::ensureLinked($context);
        $isdst = $context->builder->call(
            $context->lookupFunction('__compiler_default_tz_is_dst'),
            $ts
        );

        $values = [
            $parts['second'],
            $parts['minute'],
            $parts['hour'],
            $parts['day'],
            $tmMon,
            $tmYear,
            $parts['wday'],
            $parts['yday'],
            $isdst,
        ];

        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $assocBb = BasicBlockHelper::append($context, 'lt_assoc');
        $numericBb = BasicBlockHelper::append($context, 'lt_numeric');
        $mergeBb = BasicBlockHelper::append($context, 'lt_merge');
        $context->builder->branchIf($associative, $assocBb, $numericBb);

        $context->builder->positionAtEnd($assocBb);
        foreach (self::ASSOC_KEYS as $i => $key) {
            self::setStringKeyLong($context, $ht, $key, $values[$i]);
        }
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($numericBb);
        $sizeT = $context->getTypeFromString('size_t');
        foreach ($values as $i => $long) {
            $context->builder->call(
                $context->lookupFunction('__hashtable__setLongAt'),
                $ht,
                $sizeT->constInt($i, false),
                $long
            );
        }
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($mergeBb);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $ht
        );

        return $ptr;
    }

    private static function setStringKeyLong(Context $context, Value $ht, string $key, Value $long): void
    {
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyLong'),
            $ht,
            $context->builder->load($context->constantStringFromString($key)),
            $long
        );
    }
}
