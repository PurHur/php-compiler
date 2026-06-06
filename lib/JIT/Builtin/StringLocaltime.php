<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM implementation of __compiler_localtime — localtime + numeric/assoc array.
 *
 * Mirrors ext/standard/VmDate::localtimeBreakdown() (issue #6812).
 * php-src: ext/standard/datetime.c — PHP_FUNCTION(localtime)
 */
final class StringLocaltime
{
    private const TM_SEC = 0;

    private const TM_MIN = 4;

    private const TM_HOUR = 8;

    private const TM_MDAY = 12;

    private const TM_MON = 16;

    private const TM_YEAR = 20;

    private const TM_WDAY = 24;

    private const TM_YDAY = 28;

    private const TM_ISDST = 32;

    /** @var list<string> */
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

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_localtime');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureHashtableHelpers($context);

        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $voidTy = $context->getTypeFromString('void');
        $valuePtr = $context->getTypeFromString('__value__*');

        $ft = $context->context->functionType($voidTy, false, $i64, $i1, $valuePtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction('__compiler_localtime', $ft);
        self::implementLocaltime($context, $fn);

        self::registerLinkedRuntime($context);
    }

    private static function implementLocaltime(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('lt_entry');
        $context->builder->positionAtEnd($entry);

        $timestamp = $fn->getParam(0);
        $associative = $fn->getParam(1);
        $out = $fn->getParam(2);

        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $sizeT = $context->getTypeFromString('size_t');
        $nullOut = $context->builder->icmp(Builder::INT_EQ, $out, $valuePtr->constNull());
        $nullRetBb = $fn->appendBasicBlock('lt_null_out');
        $localBb = $fn->appendBasicBlock('lt_localtime');
        $context->builder->branchIf($nullOut, $nullRetBb, $localBb);

        $context->builder->positionAtEnd($nullRetBb);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($localBb);
        $i64p = $context->getTypeFromString('int64*');
        $tsSlot = $context->builder->alloca($i64, 1, 'lt_ts');
        $context->builder->store($timestamp, $tsSlot);
        $tsPtr = $context->builder->pointerCast($tsSlot, $i64p);
        $tmPtr = $context->builder->call($context->lookupFunction('localtime'), $tsPtr);
        $tmNull = $context->builder->icmp(Builder::INT_EQ, $tmPtr, $i8p->constNull());
        $tmFailBb = $fn->appendBasicBlock('lt_tm_fail');
        $fillBb = $fn->appendBasicBlock('lt_fill');
        $context->builder->branchIf($tmNull, $tmFailBb, $fillBb);

        $context->builder->positionAtEnd($tmFailBb);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($fillBb);
        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $htNull = $context->builder->icmp(Builder::INT_EQ, $ht, $htPtr->constNull());
        $allocFailBb = $fn->appendBasicBlock('lt_alloc_fail');
        $keysBb = $fn->appendBasicBlock('lt_keys');
        $context->builder->branchIf($htNull, $allocFailBb, $keysBb);

        $context->builder->positionAtEnd($allocFailBb);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($keysBb);
        $offsets = [
            self::TM_SEC,
            self::TM_MIN,
            self::TM_HOUR,
            self::TM_MDAY,
            self::TM_MON,
            self::TM_YEAR,
            self::TM_WDAY,
            self::TM_YDAY,
            self::TM_ISDST,
        ];
        $values = [];
        foreach ($offsets as $offset) {
            $values[] = $context->builder->zExt(self::loadTmField($context, $tmPtr, $offset), $i64);
        }

        $setLong = $context->lookupFunction('__hashtable__setStringKeyLong');
        $setAt = $context->lookupFunction('__hashtable__setLongAt');
        $assocBb = $fn->appendBasicBlock('lt_assoc');
        $indexBb = $fn->appendBasicBlock('lt_index');
        $doneBb = $fn->appendBasicBlock('lt_done');
        $context->builder->branchIf($associative, $assocBb, $indexBb);

        $context->builder->positionAtEnd($assocBb);
        foreach (self::ASSOC_KEYS as $i => $key) {
            $context->builder->call(
                $setLong,
                $ht,
                self::literalString($context, $key),
                $values[$i]
            );
        }
        $context->builder->branch($doneBb);
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($indexBb);
        foreach ($values as $i => $val) {
            $context->builder->call(
                $setAt,
                $ht,
                $sizeT->constInt($i, false),
                $val
            );
        }
        $context->builder->branch($doneBb);
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($doneBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $out,
            $ht
        );
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function loadTmField(Context $context, Value $tmPtr, int $offset): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $i32p = $context->getTypeFromString('int32*');
        $tmFields = $context->builder->pointerCast($tmPtr, $i32p);

        return $context->builder->load(
            $context->builder->gep($tmFields, $i32->constInt((int) ($offset / 4), false))
        );
    }

    private static function literalString(Context $context, string $text): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $cstr = $context->builder->pointerCast($context->constantFromString($text), $i8p);

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(\strlen($text), false),
            $cstr
        );
    }

    private static function ensureHashtableHelpers(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $voidTy = $context->getTypeFromString('void');

        foreach ([
            ['__hashtable__alloc', $htPtr, []],
            ['__hashtable__setStringKeyLong', $voidTy, [$htPtr, $strPtr, $i64]],
            ['__hashtable__setLongAt', $voidTy, [$htPtr, $sizeT, $i64]],
            ['__value__writeHashtable', $voidTy, [$valuePtr, $htPtr]],
            ['__string__init', $strPtr, [$i64, $context->getTypeFromString('int8*')]],
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
        } catch (\Throwable) {
            $fn = $context->module->addFunction($name, $ft);
            $context->registerFunction($name, $fn);
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction('__compiler_localtime');
        if (null === $fn) {
            throw new \LogicException('__compiler_localtime missing after StringLocaltime LLVM implement');
        }
        $context->registerFunction('__compiler_localtime', $fn);
    }
}
