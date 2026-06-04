<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM implementation of __compiler_hrtime_ns / __compiler_hrtime_pair.
 *
 * Mirrors ext/standard/VmHrtime.php (issue #5634, #3195).
 * php-src: ext/standard/hrtime.c — clock_gettime(CLOCK_MONOTONIC)
 */
final class StringHrtime
{
    private const TIMESPEC_SIZE = 16;

    private const TIMESPEC_OFF_TV_SEC = 0;

    private const TIMESPEC_OFF_TV_NSEC = 8;

    private const NS_PER_SEC = 1_000_000_000;

    /** Linux CLOCK_MONOTONIC; matches VmHrtime::CLOCK_MONOTONIC_LINUX. */
    private const CLOCK_MONOTONIC = 1;

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $nsProbe = $context->module->getNamedFunction('__compiler_hrtime_ns');
        if (null !== $nsProbe && $nsProbe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureLibcClock($context);

        $i64 = $context->getTypeFromString('int64');
        $htPtr = $context->getTypeFromString('__hashtable__*');

        $ftNs = $context->context->functionType($i64, false);
        $fnNs = null !== $nsProbe
            ? $nsProbe
            : $context->module->addFunction('__compiler_hrtime_ns', $ftNs);
        self::implementHrtimeNs($context, $fnNs);

        $pairProbe = $context->module->getNamedFunction('__compiler_hrtime_pair');
        $ftPair = $context->context->functionType($htPtr, false);
        $fnPair = null !== $pairProbe
            ? $pairProbe
            : $context->module->addFunction('__compiler_hrtime_pair', $ftPair);
        self::implementHrtimePair($context, $fnPair);

        self::registerLinkedRuntime($context);
    }

    private static function implementHrtimeNs(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('hr_ns_entry');
        $context->builder->positionAtEnd($entry);

        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        [$sec, $nsec, $ok] = self::readMonotonic($context, $fn);

        $failBb = $fn->appendBasicBlock('hr_ns_fail');
        $calcBb = $fn->appendBasicBlock('hr_ns_calc');
        $context->builder->branchIf($ok, $calcBb, $failBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($zero);
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($calcBb);
        $nsPerSec = $i64->constInt(self::NS_PER_SEC, false);
        $total = $context->builder->add(
            $context->builder->mul($sec, $nsPerSec),
            $nsec
        );
        $context->builder->returnValue($total);
        $context->builder->clearInsertionPosition();
    }

    private static function implementHrtimePair(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('hr_pair_entry');
        $context->builder->positionAtEnd($entry);

        $i64 = $context->getTypeFromString('int64');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $i64->constInt(0, false);
        $idx0 = $sizeT->constInt(0, false);
        $idx1 = $sizeT->constInt(1, false);

        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $nullHt = $htPtr->constNull();
        $allocFailBb = $fn->appendBasicBlock('hr_pair_alloc_fail');
        $fillBb = $fn->appendBasicBlock('hr_pair_fill');
        $htNull = $context->builder->icmp(Builder::INT_EQ, $ht, $nullHt);
        $context->builder->branchIf($htNull, $allocFailBb, $fillBb);

        $context->builder->positionAtEnd($allocFailBb);
        $context->builder->returnValue($nullHt);
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($fillBb);
        [$sec, $nsec, $ok] = self::readMonotonic($context, $fn);
        $setLong = $context->lookupFunction('__hashtable__setLongAt');
        $secVal = $context->builder->select($ok, $sec, $zero);
        $nsecVal = $context->builder->select($ok, $nsec, $zero);
        $context->builder->call($setLong, $ht, $idx0, $secVal);
        $context->builder->call($setLong, $ht, $idx1, $nsecVal);
        $context->builder->returnValue($ht);
        $context->builder->clearInsertionPosition();
    }

    /**
     * @return array{0: Value, 1: Value, 2: Value} sec, nsec, ok (i1)
     */
    private static function readMonotonic(Context $context, LlvmFunction $fn): array
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $zeroI32 = $i32->constInt(0, false);
        $clockId = $i32->constInt(self::CLOCK_MONOTONIC, false);

        $ts = $context->builder->alloca($i8, self::TIMESPEC_SIZE, 'hr_ts');
        $tsPtr = $context->builder->pointerCast($ts, $i8p);
        $cgRet = $context->builder->call(
            $context->lookupFunction('clock_gettime'),
            $clockId,
            $tsPtr
        );
        $ok = $context->builder->icmp(Builder::INT_EQ, $cgRet, $zeroI32);
        $sec = self::loadI64At($context, $ts, self::TIMESPEC_OFF_TV_SEC);
        $nsec = self::loadI64At($context, $ts, self::TIMESPEC_OFF_TV_NSEC);

        return [$sec, $nsec, $ok];
    }

    private static function loadI64At(Context $context, Value $base, int $offset): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $ptr = $context->builder->gep($base, $i8->constInt($offset, false));
        $slot = $context->builder->pointerCast($ptr, $i64->pointerType(0));

        return $context->builder->load($slot);
    }

    private static function ensureLibcClock(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');

        self::ensureExternal(
            $context,
            'clock_gettime',
            $context->context->functionType($i32, false, $i32, $i8p)
        );
    }

    private static function ensureExternal(Context $context, string $name, $ft): void
    {
        try {
            $context->lookupFunction($name);
        } catch (\Throwable $e) {
            $fn = $context->module->addFunction($name, $ft);
            $context->registerFunction($name, $fn);
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (['__compiler_hrtime_ns', '__compiler_hrtime_pair'] as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringHrtime LLVM implement');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
