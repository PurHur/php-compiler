<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\DefaultTimezoneCivilRuntime;
use PHPCompiler\JIT\Builtin\StringGettimeofday;
use PHPCompiler\JIT\Builtin\StringMicrotime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for gettimeofday() (#3208).
 *
 * Float and array paths assemble results in IR from {@see StringMicrotime::invokeFloat}
 * and {@see JitDate::time} — NestedJIT {@see GettimeofdayJitHelper} recurses into user
 * gettimeofday() under thin AOT (peer {@see JitGetdate} / #26900).
 *
 * php-src: ext/standard/microtime.c — PHP_FUNCTION(gettimeofday)
 */
final class JitGettimeofday
{
    public static function call(Context $context, Value $asFloat): Value
    {
        StringGettimeofday::ensureLinked($context); // #32683 — Type always-on gettimeofday ABI dropped

        $slot = JitValueBox::alloc($context);
        $slotPtr = JitValueBox::pointer($context, $slot);
        $isFloat = $context->builder->icmp(
            Builder::INT_NE,
            $asFloat,
            $context->constantFromBool(false)
        );
        $floatBb = BasicBlockHelper::append($context, 'gettimeofday_float');
        $arrayBb = BasicBlockHelper::append($context, 'gettimeofday_array');
        $mergeBb = BasicBlockHelper::append($context, 'gettimeofday_merge');
        $context->builder->branchIf($isFloat, $floatBb, $arrayBb);

        $context->builder->positionAtEnd($floatBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeDouble'),
            $slotPtr,
            self::buildFloatValue($context)
        );
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($arrayBb);
        $ht = self::buildArrayHashtable($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $slotPtr,
            $ht
        );
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($mergeBb);

        return $slotPtr;
    }

    private static function buildFloatValue(Context $context): Value
    {
        StringMicrotime::ensureLinked($context);

        return StringMicrotime::invokeFloat($context);
    }

    private static function buildArrayHashtable(Context $context): Value
    {
        DefaultTimezoneCivilRuntime::ensureLinked($context);
        StringMicrotime::ensureLinked($context);

        $doubleTy = $context->getTypeFromString('double');
        $i64 = $context->getTypeFromString('int64');
        $micro = StringMicrotime::invokeFloat($context);
        $sec = JitDate::time($context);
        $secAsDouble = $context->builder->sitofp($sec, $doubleTy);
        $frac = $context->builder->fsub($micro, $secAsDouble);
        $usec = $context->builder->fptosi(
            $context->builder->fmul($frac, $doubleTy->constReal(1_000_000.0)),
            $i64
        );

        $localCivil = $context->builder->call(
            $context->lookupFunction('__compiler_default_tz_civil_timestamp'),
            $sec
        );
        $offsetSec = $context->builder->sub($localCivil, $sec);
        $minuteswest = $context->builder->signedDiv(
            $context->builder->sub($i64->constInt(0, false), $offsetSec),
            $i64->constInt(60, false)
        );
        $dsttime = $context->builder->call(
            $context->lookupFunction('__compiler_default_tz_is_dst'),
            $sec
        );

        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        self::setLong($context, $ht, 'sec', $sec);
        self::setLong($context, $ht, 'usec', $usec);
        self::setLong($context, $ht, 'minuteswest', $minuteswest);
        self::setLong($context, $ht, 'dsttime', $dsttime);

        return $ht;
    }

    private static function setLong(Context $context, Value $ht, string $key, Value $long): void
    {
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyLong'),
            $ht,
            $context->builder->load($context->constantStringFromString($key)),
            $long
        );
    }
}
