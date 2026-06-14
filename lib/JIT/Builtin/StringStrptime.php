<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM __compiler_strptime — libc strptime + hashtable breakdown (#3694).
 *
 * php-src: ext/standard/datetime.c — PHP_FUNCTION(strptime)
 */
final class StringStrptime
{
    private const TM_SEC = 0;

    private const TM_MIN = 4;

    private const TM_HOUR = 8;

    private const TM_MDAY = 12;

    private const TM_MON = 16;

    private const TM_YEAR = 20;

    private const TM_WDAY = 24;

    private const TM_YDAY = 28;

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_strptime');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureHashtableHelpers($context);

        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false, $strPtr, $strPtr, $valuePtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction('__compiler_strptime', $ft);
        self::implementStrptime($context, $fn);
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    private static function implementStrptime(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('sp_entry');
        $context->builder->positionAtEnd($entry);

        $dateStr = $fn->getParam(0);
        $formatStr = $fn->getParam(1);
        $out = $fn->getParam(2);

        $strMap = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $charPtr = $context->getTypeFromString('char*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $sizeT = $context->getTypeFromString('size_t');

        $nullOut = $context->builder->icmp(Builder::INT_EQ, $out, $valuePtr->constNull());
        $nullRetBb = $fn->appendBasicBlock('sp_null_out');
        $parseBb = $fn->appendBasicBlock('sp_parse');
        $context->builder->branchIf($nullOut, $nullRetBb, $parseBb);

        $context->builder->positionAtEnd($nullRetBb);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($parseBb);
        $dateChars = $context->builder->structGep($dateStr, $strMap['value']);
        $datePtr = $context->builder->pointerCast($dateChars, $charPtr);
        $fmtChars = $context->builder->structGep($formatStr, $strMap['value']);
        $fmtPtr = $context->builder->pointerCast($fmtChars, $charPtr);

        $tmBuf = $context->builder->alloca($i8, 36, 'sp_tm');
        $tmPtr = $context->builder->pointerCast($tmBuf, $i8p);
        $context->builder->call(
            $context->lookupFunction('memset'),
            $tmPtr,
            $i32->constInt(0, false),
            $sizeT->constInt(36, false)
        );

        $rest = $context->builder->call(
            $context->lookupFunction('strptime'),
            $datePtr,
            $fmtPtr,
            $tmPtr
        );
        $parseFail = $context->builder->icmp(Builder::INT_EQ, $rest, $charPtr->constNull());
        $failBb = $fn->appendBasicBlock('sp_fail');
        $okBb = $fn->appendBasicBlock('sp_ok');
        $context->builder->branchIf($parseFail, $failBb, $okBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            $out,
            $i32->constInt(0, false)
        );
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($okBb);
        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $htNull = $context->builder->icmp(Builder::INT_EQ, $ht, $htPtr->constNull());
        $allocFailBb = $fn->appendBasicBlock('sp_alloc_fail');
        $fillBb = $fn->appendBasicBlock('sp_fill');
        $context->builder->branchIf($htNull, $allocFailBb, $fillBb);

        $context->builder->positionAtEnd($allocFailBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            $out,
            $i32->constInt(0, false)
        );
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($fillBb);
        $setLong = $context->lookupFunction('__hashtable__setStringKeyLong');
        $setString = $context->lookupFunction('__hashtable__setStringKeyString');
        foreach ([
            'tm_sec' => self::TM_SEC,
            'tm_min' => self::TM_MIN,
            'tm_hour' => self::TM_HOUR,
            'tm_mday' => self::TM_MDAY,
            'tm_mon' => self::TM_MON,
            'tm_year' => self::TM_YEAR,
            'tm_wday' => self::TM_WDAY,
            'tm_yday' => self::TM_YDAY,
        ] as $key => $offset) {
            $field = self::loadTmField($context, $tmPtr, $offset);
            $context->builder->call(
                $setLong,
                $ht,
                self::literalString($context, $key),
                $context->builder->zExt($field, $i64)
            );
        }

        $restI8 = $context->builder->pointerCast($rest, $i8p);
        $unparsedLen = $context->builder->call($context->lookupFunction('strlen'), $restI8);
        $unparsedStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zExt($unparsedLen, $i64),
            $restI8
        );
        $context->builder->call(
            $setString,
            $ht,
            self::literalString($context, 'unparsed'),
            $unparsedStr
        );
        $context->builder->call($context->lookupFunction('__value__writeHashtable'), $out, $ht);
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
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $voidTy = $context->getTypeFromString('void');
        $charPtr = $context->getTypeFromString('char*');

        foreach ([
            ['__hashtable__alloc', $htPtr, []],
            ['__hashtable__setStringKeyLong', $voidTy, [$htPtr, $strPtr, $i64]],
            ['__hashtable__setStringKeyString', $voidTy, [$htPtr, $strPtr, $strPtr]],
            ['__value__writeHashtable', $voidTy, [$valuePtr, $htPtr]],
            ['__value__writeBool', $voidTy, [$valuePtr, $i32]],
            ['__string__init', $strPtr, [$i64, $i8p]],
            ['memset', $i8p, [$i8p, $i32, $sizeT]],
            ['strptime', $charPtr, [$charPtr, $charPtr, $i8p]],
            ['strlen', $sizeT, [$i8p]],
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
        $fn = $context->module->getNamedFunction('__compiler_strptime');
        if (null === $fn) {
            throw new \LogicException('__compiler_strptime missing after StringStrptime LLVM implement');
        }
        $context->registerFunction('__compiler_strptime', $fn);
    }
}
