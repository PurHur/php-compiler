<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM implementation of __compiler_hrtime_ns / __compiler_hrtime_pair.
 *
 * Mirrors ext/standard/VmHrtimeNative (/proc/uptime monotonic read; issue #7315, #9018).
 * php-src: ext/standard/hrtime.c
 */
final class StringHrtime
{
    private const NS_PER_SEC = 1_000_000_000;

    private const READ_MONOTONIC = '__phpc_hrtime_monotonic_read';

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

        self::ensureMonotonicRead($context);

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
        [$sec, $nsec, $ok] = self::readMonotonic($context);

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
        [$sec, $nsec, $ok] = self::readMonotonic($context);
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
    private static function readMonotonic(Context $context): array
    {
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i64p = $i64->pointerType(0);
        $zeroI32 = $i32->constInt(0, false);

        $secSlot = $context->builder->alloca($i64, 1, 'hr_sec_out');
        $nsecSlot = $context->builder->alloca($i64, 1, 'hr_nsec_out');
        $ret = $context->builder->call(
            $context->lookupFunction(self::READ_MONOTONIC),
            $context->builder->pointerCast($secSlot, $i64p),
            $context->builder->pointerCast($nsecSlot, $i64p)
        );
        $ok = $context->builder->icmp(Builder::INT_EQ, $ret, $zeroI32);
        $sec = $context->builder->load($secSlot);
        $nsec = $context->builder->load($nsecSlot);

        return [$sec, $nsec, $ok];
    }

    private static function ensureMonotonicRead(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::READ_MONOTONIC);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::READ_MONOTONIC, $probe);

            return;
        }

        self::ensureLibcForUptime($context);
        self::emitMonotonicRead($context);
    }

    private static function emitMonotonicRead(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i8pp = $context->getTypeFromString('int8**');
        $i64p = $i64->pointerType(0);
        $dbl = $context->getTypeFromString('double');
        $sizeT = $context->getTypeFromString('size_t');
        $zeroI32 = $i32->constInt(0, false);
        $negOneI32 = $i32->constInt(-1, true);
        $zeroI64 = $i64->constInt(0, false);
        $oneI64 = $i64->constInt(1, false);
        $nsPerSecI64 = $i64->constInt(self::NS_PER_SEC, false);
        $nsPerSecDbl = $dbl->constReal((float) self::NS_PER_SEC, false);
        $halfDbl = $dbl->constReal(0.5, false);
        $zeroDbl = $dbl->constReal(0.0, false);

        $fn = $context->module->addFunction(
            self::READ_MONOTONIC,
            $context->context->functionType($i32, false, $i64p, $i64p)
        );
        $context->registerFunction(self::READ_MONOTONIC, $fn);

        $entry = $fn->appendBasicBlock('hr_mono_entry');
        $context->builder->positionAtEnd($entry);

        $secOut = $fn->getParam(0);
        $nsecOut = $fn->getParam(1);

        $bufLen = 64;
        $buf = $context->builder->alloca($i8, $i64->constInt($bufLen, false), 'hr_uptime_buf');
        $path = self::cstring($context, '/proc/uptime');

        $fd = $context->builder->call(
            $context->lookupFunction('open'),
            $path,
            $zeroI32,
            $zeroI32
        );
        $openFail = $context->builder->icmp(Builder::INT_SLT, $fd, $zeroI32);
        $failBb = $fn->appendBasicBlock('hr_mono_fail');
        $openOk = $fn->appendBasicBlock('hr_mono_open_ok');
        $context->builder->branchIf($openFail, $failBb, $openOk);

        $context->builder->positionAtEnd($openOk);
        $nRead = $context->builder->call(
            $context->lookupFunction('read'),
            $fd,
            $context->builder->pointerCast($buf, $i8p),
            $context->builder->truncOrBitCast($i64->constInt($bufLen - 1, false), $sizeT)
        );
        $context->builder->call($context->lookupFunction('close'), $fd);

        $readFail = $context->builder->icmp(Builder::INT_SLE, $nRead, $zeroI64);
        $parseBb = $fn->appendBasicBlock('hr_mono_parse');
        $context->builder->branchIf($readFail, $failBb, $parseBb);

        $context->builder->positionAtEnd($parseBb);
        $context->builder->store($i8->constInt(0, false), $context->builder->inBoundsGEP($buf, $nRead));

        $spacePtr = $context->builder->call(
            $context->lookupFunction('strchr'),
            $context->builder->pointerCast($buf, $i8p),
            $i32->constInt(32, false)
        );
        $spaceNull = $context->builder->icmp(Builder::INT_EQ, $spacePtr, $i8p->constNull());
        $strtodBb = $fn->appendBasicBlock('hr_mono_strtod');
        $context->builder->branchIf($spaceNull, $failBb, $strtodBb);

        $context->builder->positionAtEnd($strtodBb);
        $context->builder->store($i8->constInt(0, false), $spacePtr);

        $endPtrSlot = $context->builder->alloca($i8p, 1, 'hr_uptime_end');
        $context->builder->store($i8p->constNull(), $endPtrSlot);
        $secsDbl = $context->builder->call(
            $context->lookupFunction('strtod'),
            $context->builder->pointerCast($buf, $i8p),
            $endPtrSlot
        );
        $negative = $context->builder->fcmp(Builder::REAL_OLT, $secsDbl, $zeroDbl);
        $convertBb = $fn->appendBasicBlock('hr_mono_convert');
        $context->builder->branchIf($negative, $failBb, $convertBb);

        $context->builder->positionAtEnd($convertBb);
        $sec = $context->builder->fptosi($secsDbl, $i64);
        $secDbl = $context->builder->sitofp($sec, $dbl);
        $frac = $context->builder->fsub($secsDbl, $secDbl);
        $nsecRaw = $context->builder->fptosi(
            $context->builder->fadd($context->builder->fmul($frac, $nsPerSecDbl), $halfDbl),
            $i64
        );
        $nsecGe = $context->builder->icmp(Builder::INT_SGE, $nsecRaw, $nsPerSecI64);
        $secFinal = $context->builder->select($nsecGe, $context->builder->add($sec, $oneI64), $sec);
        $nsecFinal = $context->builder->select(
            $nsecGe,
            $context->builder->sub($nsecRaw, $nsPerSecI64),
            $nsecRaw
        );

        $context->builder->store($secFinal, $secOut);
        $context->builder->store($nsecFinal, $nsecOut);
        $context->builder->returnValue($zeroI32);
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($failBb);
        $context->builder->store($zeroI64, $secOut);
        $context->builder->store($zeroI64, $nsecOut);
        $context->builder->returnValue($negOneI32);
        $context->builder->clearInsertionPosition();
    }

    private static function cstring(Context $context, string $text): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $len = \strlen($text) + 1;
        $buf = BasicBlockHelper::entryAlloca($context, $i8->arrayType($len));
        $ptr = $context->builder->pointerCast($buf, $i8p);
        for ($i = 0; $i < \strlen($text); ++$i) {
            $context->builder->store(
                $i8->constInt(\ord($text[$i]), false),
                $context->builder->inBoundsGEP($ptr, $context->getTypeFromString('int64')->constInt($i, false))
            );
        }
        $context->builder->store(
            $i8->constInt(0, false),
            $context->builder->inBoundsGEP($ptr, $context->getTypeFromString('int64')->constInt(\strlen($text), false))
        );

        return $ptr;
    }

    private static function ensureLibcForUptime(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $i8pp = $context->getTypeFromString('int8**');
        $dbl = $context->getTypeFromString('double');
        $sizeT = $context->getTypeFromString('size_t');

        foreach ([
            ['open', $i32, [$i8p, $i32, $i32]],
            ['read', $sizeT, [$i32, $i8p, $sizeT]],
            ['close', $i32, [$i32]],
            ['strchr', $i8p, [$i8p, $i32]],
            ['strtod', $dbl, [$i8p, $i8pp]],
        ] as [$name, $ret, $params]) {
            self::ensureExternal(
                $context,
                $name,
                $context->context->functionType($ret, false, ...$params)
            );
        }
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
        $reader = $context->module->getNamedFunction(self::READ_MONOTONIC);
        if (null !== $reader) {
            $context->registerFunction(self::READ_MONOTONIC, $reader);
        }
    }
}
