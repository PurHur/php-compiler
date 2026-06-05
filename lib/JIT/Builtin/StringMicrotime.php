<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM implementation of __compiler_microtime_string / __compiler_microtime_float.
 *
 * Mirrors ext/standard/VmDate::microtime() (issue #6110, #5045/#2186).
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(microtime)
 */
final class StringMicrotime
{
    private const TIMEVAL_SIZE = 16;

    private const TIMEVAL_OFF_TV_SEC = 0;

    private const TIMEVAL_OFF_TV_USEC = 8;

    private const USEC_PER_SEC = 1_000_000;

    private const SNPRINTF_BUF = 64;

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $strProbe = $context->module->getNamedFunction('__compiler_microtime_string');
        if (null !== $strProbe && $strProbe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureLibcGettimeofday($context);
        self::ensureStringHelpers($context);

        $strPtr = $context->getTypeFromString('__string__*');
        $double = $context->getTypeFromString('double');

        $ftStr = $context->context->functionType($strPtr, false);
        $fnStr = null !== $strProbe
            ? $strProbe
            : $context->module->addFunction('__compiler_microtime_string', $ftStr);
        self::implementMicrotimeString($context, $fnStr);

        $floatProbe = $context->module->getNamedFunction('__compiler_microtime_float');
        $ftFloat = $context->context->functionType($double, false);
        $fnFloat = null !== $floatProbe
            ? $floatProbe
            : $context->module->addFunction('__compiler_microtime_float', $ftFloat);
        self::implementMicrotimeFloat($context, $fnFloat);

        self::registerLinkedRuntime($context);
    }

    private static function implementMicrotimeFloat(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('mt_float_entry');
        $context->builder->positionAtEnd($entry);

        $double = $context->getTypeFromString('double');
        $zero = $double->constReal(0.0);
        [$sec, $usec, $ok] = self::readWallClock($context);

        $failBb = $fn->appendBasicBlock('mt_float_fail');
        $calcBb = $fn->appendBasicBlock('mt_float_calc');
        $context->builder->branchIf($ok, $calcBb, $failBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($zero);
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($calcBb);
        $context->builder->returnValue(self::wallClockToDouble($context, $sec, $usec));
        $context->builder->clearInsertionPosition();
    }

    private static function implementMicrotimeString(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('mt_str_entry');
        $context->builder->positionAtEnd($entry);

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $charPtr = $context->getTypeFromString('char*');
        $sizeT = $context->getTypeFromString('size_t');
        $zeroStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(1, false),
            $context->builder->pointerCast($context->constantFromString('0'), $charPtr)
        );

        [$sec, $usec, $ok] = self::readWallClock($context);
        $failBb = $fn->appendBasicBlock('mt_str_fail');
        $fmtBb = $fn->appendBasicBlock('mt_str_fmt');
        $context->builder->branchIf($ok, $fmtBb, $failBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($zeroStr);
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($fmtBb);
        $buf = $context->builder->alloca($i8, self::SNPRINTF_BUF, 'mt_buf');
        $bufChar = $context->builder->pointerCast($buf, $charPtr);
        $frac = self::usecFraction($context, $usec);
        $secI64 = $context->builder->zExt($sec, $i64);
        $fmtPtr = $context->builder->pointerCast(
            $context->constantFromString('%.8f %ld'),
            $charPtr
        );
        $written = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $bufChar,
            $sizeT->constInt(self::SNPRINTF_BUF, false),
            $fmtPtr,
            $frac,
            $secI64
        );
        $len = $context->builder->zExt($written, $i64);
        $result = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $len,
            $bufChar
        );
        $context->builder->returnValue($result);
        $context->builder->clearInsertionPosition();
    }

    private static function wallClockToDouble(Context $context, Value $sec, Value $usec): Value
    {
        $double = $context->getTypeFromString('double');
        $i64 = $context->getTypeFromString('int64');
        $usecPerSec = $i64->constInt(self::USEC_PER_SEC, false);
        $secD = $context->builder->sitofp($context->builder->zExt($sec, $i64), $double);
        $usecD = $context->builder->sitofp($context->builder->zExt($usec, $i64), $double);
        $divisor = $context->builder->sitofp($usecPerSec, $double);

        return $context->builder->fAdd($secD, $context->builder->fDiv($usecD, $divisor));
    }

    private static function usecFraction(Context $context, Value $usec): Value
    {
        $double = $context->getTypeFromString('double');
        $i64 = $context->getTypeFromString('int64');
        $usecPerSec = $i64->constInt(self::USEC_PER_SEC, false);
        $usecD = $context->builder->sitofp($context->builder->zExt($usec, $i64), $double);
        $divisor = $context->builder->sitofp($usecPerSec, $double);

        return $context->builder->fDiv($usecD, $divisor);
    }

    /**
     * @return array{0: Value, 1: Value, 2: Value} tv_sec, tv_usec (i32), ok (i1)
     */
    private static function readWallClock(Context $context): array
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $zeroI32 = $i32->constInt(0, false);
        $zero64 = $i64->constInt(0, false);

        $tv = $context->builder->alloca($i8, self::TIMEVAL_SIZE, 'mt_tv');
        $tvPtr = $context->builder->pointerCast($tv, $i8p);
        $status = $context->builder->call(
            $context->lookupFunction('gettimeofday'),
            $tvPtr,
            $i8p->constNull()
        );
        $ok = $context->builder->icmp(Builder::INT_EQ, $status, $zeroI32);
        $secRaw = self::loadI64At($context, $tv, self::TIMEVAL_OFF_TV_SEC);
        $usecRaw = self::loadI64At($context, $tv, self::TIMEVAL_OFF_TV_USEC);
        $sec = $context->builder->truncOrBitCast(
            $context->builder->select($ok, $secRaw, $zero64),
            $i32
        );
        $usec = $context->builder->truncOrBitCast(
            $context->builder->select($ok, $usecRaw, $zero64),
            $i32
        );

        return [$sec, $usec, $ok];
    }

    private static function loadI64At(Context $context, Value $base, int $offset): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $ptr = $context->builder->gep($base, $i8->constInt($offset, false));
        $slot = $context->builder->pointerCast($ptr, $i64->pointerType(0));

        return $context->builder->load($slot);
    }

    private static function ensureLibcGettimeofday(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');

        self::ensureExternal(
            $context,
            'gettimeofday',
            $context->context->functionType($i32, false, $i8p, $i8p)
        );
    }

    private static function ensureStringHelpers(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $charPtr = $context->getTypeFromString('char*');
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');

        foreach ([
            ['__string__init', $strPtr, [$i64, $charPtr]],
            ['snprintf', $i32, [$charPtr, $sizeT, $charPtr], true],
        ] as $spec) {
            $variadic = $spec[3] ?? false;
            self::ensureExternal(
                $context,
                $spec[0],
                $context->context->functionType($spec[1], $variadic, ...$spec[2])
            );
        }
    }

    private static function ensureExternal(Context $context, string $name, $ft): void
    {
        try {
            $context->lookupFunction($name);
        } catch (\Throwable) {
            $fn = $context->module->addFunction($name, $ft);
            $context->registerFunction($name, $fn);
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (['__compiler_microtime_string', '__compiler_microtime_float'] as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringMicrotime LLVM implement');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
