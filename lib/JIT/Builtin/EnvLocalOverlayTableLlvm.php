<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM overlay table for getenv() zero-arg merge — avoids nested JIT Variable::string (#12810, #1492).
 *
 * Register bridge keeps this table in sync with {@see \PHPCompiler\ext\standard\GetenvJitHelper}
 * while lookup/register SSOT remains PHP helpers. php-src: ext/standard/basic_functions.c — EG(env).
 */
final class EnvLocalOverlayTableLlvm
{
    private const MAX_ENTRIES = 256;

    private const G_ENTRIES = 'phpc_getenv_jit_overlay_entries';

    private const G_COUNT = 'phpc_getenv_jit_overlay_count';

    private static int $blockSuffix = 0;

    public static function implementSyncOverlayBridge(Context $context): void
    {
        $abiName = '__compiler_env_local_sync_overlay';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        self::ensureGlobals($context);
        self::ensureLibc($context);

        $voidTy = $context->getTypeFromString('void');
        $i8p = $context->getTypeFromString('int8*');
        $ft = $context->context->functionType($voidTy, false, $i8p);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('el_sync_entry');
        $doneBb = $fn->appendBasicBlock('el_sync_done');
        $context->builder->positionAtEnd($entry);
        self::emitSyncFromSettingCstr($context, $fn, $fn->getParam(0), $doneBb);
        $context->builder->positionAtEnd($doneBb);
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    public static function ensureGlobals(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');

        if (null === $context->module->getNamedGlobal(self::G_ENTRIES)) {
            $entries = $context->module->addGlobal(self::entryArrayType($context), self::G_ENTRIES);
            $entries->setInitializer(self::entryArrayType($context)->constNull());
        }
        if (null === $context->module->getNamedGlobal(self::G_COUNT)) {
            $count = $context->module->addGlobal($i32, self::G_COUNT);
            $count->setInitializer($i32->constInt(0, false));
        }
    }

    public static function ensureLibc(Context $context): void
    {
        $voidPtr = $context->getTypeFromString('void*');
        $voidTy = $context->getTypeFromString('void');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $charPtr = $context->getTypeFromString('char*');

        foreach ([
            ['free', $voidTy, [$voidPtr]],
            ['memcpy', $voidPtr, [$voidPtr, $voidPtr, $sizeT]],
            ['strcmp', $i32, [$i8p, $i8p]],
            ['strchr', $i8p, [$i8p, $i32]],
            ['__hashtable__setStringKeyString', $voidTy, [
                $context->getTypeFromString('__hashtable__*'),
                $context->getTypeFromString('__string__*'),
                $context->getTypeFromString('__string__*'),
            ]],
            ['__string__init', $context->getTypeFromString('__string__*'), [$i64, $charPtr]],
        ] as [$name, $ret, $params]) {
            self::ensureExternal($context, $name, $context->context->functionType($ret, false, ...$params));
        }
    }

    /** @param BasicBlock $continueBb merge target for all exit paths */
    private static function emitSyncFromSettingCstr(
        Context $context,
        LlvmFunction $fn,
        Value $settingCstr,
        BasicBlock $continueBb
    ): void {
        self::$blockSuffix = 0;
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $null = $i8p->constNull();
        $eqChar = $i32->constInt(ord('='), false);

        $nameSlot = $context->builder->alloca($i8p, 1, 'el_sync_name');
        $valueSlot = $context->builder->alloca($i8p, 1, 'el_sync_value');
        $unsetSlot = $context->builder->alloca($i32, 1, 'el_sync_unset');

        $badBb = $fn->appendBasicBlock('el_sync_bad');
        $parseBb = $fn->appendBasicBlock('el_sync_parse');
        $settingNull = $context->builder->icmp(Builder::INT_EQ, $settingCstr, $null);
        $context->builder->branchIf($settingNull, $badBb, $parseBb);

        $context->builder->positionAtEnd($parseBb);
        $eq = $context->builder->call($context->lookupFunction('strchr'), $settingCstr, $eqChar);
        $noEqBb = $fn->appendBasicBlock('el_sync_no_eq');
        $hasEqBb = $fn->appendBasicBlock('el_sync_has_eq');
        $afterParse = $fn->appendBasicBlock('el_sync_after_parse');
        $eqNull = $context->builder->icmp(Builder::INT_EQ, $eq, $null);
        $context->builder->branchIf($eqNull, $noEqBb, $hasEqBb);

        $context->builder->positionAtEnd($noEqBb);
        $context->builder->store(self::dupCstr($context, $settingCstr), $nameSlot);
        $context->builder->store($null, $valueSlot);
        $context->builder->store($i32->constInt(1, false), $unsetSlot);
        $context->builder->branch($afterParse);

        $context->builder->positionAtEnd($hasEqBb);
        $nameLen = $context->builder->sub(
            $context->builder->ptrToInt($eq, $sizeT),
            $context->builder->ptrToInt($settingCstr, $sizeT)
        );
        $nameBuf = $context->builder->call(
            $context->lookupFunction('malloc'),
            $context->builder->add($nameLen, $sizeT->constInt(1, false))
        );
        $nameCstr = $context->builder->pointerCast($nameBuf, $i8p);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $nameCstr,
            $settingCstr,
            $nameLen
        );
        $context->builder->store(
            $i8->constInt(0, false),
            $context->builder->inBoundsGEP($nameCstr, $nameLen)
        );
        $valueStart = $context->builder->inBoundsGEP($eq, $sizeT->constInt(1, false));
        $context->builder->store($nameCstr, $nameSlot);
        $context->builder->store(self::dupCstr($context, $valueStart), $valueSlot);
        $context->builder->store($i32->constInt(0, false), $unsetSlot);
        $context->builder->branch($afterParse);

        $emptyNameBb = $fn->appendBasicBlock('el_sync_empty_name');
        $applyBb = $fn->appendBasicBlock('el_sync_apply');
        $afterRemoveBb = $fn->appendBasicBlock('el_sync_after_remove');
        $unsetBb = $fn->appendBasicBlock('el_sync_unset_only');
        $setBb = $fn->appendBasicBlock('el_sync_set');

        $context->builder->positionAtEnd($afterParse);
        $name = $context->builder->load($nameSlot);
        $firstByte = $context->builder->load($name);
        $nameEmpty = $context->builder->icmp(Builder::INT_EQ, $firstByte, $i8->constInt(0, false));
        $context->builder->branchIf($nameEmpty, $emptyNameBb, $applyBb);

        $context->builder->positionAtEnd($emptyNameBb);
        $context->builder->call($context->lookupFunction('free'), $name);
        $context->builder->call($context->lookupFunction('free'), $context->builder->load($valueSlot));
        $context->builder->branch($badBb);

        $context->builder->positionAtEnd($applyBb);
        self::emitRemoveByName($context, $fn, $name, $afterRemoveBb);

        $context->builder->positionAtEnd($afterRemoveBb);
        $unset = $context->builder->load($unsetSlot);
        $isUnset = $context->builder->icmp(Builder::INT_NE, $unset, $i32->constInt(0, false));
        $context->builder->branchIf($isUnset, $unsetBb, $setBb);

        $context->builder->positionAtEnd($unsetBb);
        $context->builder->call($context->lookupFunction('free'), $context->builder->load($nameSlot));
        $context->builder->call($context->lookupFunction('free'), $context->builder->load($valueSlot));
        $context->builder->branch($continueBb);

        $context->builder->positionAtEnd($setBb);
        self::emitSetEntry(
            $context,
            $fn,
            $context->builder->load($nameSlot),
            $context->builder->load($valueSlot)
        );
        $context->builder->call($context->lookupFunction('free'), $context->builder->load($nameSlot));
        $context->builder->call($context->lookupFunction('free'), $context->builder->load($valueSlot));
        $context->builder->branch($continueBb);

        $context->builder->positionAtEnd($badBb);
        $context->builder->branch($continueBb);
    }

    public static function emitMergeOverlay(Context $context, Value $ht): void
    {
        self::ensureGlobals($context);
        self::ensureLibc($context);

        $fn = $context->builder->getInsertBlock()->getParent();
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $null = $i8p->constNull();
        $one = $i32->constInt(1, false);

        $idxSlot = $context->builder->alloca($i32, 1, 'ga_local_i');
        $context->builder->store($i32->constInt(0, false), $idxSlot);

        $loopCheck = $fn->appendBasicBlock('ga_local_check');
        $loopBody = $fn->appendBasicBlock('ga_local_body');
        $loopNext = $fn->appendBasicBlock('ga_local_next');
        $loopDone = $fn->appendBasicBlock('ga_local_done');
        $context->builder->branch($loopCheck);

        $context->builder->positionAtEnd($loopCheck);
        $idx = $context->builder->load($idxSlot);
        $count = $context->builder->load(self::globalPtr($context, self::G_COUNT, $i32));
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $count);
        $context->builder->branchIf($atEnd, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $entryPtr = self::entryPtr($context, $idx);
        $entryName = $context->builder->load($context->builder->structGep($entryPtr, 0));
        $entryValue = $context->builder->load($context->builder->structGep($entryPtr, 1));
        $valueNull = $context->builder->icmp(Builder::INT_EQ, $entryValue, $null);
        $skipBb = $fn->appendBasicBlock('ga_local_skip');
        $setBb = $fn->appendBasicBlock('ga_local_set');
        $context->builder->branchIf($valueNull, $skipBb, $setBb);

        $context->builder->positionAtEnd($setBb);
        self::setCstrPair($context, $ht, $entryName, $entryValue);
        $context->builder->branch($loopNext);

        $context->builder->positionAtEnd($skipBb);
        $context->builder->branch($loopNext);

        $context->builder->positionAtEnd($loopNext);
        $context->builder->store($context->builder->add($idx, $one), $idxSlot);
        $context->builder->branch($loopCheck);

        $context->builder->positionAtEnd($loopDone);
    }

    private static function emitRemoveByName(Context $context, LlvmFunction $fn, Value $name, BasicBlock $exitBb): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $null = $i8p->constNull();
        $one = $i32->constInt(1, false);

        $idxSlot = $context->builder->alloca($i32, 1, 'el_remove_i');
        $context->builder->store($i32->constInt(0, false), $idxSlot);
        $loopCheck = $fn->appendBasicBlock('el_remove_check_'.(++self::$blockSuffix));
        $loopBody = $fn->appendBasicBlock('el_remove_body_'.self::$blockSuffix);
        $loopNext = $fn->appendBasicBlock('el_remove_next_'.self::$blockSuffix);
        $loopFound = $fn->appendBasicBlock('el_remove_found_'.self::$blockSuffix);
        $context->builder->branch($loopCheck);

        $context->builder->positionAtEnd($loopCheck);
        $idx = $context->builder->load($idxSlot);
        $count = $context->builder->load(self::globalPtr($context, self::G_COUNT, $i32));
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $count);
        $context->builder->branchIf($atEnd, $exitBb, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $entryPtr = self::entryPtr($context, $idx);
        $entryName = $context->builder->load($context->builder->structGep($entryPtr, 0));
        $entryValue = $context->builder->load($context->builder->structGep($entryPtr, 1));
        $cmp = $context->builder->call($context->lookupFunction('strcmp'), $entryName, $name);
        $isMatch = $context->builder->icmp(Builder::INT_EQ, $cmp, $i32->constInt(0, false));
        $context->builder->branchIf($isMatch, $loopFound, $loopNext);

        $context->builder->positionAtEnd($loopNext);
        $context->builder->store($context->builder->add($idx, $one), $idxSlot);
        $context->builder->branch($loopCheck);

        $context->builder->positionAtEnd($loopFound);
        $context->builder->call($context->lookupFunction('free'), $entryName);
        $context->builder->call($context->lookupFunction('free'), $entryValue);
        $lastIdx = $context->builder->sub($count, $one);
        $lastPtr = self::entryPtr($context, $lastIdx);
        $lastName = $context->builder->load($context->builder->structGep($lastPtr, 0));
        $lastValue = $context->builder->load($context->builder->structGep($lastPtr, 1));
        $context->builder->store($lastName, $context->builder->structGep($entryPtr, 0));
        $context->builder->store($lastValue, $context->builder->structGep($entryPtr, 1));
        $countPtr = self::globalPtr($context, self::G_COUNT, $i32);
        $context->builder->store($lastIdx, $countPtr);
        $context->builder->branch($loopCheck);
    }

    private static function emitSetEntry(Context $context, LlvmFunction $fn, Value $name, Value $value): void
    {
        $i32 = $context->getTypeFromString('int32');
        $countPtr = self::globalPtr($context, self::G_COUNT, $i32);
        $count = $context->builder->load($countPtr);
        $atMax = $context->builder->icmp(
            Builder::INT_SGE,
            $count,
            $i32->constInt(self::MAX_ENTRIES, false)
        );
        $doneBb = $fn->appendBasicBlock('el_set_done_'.(++self::$blockSuffix));
        $workBb = $fn->appendBasicBlock('el_set_work_'.self::$blockSuffix);
        $context->builder->branchIf($atMax, $doneBb, $workBb);

        $context->builder->positionAtEnd($workBb);
        $entryPtr = self::entryPtr($context, $count);
        $context->builder->store(self::dupCstr($context, $name), $context->builder->structGep($entryPtr, 0));
        $context->builder->store(self::dupCstr($context, $value), $context->builder->structGep($entryPtr, 1));
        $context->builder->store(
            $context->builder->add($count, $i32->constInt(1, false)),
            $countPtr
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
    }

    private static function setCstrPair(Context $context, Value $ht, Value $keyCstr, Value $valueCstr): void
    {
        $keyStr = self::cstrToString($context, $keyCstr);
        $valStr = self::cstrToString($context, $valueCstr);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyString'),
            $ht,
            $keyStr,
            $valStr
        );
    }

    private static function cstrToString(Context $context, Value $cstr): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $charPtr = $context->getTypeFromString('char*');
        $len = $context->builder->call($context->lookupFunction('strlen'), $cstr);

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zExt($len, $i64),
            $context->builder->pointerCast($cstr, $charPtr)
        );
    }

    private static function dupCstr(Context $context, Value $src): Value
    {
        return self::dupCstrBytes($context, $src);
    }

    /** Duplicate a null-terminated C string (php-src env overlay / lookup bridges). */
    public static function dupCstrBytes(Context $context, Value $src): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $len = $context->builder->call($context->lookupFunction('strlen'), $src);
        $buf = $context->builder->call(
            $context->lookupFunction('malloc'),
            $context->builder->add($len, $sizeT->constInt(1, false))
        );
        $dest = $context->builder->pointerCast($buf, $i8p);
        $context->builder->call($context->lookupFunction('memcpy'), $dest, $src, $len);
        $context->builder->store(
            $i8->constInt(0, false),
            $context->builder->inBoundsGEP($dest, $len)
        );

        return $dest;
    }

    /** Duplicate __string__ payload bytes into a malloc'd C string (#12910). */
    public static function dupCstrFromStringStruct(Context $context, Value $src): Value
    {
        $strMap = $context->structFieldMap['__string__'];
        $valueBytes = $context->builder->structGep($src, $strMap['value']);

        return self::dupCstrBytes($context, $valueBytes);
    }

    private static function entryPtr(Context $context, Value $index): Value
    {
        $entriesGlobal = $context->module->getNamedGlobal(self::G_ENTRIES);
        if (null === $entriesGlobal) {
            throw new \LogicException('Missing getenv JIT overlay entries global');
        }
        $entriesPtr = $context->builder->pointerCast(
            $entriesGlobal,
            self::entryArrayType($context)->pointerType(0)
        );
        $rawPtr = $context->builder->gep($entriesPtr, $index);

        return $context->builder->pointerCast($rawPtr, self::entryType($context)->pointerType(0));
    }

    private static function entryType(Context $context)
    {
        $i8p = $context->getTypeFromString('int8*');

        return $context->context->structType(false, $i8p, $i8p);
    }

    private static function entryArrayType(Context $context)
    {
        return self::entryType($context)->arrayType(self::MAX_ENTRIES);
    }

    private static function globalPtr(Context $context, string $name, $type): Value
    {
        $global = $context->module->getNamedGlobal($name);
        if (null === $global) {
            throw new \LogicException('Missing getenv JIT overlay global: '.$name);
        }

        return $context->builder->pointerCast($global, $type->pointerType(0));
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
}
