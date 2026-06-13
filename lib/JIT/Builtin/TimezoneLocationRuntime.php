<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\VmDateTimeNative;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM timezone_location_get() — zone.tab lookup baked at compile time (#6041 phase 2).
 *
 * php-src: ext/date/php_date.c — PHP_FUNCTION(timezone_location_get)
 */
final class TimezoneLocationRuntime
{
    private const ZONEINFO_ROOT = '/usr/share/zoneinfo';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__phpc_timezone_location_ht');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        LibcExtern::register($context);
        self::ensureHashtableHelpers($context);
        self::ensureValueHelpers($context);

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType($htPtr, false, $strPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction('__phpc_timezone_location_ht', $ft);
        $restore = $context->builder->getInsertBlock();
        self::implementLookup($context, $fn);

        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
        if (null !== $restore) {
            $terminator = $restore->getTerminator();
            if (null !== $terminator) {
                $context->builder->positionBefore($terminator);
            } else {
                $context->builder->positionAtEnd($restore);
            }
        }
    }

    private static function implementLookup(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('tzloc_entry');
        $context->builder->positionAtEnd($entry);

        $tzName = $fn->getParam(0);
        $htPtrTy = $context->getTypeFromString('__hashtable__*');
        $nullHt = $htPtrTy->constNull();

        $nullTz = $context->builder->icmp(
            Builder::INT_EQ,
            $tzName,
            $context->getTypeFromString('__string__*')->constNull()
        );
        $nullBb = $fn->appendBasicBlock('tzloc_null_tz');
        $matchBb = $fn->appendBasicBlock('tzloc_match');
        $context->builder->branchIf($nullTz, $nullBb, $matchBb);

        $context->builder->positionAtEnd($nullBb);
        $context->builder->returnValue($nullHt);

        $context->builder->positionAtEnd($matchBb);
        $tzCStr = self::stringData($context, $tzName);
        $entries = VmDateTimeNative::exportZoneTabEntries();
        $doneBb = $fn->appendBasicBlock('tzloc_done');
        $fallbackBb = $fn->appendBasicBlock('tzloc_fallback');

        $nextBb = $matchBb;
        foreach ($entries as $idx => $entry) {
            $checkBb = $fn->appendBasicBlock('tzloc_chk_'.$idx);
            $hitBb = $fn->appendBasicBlock('tzloc_hit_'.$idx);
            $context->builder->positionAtEnd($nextBb);
            $idCStr = $context->builder->pointerCast(
                $context->constantFromString($entry['id']),
                $context->getTypeFromString('int8*')
            );
            $cmp = $context->builder->call(
                $context->lookupFunction('strcasecmp'),
                $tzCStr,
                $idCStr
            );
            $isHit = $context->builder->icmp(Builder::INT_EQ, $cmp, $context->getTypeFromString('int32')->constInt(0, false));
            $context->builder->branchIf($isHit, $hitBb, $checkBb);

            $context->builder->positionAtEnd($hitBb);
            $ht = self::emitLocationHashtable(
                $context,
                $entry['country'],
                $entry['latitude'],
                $entry['longitude'],
                $entry['comments']
            );
            $context->builder->returnValue($ht);

            $nextBb = $checkBb;
        }

        $context->builder->positionAtEnd($nextBb);
        $context->builder->branch($fallbackBb);

        $context->builder->positionAtEnd($fallbackBb);
        $exists = self::emitZoneinfoExists($context, $fn, $tzCStr);
        $noBb = $fn->appendBasicBlock('tzloc_missing');
        $yesBb = $fn->appendBasicBlock('tzloc_default');
        $context->builder->branchIf($exists, $yesBb, $noBb);

        $context->builder->positionAtEnd($noBb);
        $context->builder->returnValue($nullHt);

        $context->builder->positionAtEnd($yesBb);
        $defaultHt = self::emitLocationHashtable($context, '??', 0.0, 0.0, '?');
        $context->builder->returnValue($defaultHt);

        $context->builder->positionAtEnd($doneBb);
    }

    private static function emitZoneinfoExists(Context $context, LlvmFunction $fn, Value $tzCStr): Value
    {
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $pathBuf = $context->builder->alloca($i8p, $i32->constInt(512, false), 'tzloc_path');
        $pathPtr = $context->builder->pointerCast($pathBuf, $i8p);
        $fmt = $context->builder->pointerCast(
            $context->constantFromString(self::ZONEINFO_ROOT.'/%s'),
            $i8p
        );
        $context->builder->call(
            $context->lookupFunction('snprintf'),
            $pathPtr,
            $sizeT->constInt(512, false),
            $fmt,
            $tzCStr
        );
        $statBuf = $context->builder->alloca($i8p, $i32->constInt(256, false), 'tzloc_stat');
        $statPtr = $context->builder->pointerCast($statBuf, $i8p);
        $rc = $context->builder->call(
            $context->lookupFunction('stat'),
            $pathPtr,
            $statPtr
        );

        return $context->builder->icmp(Builder::INT_EQ, $rc, $i32->constInt(0, false));
    }

    private static function emitLocationHashtable(
        Context $context,
        string $country,
        float $latitude,
        float $longitude,
        string $comments
    ): Value {
        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $setStr = $context->lookupFunction('__hashtable__setStringKeyString');
        $setDbl = $context->lookupFunction('__hashtable__setStringKeyDouble');
        $strTy = $context->getTypeFromString('__string__*');
        $dblTy = $context->getTypeFromString('double');

        foreach ([
            'country_code' => $country,
            'comments' => $comments,
        ] as $key => $value) {
            $context->builder->call(
                $setStr,
                $ht,
                self::compileString($context, $key),
                self::compileString($context, $value)
            );
        }
        $context->builder->call(
            $setDbl,
            $ht,
            self::compileString($context, 'latitude'),
            $dblTy->constReal($latitude)
        );
        $context->builder->call(
            $setDbl,
            $ht,
            self::compileString($context, 'longitude'),
            $dblTy->constReal($longitude)
        );

        return $ht;
    }

    private static function compileString(Context $context, string $text): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(\strlen($text), false),
            $context->builder->pointerCast(
                $context->constantFromString($text),
                $i8p
            )
        );
    }

    private static function stringData(Context $context, Value $strPtr): Value
    {
        $off = $context->structFieldIndex($strPtr, 'value');

        return $context->builder->pointerCast(
            $context->builder->structGep($strPtr, $off),
            $context->getTypeFromString('int8*')
        );
    }

    private static function ensureHashtableHelpers(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $dbl = $context->getTypeFromString('double');
        $voidTy = $context->getTypeFromString('void');
        $i8p = $context->getTypeFromString('int8*');

        foreach ([
            ['__hashtable__alloc', $htPtr, []],
            ['__hashtable__setStringKeyString', $voidTy, [$htPtr, $strPtr, $strPtr]],
            ['__hashtable__setStringKeyDouble', $voidTy, [$htPtr, $strPtr, $dbl]],
            ['__string__init', $strPtr, [$context->getTypeFromString('int64'), $i8p]],
        ] as [$name, $ret, $params]) {
            if (null === $context->module->getNamedFunction($name)) {
                $context->module->addFunction(
                    $name,
                    $context->context->functionType($ret, false, ...$params)
                );
            }
        }
    }

    private static function ensureValueHelpers(Context $context): void
    {
        $voidTy = $context->getTypeFromString('void');
        $valuePtr = $context->getTypeFromString('__value__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');

        foreach ([
            ['__value__writeHashtable', $voidTy, [$valuePtr, $htPtr]],
            ['__value__writeBool', $voidTy, [$valuePtr, $context->getTypeFromString('int32')]],
        ] as [$name, $ret, $params]) {
            if (null === $context->module->getNamedFunction($name)) {
                $context->module->addFunction(
                    $name,
                    $context->context->functionType($ret, false, ...$params)
                );
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $context->registerFunction(
            '__phpc_timezone_location_ht',
            $context->module->getNamedFunction('__phpc_timezone_location_ht')
        );
    }
}
