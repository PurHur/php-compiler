<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM putenv()/getenv() local environment overlay (issue #3710, #5345).
 *
 * Replaces {@see lib/AOT/runtime/phpc_env_local.c}. Semantics mirror
 * {@see \PHPCompiler\ext\standard\VmEnv} and php-src EG(env).
 */
final class EnvLocalStandaloneLlvm
{
    private const MAX_ENTRIES = 256;

    private const G_ENTRIES = 'phpc_env_local_entries';

    private const G_COUNT = 'phpc_env_local_count';

    private static int $blockSuffix = 0;

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_env_local_lookup',
        '__compiler_env_register_putenv',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        self::$blockSuffix = 0;
        $probe = $context->module->getNamedFunction('__compiler_env_local_lookup');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureGlobals($context);
        self::ensureLibc($context);

        $i8p = $context->getTypeFromString('int8*');
        $voidTy = $context->getTypeFromString('void');

        $lookupProbe = $context->module->getNamedFunction('__compiler_env_local_lookup');
        $ftLookup = $context->context->functionType($i8p, false, $i8p);
        $fnLookup = null !== $lookupProbe
            ? $lookupProbe
            : $context->module->addFunction('__compiler_env_local_lookup', $ftLookup);
        self::implementLookup($context, $fnLookup);

        $registerProbe = $context->module->getNamedFunction('__compiler_env_register_putenv');
        $ftRegister = $context->context->functionType($voidTy, false, $i8p);
        $fnRegister = null !== $registerProbe
            ? $registerProbe
            : $context->module->addFunction('__compiler_env_register_putenv', $ftRegister);
        self::implementRegisterPutenv($context, $fnRegister);

        self::registerLinkedRuntime($context);
    }

    private static function implementLookup(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('el_lookup_entry');
        $missBb = $fn->appendBasicBlock('el_lookup_miss');
        $foundBb = $fn->appendBasicBlock('el_lookup_found');
        $doneBb = $fn->appendBasicBlock('el_lookup_done');
        $context->builder->positionAtEnd($entry);

        $name = $fn->getParam(0);
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $null = $i8p->constNull();

        $idxSlot = $context->builder->alloca($i32, 1, 'el_lookup_i');
        $context->builder->store($i32->constInt(0, false), $idxSlot);

        $nameNull = $context->builder->icmp(Builder::INT_EQ, $name, $null);
        $loopHead = $fn->appendBasicBlock('el_lookup_head');
        $context->builder->branchIf($nameNull, $missBb, $loopHead);

        $loopCheck = $fn->appendBasicBlock('el_lookup_check');
        $loopBody = $fn->appendBasicBlock('el_lookup_body');
        $loopNext = $fn->appendBasicBlock('el_lookup_next');
        $context->builder->positionAtEnd($loopHead);
        $context->builder->branch($loopCheck);

        $context->builder->positionAtEnd($loopCheck);
        $idx = $context->builder->load($idxSlot);
        $count = $context->builder->load(self::globalPtr($context, self::G_COUNT, $i32));
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $count);
        $context->builder->branchIf($atEnd, $missBb, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $entryPtr = self::entryPtr($context, $idx);
        $entryName = $context->builder->load($context->builder->structGep($entryPtr, 0));
        $cmp = $context->builder->call($context->lookupFunction('strcmp'), $entryName, $name);
        $isMatch = $context->builder->icmp(Builder::INT_EQ, $cmp, $i32->constInt(0, false));
        $context->builder->branchIf($isMatch, $foundBb, $loopNext);

        $context->builder->positionAtEnd($loopNext);
        $context->builder->store(
            $context->builder->add($idx, $i32->constInt(1, false)),
            $idxSlot
        );
        $context->builder->branch($loopCheck);

        $context->builder->positionAtEnd($foundBb);
        $valuePtr = $context->builder->load($context->builder->structGep($entryPtr, 1));
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($missBb);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $result = $context->builder->phi($i8p);
        $result->addIncoming($null, $missBb);
        $result->addIncoming($valuePtr, $foundBb);
        $context->builder->returnValue($result);
        $context->builder->clearInsertionPosition();
    }

    private static function implementRegisterPutenv(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('el_reg_entry');
        $doneBb = $fn->appendBasicBlock('el_reg_done');
        $context->builder->positionAtEnd($entry);

        $setting = $fn->getParam(0);
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $null = $i8p->constNull();
        $eqChar = $i32->constInt(ord('='), false);

        $nameSlot = $context->builder->alloca($i8p, 1, 'el_reg_name');
        $valueSlot = $context->builder->alloca($i8p, 1, 'el_reg_value');
        $unsetSlot = $context->builder->alloca($i32, 1, 'el_reg_unset');

        $badBb = $fn->appendBasicBlock('el_reg_bad');
        $parseBb = $fn->appendBasicBlock('el_reg_parse');
        $settingNull = $context->builder->icmp(Builder::INT_EQ, $setting, $null);
        $context->builder->branchIf($settingNull, $badBb, $parseBb);

        $context->builder->positionAtEnd($parseBb);
        $eq = $context->builder->call($context->lookupFunction('strchr'), $setting, $eqChar);
        $noEqBb = $fn->appendBasicBlock('el_reg_no_eq');
        $hasEqBb = $fn->appendBasicBlock('el_reg_has_eq');
        $afterParse = $fn->appendBasicBlock('el_reg_after_parse');
        $eqNull = $context->builder->icmp(Builder::INT_EQ, $eq, $null);
        $context->builder->branchIf($eqNull, $noEqBb, $hasEqBb);

        $context->builder->positionAtEnd($noEqBb);
        $context->builder->store(self::dupCstr($context, $setting), $nameSlot);
        $context->builder->store($null, $valueSlot);
        $context->builder->store($i32->constInt(1, false), $unsetSlot);
        $context->builder->branch($afterParse);

        $context->builder->positionAtEnd($hasEqBb);
        $nameLen = $context->builder->sub(
            $context->builder->ptrToInt($eq, $sizeT),
            $context->builder->ptrToInt($setting, $sizeT)
        );
        $nameBuf = $context->builder->call(
            $context->lookupFunction('malloc'),
            $context->builder->add($nameLen, $sizeT->constInt(1, false))
        );
        $nameCstr = $context->builder->pointerCast($nameBuf, $i8p);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $nameCstr,
            $setting,
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

        $emptyNameBb = $fn->appendBasicBlock('el_reg_empty_name');
        $applyBb = $fn->appendBasicBlock('el_reg_apply');
        $afterRemoveBb = $fn->appendBasicBlock('el_reg_after_remove');
        $unsetBb = $fn->appendBasicBlock('el_reg_unset_only');
        $setBb = $fn->appendBasicBlock('el_reg_set');

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
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($setBb);
        self::emitSetEntry(
            $context,
            $fn,
            $context->builder->load($nameSlot),
            $context->builder->load($valueSlot)
        );
        $context->builder->call($context->lookupFunction('free'), $context->builder->load($nameSlot));
        $context->builder->call($context->lookupFunction('free'), $context->builder->load($valueSlot));
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($badBb);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
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

        $lastIndex = $context->builder->sub($count, $one);
        $afterSwap = $fn->appendBasicBlock('el_remove_swap_'.self::$blockSuffix);
        $skipSwap = $fn->appendBasicBlock('el_remove_skip_'.self::$blockSuffix);
        $needSwap = $context->builder->icmp(Builder::INT_SLT, $idx, $lastIndex);
        $context->builder->branchIf($needSwap, $afterSwap, $skipSwap);

        $context->builder->positionAtEnd($afterSwap);
        $lastPtr = self::entryPtr($context, $lastIndex);
        $context->builder->store(
            $context->builder->load($context->builder->structGep($lastPtr, 0)),
            $context->builder->structGep($entryPtr, 0)
        );
        $context->builder->store(
            $context->builder->load($context->builder->structGep($lastPtr, 1)),
            $context->builder->structGep($entryPtr, 1)
        );
        $context->builder->store($null, $context->builder->structGep($lastPtr, 0));
        $context->builder->store($null, $context->builder->structGep($lastPtr, 1));
        $context->builder->branch($skipSwap);

        $context->builder->positionAtEnd($skipSwap);
        $context->builder->store($context->builder->sub($count, $one), self::globalPtr($context, self::G_COUNT, $i32));
        $context->builder->branch($exitBb);
    }

    private static function emitSetEntry(Context $context, LlvmFunction $fn, Value $name, Value $value): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $countPtr = self::globalPtr($context, self::G_COUNT, $i32);
        $count = $context->builder->load($countPtr);
        $max = $i32->constInt(self::MAX_ENTRIES, false);
        $atMax = $context->builder->icmp(Builder::INT_SGE, $count, $max);
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

    private static function dupCstr(Context $context, Value $src): Value
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

    private static function entryPtr(Context $context, Value $index): Value
    {
        $entriesGlobal = $context->module->getNamedGlobal(self::G_ENTRIES);
        if (null === $entriesGlobal) {
            throw new \LogicException('Missing env local entries global');
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

    private static function ensureGlobals(Context $context): void
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

    private static function ensureLibc(Context $context): void
    {
        $voidPtr = $context->getTypeFromString('void*');
        $voidTy = $context->getTypeFromString('void');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');

        self::ensureExternal($context, 'malloc', $context->context->functionType($voidPtr, false, $sizeT));
        self::ensureExternal($context, 'free', $context->context->functionType($voidTy, false, $voidPtr));
        self::ensureExternal(
            $context,
            'memcpy',
            $context->context->functionType($voidPtr, false, $voidPtr, $voidPtr, $sizeT)
        );
        self::ensureExternal($context, 'strlen', $context->context->functionType($sizeT, false, $i8p));
        self::ensureExternal($context, 'strcmp', $context->context->functionType($i32, false, $i8p, $i8p));
        self::ensureExternal($context, 'strchr', $context->context->functionType($i8p, false, $i8p, $i32));
    }

    private static function ensureExternal(Context $context, string $name, $fnType): void
    {
        try {
            $context->lookupFunction($name);
        } catch (\Throwable) {
            $fn = $context->module->addFunction($name, $fnType);
            $context->registerFunction($name, $fn);
        }
    }

    private static function globalPtr(Context $context, string $name, $type): Value
    {
        $global = $context->module->getNamedGlobal($name);
        if (null === $global) {
            throw new \LogicException('Missing env local global: '.$name);
        }

        return $context->builder->pointerCast($global, $type->pointerType(0));
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after env local LLVM link');
            }
            $context->registerFunction($name, $fn);
        }
    }

    public static function emitMergeOverlay(Context $context, Value $ht): void
    {
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
}
