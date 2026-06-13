<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM weak-reference registry for JIT/AOT (issues #3667, #5684).
 *
 * Replaces lib/AOT/runtime/phpc_weakref.c; semantics mirror {@see \PHPCompiler\VM\WeakRefRegistry}.
 * php-src: Zend/zend_weakrefs.c
 */
final class WeakRefRegistryRuntime
{
    private const MAX_REFS = 4096;

    private const MAX_MAPS = 4096;

    private const MAP_KEY_BYTES = 40;

    private const TYPEINFO_TYPEMASK = 0xFFFFFFFC;

    private const TYPEINFO_TYPE_OBJECT = 8;

    private const G_REF_COUNT = 'phpc_wr_ref_count';

    private const G_MAP_COUNT = 'phpc_wr_map_count';

    private const G_REFS = 'phpc_wr_refs';

    private const G_MAPS = 'phpc_wr_maps';

    private static int $blockSuffix = 0;

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        self::$blockSuffix = 0;
        $probe = $context->module->getNamedFunction('phpc_weakref_register_ref');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::ensureGcNotifyObjectFreed($context);
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureGlobals($context);
        self::ensureExternals($context);

        $voidTy = $context->getTypeFromString('void');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');

        $ftReset = $context->context->functionType($voidTy, false);
        $fnReset = $context->module->addFunction('phpc_weakref_reset', $ftReset);
        self::implementReset($context, $fnReset);

        $ftRegRef = $context->context->functionType($voidTy, false, $i8p, $i8p);
        $fnRegRef = $context->module->addFunction('phpc_weakref_register_ref', $ftRegRef);
        self::implementRegisterRef($context, $fnRegRef);

        $ftRegMap = $context->context->functionType($voidTy, false, $i8p, $i8p, $i8p);
        $fnRegMap = $context->module->addFunction('phpc_weakref_register_map', $ftRegMap);
        self::implementRegisterMap($context, $fnRegMap);

        $ftUnregMap = $context->context->functionType($voidTy, false, $i8p, $i8p, $i8p);
        $fnUnregMap = $context->module->addFunction('phpc_weakref_unregister_map', $ftUnregMap);
        self::implementUnregisterMap($context, $fnUnregMap);

        $ftFmt = $context->context->functionType($voidTy, false, $i8p, $i8p, $sizeT);
        $fnFmt = $context->module->addFunction('phpc_weakref_format_object_key', $ftFmt);
        self::implementFormatObjectKey($context, $fnFmt);

        $objPtr = $context->getTypeFromString('__object__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $ftResolve = $context->context->functionType($objPtr, false, $strPtr);
        $fnResolve = $context->module->addFunction('phpc_weakref_map_key_to_object', $ftResolve);
        self::implementMapKeyToObject($context, $fnResolve);

        $ftClear = $context->context->functionType($voidTy, false, $i8p);
        $fnClear = $context->module->addFunction('phpc_weakref_clear_object', $ftClear);
        self::implementClearObject($context, $fnClear);

        $ftClearTyped = $context->context->functionType($voidTy, false, $i8p, $i32);
        $fnClearTyped = $context->module->addFunction('phpc_weakref_clear_object_typed', $ftClearTyped);
        self::implementClearObjectTyped($context, $fnClearTyped, $fnClear);
        $context->registerFunction('phpc_weakref_clear_object_typed', $fnClearTyped);

        self::ensureGcNotifyObjectFreed($context);
        self::registerLinkedRuntime($context);
    }

    /**
     * GC/AOT phpc_gc.c entry — reads object typeinfo and clears weak refs (#6836).
     */
    private static function ensureGcNotifyObjectFreed(Context $context): void
    {
        $existing = $context->module->getNamedFunction('phpc_gc_notify_object_freed');
        if (null !== $existing && $existing->countBasicBlocks() > 0) {
            $context->registerFunction('phpc_gc_notify_object_freed', $existing);

            return;
        }

        $voidTy = $context->getTypeFromString('void');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($voidTy, false, $i8p);
        $fn = $context->module->addFunction('phpc_gc_notify_object_freed', $ft);

        $fnClearTyped = $context->lookupFunction('phpc_weakref_clear_object_typed');

        $entry = $fn->appendBasicBlock('gc_notify_entry');
        $doneBb = $fn->appendBasicBlock('gc_notify_done');
        $workBb = $fn->appendBasicBlock('gc_notify_work');
        $context->builder->positionAtEnd($entry);

        $obj = $fn->getParam(0);
        $null = $i8p->constNull();
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $obj, $null),
            $doneBb,
            $workBb
        );

        $context->builder->positionAtEnd($workBb);
        // phpc_object_header.ref.typeinfo follows refcount (offset 4).
        $typeinfoPtr = $context->builder->pointerCast(
            $context->builder->inBoundsGEP($obj, $i32->constInt(4, false)),
            $i32->pointerType(0)
        );
        $typeinfo = $context->builder->load($typeinfoPtr);
        $context->builder->call($fnClearTyped, $obj, $typeinfo);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();

        $context->registerFunction('phpc_gc_notify_object_freed', $fn);
    }

    private static function implementReset(Context $context, Value $fn): void
    {
        $entry = $fn->appendBasicBlock('wr_reset_entry');
        $context->builder->positionAtEnd($entry);
        $i32 = $context->getTypeFromString('int32');
        $zero = $i32->constInt(0, false);
        $context->builder->store($zero, self::globalPtr($context, self::G_REF_COUNT, $i32));
        $context->builder->store($zero, self::globalPtr($context, self::G_MAP_COUNT, $i32));
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function implementRegisterRef(Context $context, Value $fn): void
    {
        $entry = $fn->appendBasicBlock('wr_reg_ref_entry');
        $doneBb = $fn->appendBasicBlock('wr_reg_ref_done');
        $context->builder->positionAtEnd($entry);

        $target = $fn->getParam(0);
        $slot = $fn->getParam(1);
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $null = $i8p->constNull();
        $max = $i32->constInt(self::MAX_REFS, false);

        $targetNull = $context->builder->icmp(Builder::INT_EQ, $target, $null);
        $slotNull = $context->builder->icmp(Builder::INT_EQ, $slot, $null);
        $badArg = $context->builder->or($targetNull, $slotNull);

        $countPtr = self::globalPtr($context, self::G_REF_COUNT, $i32);
        $count = $context->builder->load($countPtr);
        $atMax = $context->builder->icmp(Builder::INT_SGE, $count, $max);
        $skip = $context->builder->or($badArg, $atMax);
        $workBb = $fn->appendBasicBlock('wr_reg_ref_work');
        $context->builder->branchIf($skip, $doneBb, $workBb);

        $context->builder->positionAtEnd($workBb);
        $entryPtr = self::refEntryPtr($context, $count);
        $context->builder->store($target, $context->builder->structGep($entryPtr, 0));
        $context->builder->store($slot, $context->builder->structGep($entryPtr, 1));
        $context->builder->store(
            $context->builder->add($count, $i32->constInt(1, false)),
            $countPtr
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function implementRegisterMap(Context $context, Value $fn): void
    {
        $entry = $fn->appendBasicBlock('wr_reg_map_entry');
        $doneBb = $fn->appendBasicBlock('wr_reg_map_done');
        $context->builder->positionAtEnd($entry);

        $target = $fn->getParam(0);
        $ht = $fn->getParam(1);
        $key = $fn->getParam(2);
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $null = $i8p->constNull();
        $max = $i32->constInt(self::MAX_MAPS, false);

        $bad = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $target, $null),
            $context->builder->or(
                $context->builder->icmp(Builder::INT_EQ, $ht, $null),
                $context->builder->icmp(Builder::INT_EQ, $key, $null)
            )
        );
        $countPtr = self::globalPtr($context, self::G_MAP_COUNT, $i32);
        $count = $context->builder->load($countPtr);
        $atMax = $context->builder->icmp(Builder::INT_SGE, $count, $max);
        $skip = $context->builder->or($bad, $atMax);
        $workBb = $fn->appendBasicBlock('wr_reg_map_work');
        $context->builder->branchIf($skip, $doneBb, $workBb);

        $context->builder->positionAtEnd($workBb);
        $entryPtr = self::mapEntryPtr($context, $count);
        $context->builder->store($target, $context->builder->structGep($entryPtr, 0));
        $context->builder->store($ht, $context->builder->structGep($entryPtr, 1));
        $keyField = $context->builder->structGep($entryPtr, 2);
        $keyBuf = $context->builder->pointerCast($keyField, $i8p);
        $sizeT = $context->getTypeFromString('size_t');
        $context->builder->call(
            $context->lookupFunction('snprintf'),
            $keyBuf,
            $sizeT->constInt(self::MAP_KEY_BYTES, false),
            self::literalCstr($context, '%s'),
            $key
        );
        $context->builder->store(
            $context->builder->add($count, $i32->constInt(1, false)),
            $countPtr
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function implementUnregisterMap(Context $context, Value $fn): void
    {
        $entry = $fn->appendBasicBlock('wr_unmap_entry');
        $doneBb = $fn->appendBasicBlock('wr_unmap_done');
        $loopInit = $fn->appendBasicBlock('wr_unmap_loop_init');
        $loopCond = $fn->appendBasicBlock('wr_unmap_cond');
        $context->builder->positionAtEnd($entry);

        $target = $fn->getParam(0);
        $ht = $fn->getParam(1);
        $key = $fn->getParam(2);
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $null = $i8p->constNull();
        $bad = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $target, $null),
            $context->builder->or(
                $context->builder->icmp(Builder::INT_EQ, $ht, $null),
                $context->builder->icmp(Builder::INT_EQ, $key, $null)
            )
        );
        $context->builder->branchIf($bad, $doneBb, $loopInit);
        $loopBody = $fn->appendBasicBlock('wr_unmap_body');
        $loopInc = $fn->appendBasicBlock('wr_unmap_inc');
        $context->builder->positionAtEnd($loopInit);
        $countPtr = self::globalPtr($context, self::G_MAP_COUNT, $i32);
        $count = $context->builder->load($countPtr);
        $idx = $context->builder->alloca($i32, 1, 'wr_unmap_i');
        $context->builder->store($i32->constInt(0, false), $idx);
        $context->builder->branch($loopCond);

        $context->builder->positionAtEnd($loopCond);
        $i = $context->builder->load($idx);
        $cont = $context->builder->icmp(Builder::INT_SLT, $i, $count);
        $context->builder->branchIf($cont, $loopBody, $doneBb);

        $context->builder->positionAtEnd($loopBody);
        $entryPtr = self::mapEntryPtr($context, $i);
        $storedTarget = $context->builder->load($context->builder->structGep($entryPtr, 0));
        $storedHt = $context->builder->load($context->builder->structGep($entryPtr, 1));
        $keyField = $context->builder->structGep($entryPtr, 2);
        $keyBuf = $context->builder->pointerCast($keyField, $i8p);
        $targetMatch = $context->builder->icmp(Builder::INT_EQ, $storedTarget, $target);
        $htMatch = $context->builder->icmp(Builder::INT_EQ, $storedHt, $ht);
        $keyMatch = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->call($context->lookupFunction('strcmp'), $keyBuf, $key),
            $i32->constInt(0, false)
        );
        $allMatch = $context->builder->and($targetMatch, $context->builder->and($htMatch, $keyMatch));
        $foundBb = $fn->appendBasicBlock('wr_unmap_found');
        $context->builder->branchIf($allMatch, $foundBb, $loopInc);

        $context->builder->positionAtEnd($foundBb);
        $context->builder->store($null, $context->builder->structGep($entryPtr, 0));
        $context->builder->store($null, $context->builder->structGep($entryPtr, 1));
        $i8 = $context->getTypeFromString('int8');
        $context->builder->store($i8->constInt(0, false), $keyBuf);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($loopInc);
        $context->builder->store(
            $context->builder->add($context->builder->load($idx), $i32->constInt(1, false)),
            $idx
        );
        $context->builder->branch($loopCond);

        $context->builder->positionAtEnd($doneBb);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function implementFormatObjectKey(Context $context, Value $fn): void
    {
        $entry = $fn->appendBasicBlock('wr_fmt_entry');
        $doneBb = $fn->appendBasicBlock('wr_fmt_done');
        $context->builder->positionAtEnd($entry);

        $obj = $fn->getParam(0);
        $buf = $fn->getParam(1);
        $bufLen = $fn->getParam(2);
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $null = $i8p->constNull();
        $bad = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $buf, $null),
            $context->builder->icmp(Builder::INT_EQ, $bufLen, $sizeT->constInt(0, false))
        );
        $workBb = $fn->appendBasicBlock('wr_fmt_work');
        $context->builder->branchIf($bad, $doneBb, $workBb);

        $context->builder->positionAtEnd($workBb);
        $addr = $context->builder->pointerCast($obj, $i64);
        $context->builder->call(
            $context->lookupFunction('snprintf'),
            $buf,
            $bufLen,
            self::literalCstr($context, 'o:%llx'),
            $addr
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function implementClearObjectTyped(Context $context, Value $fnClearTyped, Value $fnClear): void
    {
        $entry = $fnClearTyped->appendBasicBlock('wr_clear_t_entry');
        $doneBb = $fnClearTyped->appendBasicBlock('wr_clear_t_done');
        $checkBb = $fnClearTyped->appendBasicBlock('wr_clear_t_check');
        $workBb = $fnClearTyped->appendBasicBlock('wr_clear_t_work');
        $context->builder->positionAtEnd($entry);

        $target = $fnClearTyped->getParam(0);
        $typeinfo = $fnClearTyped->getParam(1);
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $null = $i8p->constNull();
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $target, $null),
            $doneBb,
            $checkBb
        );
        $context->builder->positionAtEnd($checkBb);
        $masked = $context->builder->and(
            $typeinfo,
            $i32->constInt(self::TYPEINFO_TYPEMASK, false)
        );
        $isObject = $context->builder->icmp(
            Builder::INT_EQ,
            $masked,
            $i32->constInt(self::TYPEINFO_TYPE_OBJECT, false)
        );
        $context->builder->branchIf($isObject, $workBb, $doneBb);

        $context->builder->positionAtEnd($workBb);
        $context->builder->call($fnClear, $target);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function implementMapKeyToObject(Context $context, Value $fn): void
    {
        $entry = $fn->appendBasicBlock('wr_resolve_entry');
        $exitBb = $fn->appendBasicBlock('wr_resolve_exit');
        $context->builder->positionAtEnd($entry);

        $keyStr = $fn->getParam(0);
        $objPtrTy = $context->getTypeFromString('__object__*');
        $strNull = $keyStr->typeOf()->constNull();
        $nullObj = $objPtrTy->constNull();
        $strMap = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');

        $resultSlot = $context->builder->alloca($objPtrTy, 1, 'wr_resolve_result');
        $context->builder->store($nullObj, $resultSlot);

        $keyNull = $context->builder->icmp(Builder::INT_EQ, $keyStr, $strNull);
        $checkLen = $fn->appendBasicBlock('wr_resolve_check_len');
        $context->builder->branchIf($keyNull, $exitBb, $checkLen);

        $context->builder->positionAtEnd($checkLen);
        $len = $context->builder->load($context->builder->structGep($keyStr, $strMap['length']));
        $len64 = $context->builder->zExt($len, $i64);
        $tooShort = $context->builder->icmp(Builder::INT_ULT, $len64, $i64->constInt(3, false));
        $checkPrefix = $fn->appendBasicBlock('wr_resolve_check_prefix');
        $context->builder->branchIf($tooShort, $exitBb, $checkPrefix);

        $context->builder->positionAtEnd($checkPrefix);
        $bytes = $context->builder->structGep($keyStr, $strMap['value']);
        $dataPtr = $context->builder->pointerCast($bytes, $i8p);
        $oByte = $context->builder->load($dataPtr);
        $colonByte = $context->builder->load($context->builder->inBoundsGep($dataPtr, $i64->constInt(1, false)));
        $isO = $context->builder->icmp(Builder::INT_EQ, $oByte, $i8->constInt(ord('o'), false));
        $isColon = $context->builder->icmp(Builder::INT_EQ, $colonByte, $i8->constInt(ord(':'), false));
        $prefixOk = $context->builder->and($isO, $isColon);
        $parseBb = $fn->appendBasicBlock('wr_resolve_parse');
        $context->builder->branchIf($prefixOk, $parseBb, $exitBb);

        $context->builder->positionAtEnd($parseBb);
        $suffixPtr = $context->builder->inBoundsGep($dataPtr, $i64->constInt(2, false));
        $endPtr = $context->builder->alloca($i8p, 1, 'wr_resolve_end');
        $context->builder->store($i8p->constNull(), $endPtr);
        $handle = $context->builder->call(
            $context->lookupFunction('strtoull'),
            $suffixPtr,
            $endPtr,
            $i32->constInt(16, false)
        );
        $obj = $context->builder->intToPtr($handle, $objPtrTy);
        $context->builder->store($obj, $resultSlot);
        $context->builder->branch($exitBb);

        $context->builder->positionAtEnd($exitBb);
        $context->builder->returnValue($context->builder->load($resultSlot));
        $context->builder->clearInsertionPosition();
    }

    private static function implementClearObject(Context $context, Value $fn): void
    {
        $entry = $fn->appendBasicBlock('wr_clear_entry');
        $doneBb = $fn->appendBasicBlock('wr_clear_done');
        $refsInit = $fn->appendBasicBlock('wr_clear_refs_init');
        $mapsInit = $fn->appendBasicBlock('wr_clear_maps_init');
        $context->builder->positionAtEnd($entry);

        $target = $fn->getParam(0);
        $i8p = $context->getTypeFromString('int8*');
        $null = $i8p->constNull();
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $target, $null),
            $doneBb,
            $refsInit
        );

        self::emitClearRefLoop($context, $fn, $target, $refsInit, $mapsInit);
        self::emitClearMapLoop($context, $fn, $target, $mapsInit, $doneBb);

        $context->builder->positionAtEnd($doneBb);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function emitClearRefLoop(
        Context $context,
        Value $fn,
        Value $target,
        $loopInit,
        $afterBb,
    ): void {
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $null = $i8p->constNull();
        $valuePtr = $context->getTypeFromString('__value__*');
        $writeNull = $context->lookupFunction('__value__writeNull');

        $loopCond = $fn->appendBasicBlock('wr_clear_refs_cond');
        $loopBody = $fn->appendBasicBlock('wr_clear_refs_body');
        $loopInc = $fn->appendBasicBlock('wr_clear_refs_inc');

        $context->builder->positionAtEnd($loopInit);
        $countPtr = self::globalPtr($context, self::G_REF_COUNT, $i32);
        $count = $context->builder->load($countPtr);
        $idx = $context->builder->alloca($i32, 1, 'wr_clear_ref_i');
        $context->builder->store($i32->constInt(0, false), $idx);
        $context->builder->branch($loopCond);

        $context->builder->positionAtEnd($loopCond);
        $i = $context->builder->load($idx);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLT, $i, $count),
            $loopBody,
            $afterBb
        );

        $context->builder->positionAtEnd($loopBody);
        $entryPtr = self::refEntryPtr($context, $i);
        $storedTarget = $context->builder->load($context->builder->structGep($entryPtr, 0));
        $storedSlot = $context->builder->load($context->builder->structGep($entryPtr, 1));
        $targetMatch = $context->builder->icmp(Builder::INT_EQ, $storedTarget, $target);
        $slotNonNull = $context->builder->icmp(Builder::INT_NE, $storedSlot, $null);
        $doClear = $context->builder->and($targetMatch, $slotNonNull);
        $clearBb = $fn->appendBasicBlock('wr_clear_refs_do');
        $context->builder->branchIf($doClear, $clearBb, $loopInc);

        $context->builder->positionAtEnd($clearBb);
        $slotAsValue = $context->builder->pointerCast($storedSlot, $valuePtr);
        $context->builder->call($writeNull, $slotAsValue);
        $context->builder->store($null, $context->builder->structGep($entryPtr, 0));
        $context->builder->store($null, $context->builder->structGep($entryPtr, 1));
        $context->builder->branch($loopInc);

        $context->builder->positionAtEnd($loopInc);
        $context->builder->store(
            $context->builder->add($context->builder->load($idx), $i32->constInt(1, false)),
            $idx
        );
        $context->builder->branch($loopCond);
    }

    private static function emitClearMapLoop(
        Context $context,
        Value $fn,
        Value $target,
        $loopInit,
        $doneBb,
    ): void {
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $null = $i8p->constNull();
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $unsetKey = $context->lookupFunction('__hashtable__unsetStringKey');
        $strInit = $context->lookupFunction('__string__init');

        $loopCond = $fn->appendBasicBlock('wr_clear_maps_cond');
        $loopBody = $fn->appendBasicBlock('wr_clear_maps_body');
        $loopInc = $fn->appendBasicBlock('wr_clear_maps_inc');

        $context->builder->positionAtEnd($loopInit);
        $countPtr = self::globalPtr($context, self::G_MAP_COUNT, $i32);
        $count = $context->builder->load($countPtr);
        $idx = $context->builder->alloca($i32, 1, 'wr_clear_map_i');
        $context->builder->store($i32->constInt(0, false), $idx);
        $context->builder->branch($loopCond);

        $context->builder->positionAtEnd($loopCond);
        $i = $context->builder->load($idx);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLT, $i, $count),
            $loopBody,
            $doneBb
        );

        $context->builder->positionAtEnd($loopBody);
        $entryPtr = self::mapEntryPtr($context, $i);
        $storedTarget = $context->builder->load($context->builder->structGep($entryPtr, 0));
        $storedHt = $context->builder->load($context->builder->structGep($entryPtr, 1));
        $keyField = $context->builder->structGep($entryPtr, 2);
        $targetMatch = $context->builder->icmp(Builder::INT_EQ, $storedTarget, $target);
        $htNonNull = $context->builder->icmp(Builder::INT_NE, $storedHt, $null);
        $doClear = $context->builder->and($targetMatch, $htNonNull);
        $clearBb = $fn->appendBasicBlock('wr_clear_maps_do');
        $context->builder->branchIf($doClear, $clearBb, $loopInc);

        $context->builder->positionAtEnd($clearBb);
        $keyBuf = $context->builder->pointerCast($keyField, $i8p);
        $keyLen = $context->builder->call($context->lookupFunction('strlen'), $keyBuf);
        $keyStr = $context->builder->call(
            $strInit,
            $context->builder->sext($keyLen, $i64),
            $keyBuf
        );
        $context->builder->call(
            $unsetKey,
            $context->builder->pointerCast($storedHt, $htPtr),
            $keyStr
        );
        $context->builder->store($null, $context->builder->structGep($entryPtr, 0));
        $context->builder->store($null, $context->builder->structGep($entryPtr, 1));
        $i8 = $context->getTypeFromString('int8');
        $context->builder->store($i8->constInt(0, false), $keyBuf);
        $context->builder->branch($loopInc);

        $context->builder->positionAtEnd($loopInc);
        $context->builder->store(
            $context->builder->add($context->builder->load($idx), $i32->constInt(1, false)),
            $idx
        );
        $context->builder->branch($loopCond);
    }

    private static function refEntryPtr(Context $context, Value $index): Value
    {
        $refsGlobal = $context->module->getNamedGlobal(self::G_REFS);
        if (null === $refsGlobal) {
            throw new \LogicException('WeakRefRegistryRuntime refs global missing');
        }
        $refsPtr = $context->builder->pointerCast($refsGlobal, self::refArrayType($context)->pointerType(0));
        $entryPtr = $context->builder->gep($refsPtr, $index);

        return $context->builder->pointerCast($entryPtr, self::refEntryType($context)->pointerType(0));
    }

    private static function mapEntryPtr(Context $context, Value $index): Value
    {
        $mapsGlobal = $context->module->getNamedGlobal(self::G_MAPS);
        if (null === $mapsGlobal) {
            throw new \LogicException('WeakRefRegistryRuntime maps global missing');
        }
        $mapsPtr = $context->builder->pointerCast($mapsGlobal, self::mapArrayType($context)->pointerType(0));
        $entryPtr = $context->builder->gep($mapsPtr, $index);

        return $context->builder->pointerCast($entryPtr, self::mapEntryType($context)->pointerType(0));
    }

    private static function refEntryType(Context $context)
    {
        $i8p = $context->getTypeFromString('int8*');

        return $context->context->structType(false, $i8p, $i8p);
    }

    private static function refArrayType(Context $context)
    {
        return self::refEntryType($context)->arrayType(self::MAX_REFS);
    }

    private static function mapEntryType(Context $context)
    {
        $i8p = $context->getTypeFromString('int8*');
        $i8 = $context->getTypeFromString('int8');

        return $context->context->structType(false, $i8p, $i8p, $i8->arrayType(self::MAP_KEY_BYTES));
    }

    private static function mapArrayType(Context $context)
    {
        return self::mapEntryType($context)->arrayType(self::MAX_MAPS);
    }

    private static function literalCstr(Context $context, string $text): Value
    {
        $i8p = $context->getTypeFromString('int8*');

        return $context->builder->pointerCast($context->constantFromString($text), $i8p);
    }

    private static function globalPtr(Context $context, string $name, $llvmType): Value
    {
        $global = $context->module->getNamedGlobal($name);
        if (null === $global) {
            throw new \LogicException('WeakRefRegistryRuntime global missing: '.$name);
        }

        return $context->builder->pointerCast($global, $llvmType->pointerType(0));
    }

    private static function ensureGlobals(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');

        if (null === $context->module->getNamedGlobal(self::G_REF_COUNT)) {
            $g = $context->module->addGlobal($i32, self::G_REF_COUNT);
            $g->setInitializer($i32->constInt(0, false));
        }
        if (null === $context->module->getNamedGlobal(self::G_MAP_COUNT)) {
            $g = $context->module->addGlobal($i32, self::G_MAP_COUNT);
            $g->setInitializer($i32->constInt(0, false));
        }
        if (null === $context->module->getNamedGlobal(self::G_REFS)) {
            $refsTy = self::refArrayType($context);
            $g = $context->module->addGlobal($refsTy, self::G_REFS);
            $g->setInitializer($refsTy->constNull());
        }
        if (null === $context->module->getNamedGlobal(self::G_MAPS)) {
            $mapsTy = self::mapArrayType($context);
            $g = $context->module->addGlobal($mapsTy, self::G_MAPS);
            $g->setInitializer($mapsTy->constNull());
        }
    }

    private static function ensureExternals(Context $context): void
    {
        $voidTy = $context->getTypeFromString('void');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');

        self::ensureExternal(
            $context,
            'snprintf',
            $context->context->functionType($i32, true, $i8p, $sizeT, $i8p, $i64)
        );
        self::ensureExternal(
            $context,
            'strcmp',
            $context->context->functionType($i32, false, $i8p, $i8p)
        );
        self::ensureExternal(
            $context,
            'strlen',
            $context->context->functionType($sizeT, false, $i8p)
        );
        self::ensureExternal(
            $context,
            'strtoull',
            $context->context->functionType($i64, false, $i8p, $i8p->pointerType(0), $i32)
        );
        self::ensureExternal(
            $context,
            '__value__writeNull',
            $context->context->functionType($voidTy, false, $valuePtr)
        );
        self::ensureExternal(
            $context,
            '__hashtable__unsetStringKey',
            $context->context->functionType($voidTy, false, $htPtr, $strPtr)
        );
        self::ensureExternal(
            $context,
            '__string__init',
            $context->context->functionType($strPtr, false, $i64, $i8p)
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
        foreach (
            [
                'phpc_weakref_reset',
                'phpc_weakref_register_ref',
                'phpc_weakref_register_map',
                'phpc_weakref_unregister_map',
                'phpc_weakref_clear_object',
                'phpc_weakref_clear_object_typed',
                'phpc_weakref_format_object_key',
                'phpc_weakref_map_key_to_object',
                'phpc_gc_notify_object_freed',
            ] as $name
        ) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after WeakRefRegistryRuntime LLVM implement');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
