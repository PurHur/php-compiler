<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for phpc_weakref_* registry via WeakRefRegistryJitHelper PHP (#9191).
 *
 * Replaces lib/AOT/runtime/phpc_weakref.c; registry storage in WeakRefRegistryJitHelper PHP (#9191).
 * php-src: Zend/zend_weakrefs.c
 */
final class WeakRefRegistryRuntime
{
    private const TYPEINFO_TYPEMASK = 0xFFFFFFFC;

    private const TYPEINFO_TYPE_OBJECT = 8;

    private const HELPER_PATH = '/ext/standard/WeakRefRegistryJitHelper.php';

    private const RESET = 'PHPCompiler\\ext\\standard\\WeakRefRegistryJitHelper::reset';

    private const REGISTER_REF = 'PHPCompiler\\ext\\standard\\WeakRefRegistryJitHelper::registerRef';

    private const REGISTER_MAP = 'PHPCompiler\\ext\\standard\\WeakRefRegistryJitHelper::registerMap';

    private const UNREGISTER_MAP = 'PHPCompiler\\ext\\standard\\WeakRefRegistryJitHelper::unregisterMap';

    private const FORMAT_KEY = 'PHPCompiler\\ext\\standard\\WeakRefRegistryJitHelper::formatObjectKey';

    private const MAP_KEY_TO_OBJECT = 'PHPCompiler\\ext\\standard\\WeakRefRegistryJitHelper::mapKeyToObjectPtr';

    private const REF_COUNT = 'PHPCompiler\\ext\\standard\\WeakRefRegistryJitHelper::refCount';

    private const REF_TARGET = 'PHPCompiler\\ext\\standard\\WeakRefRegistryJitHelper::refTargetPtr';

    private const REF_SLOT = 'PHPCompiler\\ext\\standard\\WeakRefRegistryJitHelper::refSlotPtr';

    private const CLEAR_REF = 'PHPCompiler\\ext\\standard\\WeakRefRegistryJitHelper::clearRefEntry';

    private const MAP_COUNT = 'PHPCompiler\\ext\\standard\\WeakRefRegistryJitHelper::mapCount';

    private const MAP_TARGET = 'PHPCompiler\\ext\\standard\\WeakRefRegistryJitHelper::mapTargetPtr';

    private const MAP_HT = 'PHPCompiler\\ext\\standard\\WeakRefRegistryJitHelper::mapHtPtr';

    private const MAP_KEY = 'PHPCompiler\\ext\\standard\\WeakRefRegistryJitHelper::mapKey';

    private const CLEAR_MAP = 'PHPCompiler\\ext\\standard\\WeakRefRegistryJitHelper::clearMapEntry';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::RESET,
        self::REGISTER_REF,
        self::REGISTER_MAP,
        self::UNREGISTER_MAP,
        self::FORMAT_KEY,
        self::MAP_KEY_TO_OBJECT,
        self::REF_COUNT,
        self::REF_TARGET,
        self::REF_SLOT,
        self::CLEAR_REF,
        self::MAP_COUNT,
        self::MAP_TARGET,
        self::MAP_HT,
        self::MAP_KEY,
        self::CLEAR_MAP,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('phpc_weakref_register_ref');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::ensureGcNotifyObjectFreed($context);
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureExternals($context);
        self::ensureJitHelperCompiled($context);

        self::implementResetBridge($context);
        self::implementRegisterRefBridge($context);
        self::implementRegisterMapBridge($context);
        self::implementUnregisterMapBridge($context);
        self::implementFormatObjectKeyBridge($context);
        self::implementMapKeyToObjectBridge($context);
        self::implementClearObjectBridge($context);
        self::implementClearObjectTypedBridge($context);
        self::ensureGcNotifyObjectFreed($context);
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    private static function implementResetBridge(Context $context): void
    {
        $abiName = 'phpc_weakref_reset';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false);
        $fn = null !== $probe ? $probe : $context->module->addFunction($abiName, $ft);
        $entry = $fn->appendBasicBlock('wr_reset_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $context->builder->call(self::helperFunction($context, self::RESET));
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function implementRegisterRefBridge(Context $context): void
    {
        $abiName = 'phpc_weakref_register_ref';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $voidTy = $context->getTypeFromString('void');
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($voidTy, false, $i8p, $i8p);
        $fn = null !== $probe ? $probe : $context->module->addFunction($abiName, $ft);
        $entry = $fn->appendBasicBlock('wr_reg_ref_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $target = $context->builder->pointerCast($fn->getParam(0), $i64);
        $slot = $context->builder->pointerCast($fn->getParam(1), $i64);
        $context->builder->call(self::helperFunction($context, self::REGISTER_REF), $target, $slot);
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function implementRegisterMapBridge(Context $context): void
    {
        self::implementMapMutationBridge($context, 'phpc_weakref_register_map', self::REGISTER_MAP);
    }

    private static function implementUnregisterMapBridge(Context $context): void
    {
        self::implementMapMutationBridge($context, 'phpc_weakref_unregister_map', self::UNREGISTER_MAP);
    }

    private static function implementMapMutationBridge(Context $context, string $abiName, string $helperLogical): void
    {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $voidTy = $context->getTypeFromString('void');
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType($voidTy, false, $i8p, $i8p, $i8p);
        $fn = null !== $probe ? $probe : $context->module->addFunction($abiName, $ft);
        $entry = $fn->appendBasicBlock('wr_map_mut_bridge_entry');
        $context->builder->positionAtEnd($entry);

        $target = $context->builder->pointerCast($fn->getParam(0), $i64);
        $ht = $context->builder->pointerCast($fn->getParam(1), $i64);
        $keyCstr = $fn->getParam(2);
        $keyStr = self::cstrToString($context, $keyCstr);
        $context->builder->call(self::helperFunction($context, $helperLogical), $target, $ht, $keyStr);
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function implementFormatObjectKeyBridge(Context $context): void
    {
        $abiName = 'phpc_weakref_format_object_key';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $voidTy = $context->getTypeFromString('void');
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $ft = $context->context->functionType($voidTy, false, $i8p, $i8p, $sizeT);
        $fn = null !== $probe ? $probe : $context->module->addFunction($abiName, $ft);
        $entry = $fn->appendBasicBlock('wr_fmt_bridge_entry');
        $doneBb = $fn->appendBasicBlock('wr_fmt_bridge_done');
        $workBb = $fn->appendBasicBlock('wr_fmt_bridge_work');
        $context->builder->positionAtEnd($entry);

        $buf = $fn->getParam(1);
        $bufLen = $fn->getParam(2);
        $null = $i8p->constNull();
        $bad = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $buf, $null),
            $context->builder->icmp(Builder::INT_EQ, $bufLen, $sizeT->constInt(0, false))
        );
        $context->builder->branchIf($bad, $doneBb, $workBb);

        $context->builder->positionAtEnd($workBb);
        $objPtr = $context->builder->pointerCast($fn->getParam(0), $i64);
        $formatted = $context->builder->call(self::helperFunction($context, self::FORMAT_KEY), $objPtr);
        $context->builder->call(
            $context->lookupFunction('snprintf'),
            $buf,
            $bufLen,
            self::literalCstr($context, '%s'),
            $formatted
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function implementMapKeyToObjectBridge(Context $context): void
    {
        $abiName = 'phpc_weakref_map_key_to_object';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $objPtrTy = $context->getTypeFromString('__object__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($objPtrTy, false, $strPtr);
        $fn = null !== $probe ? $probe : $context->module->addFunction($abiName, $ft);
        $entry = $fn->appendBasicBlock('wr_resolve_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $handle = $context->builder->call(
            self::helperFunction($context, self::MAP_KEY_TO_OBJECT),
            $fn->getParam(0)
        );
        $context->builder->returnValue($context->builder->intToPtr($handle, $objPtrTy));
        $context->registerFunction($abiName, $fn);
    }

    private static function implementClearObjectTypedBridge(Context $context): void
    {
        $abiName = 'phpc_weakref_clear_object_typed';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $voidTy = $context->getTypeFromString('void');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($voidTy, false, $i8p, $i32);
        $fn = null !== $probe ? $probe : $context->module->addFunction($abiName, $ft);
        $entry = $fn->appendBasicBlock('wr_clear_t_bridge_entry');
        $doneBb = $fn->appendBasicBlock('wr_clear_t_bridge_done');
        $checkBb = $fn->appendBasicBlock('wr_clear_t_bridge_check');
        $workBb = $fn->appendBasicBlock('wr_clear_t_bridge_work');
        $context->builder->positionAtEnd($entry);

        $target = $fn->getParam(0);
        $typeinfo = $fn->getParam(1);
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
        $fnClear = $context->lookupFunction('phpc_weakref_clear_object');
        $context->builder->branchIf($isObject, $workBb, $doneBb);

        $context->builder->positionAtEnd($workBb);
        $context->builder->call($fnClear, $target);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function implementClearObjectBridge(Context $context): void
    {
        $abiName = 'phpc_weakref_clear_object';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $voidTy = $context->getTypeFromString('void');
        $i8p = $context->getTypeFromString('int8*');
        $ft = $context->context->functionType($voidTy, false, $i8p);
        $fn = null !== $probe ? $probe : $context->module->addFunction($abiName, $ft);
        $entry = $fn->appendBasicBlock('wr_clear_bridge_entry');
        $doneBb = $fn->appendBasicBlock('wr_clear_bridge_done');
        $refsInit = $fn->appendBasicBlock('wr_clear_bridge_refs_init');
        $mapsInit = $fn->appendBasicBlock('wr_clear_bridge_maps_init');
        $context->builder->positionAtEnd($entry);

        $target = $fn->getParam(0);
        $null = $i8p->constNull();
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $target, $null),
            $doneBb,
            $refsInit
        );
        $context->builder->clearInsertionPosition();

        self::emitClearRefLoop($context, $fn, $target, $refsInit, $mapsInit);
        self::emitClearMapLoop($context, $fn, $target, $mapsInit, $doneBb);

        $context->builder->positionAtEnd($doneBb);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
        $context->registerFunction($abiName, $fn);
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

    private static function emitClearRefLoop(
        Context $context,
        Value $fn,
        Value $target,
        $loopInit,
        $afterBb,
    ): void {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $null = $i8p->constNull();
        $valuePtr = $context->getTypeFromString('__value__*');
        $writeNull = $context->lookupFunction('__value__writeNull');
        $targetI64 = $context->builder->pointerCast($target, $i64);

        $loopCond = $fn->appendBasicBlock('wr_clear_refs_cond');
        $loopBody = $fn->appendBasicBlock('wr_clear_refs_body');
        $clearBb = $fn->appendBasicBlock('wr_clear_refs_do');
        $loopInc = $fn->appendBasicBlock('wr_clear_refs_inc');

        $context->builder->positionAtEnd($loopInit);
        $count = $context->builder->call(self::helperFunction($context, self::REF_COUNT));
        $countI32 = $context->builder->trunc($count, $i32);
        $idx = $context->builder->alloca($i32, 1, 'wr_clear_ref_i');
        $context->builder->store($i32->constInt(0, false), $idx);
        $context->builder->branch($loopCond);

        $context->builder->positionAtEnd($loopCond);
        $i = $context->builder->load($idx);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLT, $i, $countI32),
            $loopBody,
            $afterBb
        );

        $context->builder->positionAtEnd($loopBody);
        $i64Idx = $context->builder->sext($i, $i64);
        $storedTarget = $context->builder->call(self::helperFunction($context, self::REF_TARGET), $i64Idx);
        $storedSlot = $context->builder->call(self::helperFunction($context, self::REF_SLOT), $i64Idx);
        $targetMatch = $context->builder->icmp(Builder::INT_EQ, $storedTarget, $targetI64);
        $slotNonNull = $context->builder->icmp(Builder::INT_NE, $storedSlot, $i64->constInt(0, false));
        $doClear = $context->builder->and($targetMatch, $slotNonNull);
        $context->builder->branchIf($doClear, $clearBb, $loopInc);

        $context->builder->positionAtEnd($clearBb);
        $slotAsValue = $context->builder->pointerCast(
            $context->builder->intToPtr($storedSlot, $i8p),
            $valuePtr
        );
        $context->builder->call($writeNull, $slotAsValue);
        $context->builder->call(self::helperFunction($context, self::CLEAR_REF), $i64Idx);
        $context->builder->branch($loopInc);

        $context->builder->positionAtEnd($loopInc);
        $context->builder->store(
            $context->builder->add($context->builder->load($idx), $i32->constInt(1, false)),
            $idx
        );
        $context->builder->branch($loopCond);
        $context->builder->clearInsertionPosition();
    }

    private static function emitClearMapLoop(
        Context $context,
        Value $fn,
        Value $target,
        $loopInit,
        $doneBb,
    ): void {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $null = $i8p->constNull();
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $unsetKey = $context->lookupFunction('__hashtable__unsetStringKey');
        $strInit = $context->lookupFunction('__string__init');
        $targetI64 = $context->builder->pointerCast($target, $i64);

        $loopCond = $fn->appendBasicBlock('wr_clear_maps_cond');
        $loopBody = $fn->appendBasicBlock('wr_clear_maps_body');
        $clearBb = $fn->appendBasicBlock('wr_clear_maps_do');
        $loopInc = $fn->appendBasicBlock('wr_clear_maps_inc');

        $context->builder->positionAtEnd($loopInit);
        $count = $context->builder->call(self::helperFunction($context, self::MAP_COUNT));
        $countI32 = $context->builder->trunc($count, $i32);
        $idx = $context->builder->alloca($i32, 1, 'wr_clear_map_i');
        $context->builder->store($i32->constInt(0, false), $idx);
        $context->builder->branch($loopCond);

        $context->builder->positionAtEnd($loopCond);
        $i = $context->builder->load($idx);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLT, $i, $countI32),
            $loopBody,
            $doneBb
        );

        $context->builder->positionAtEnd($loopBody);
        $i64Idx = $context->builder->sext($i, $i64);
        $storedTarget = $context->builder->call(self::helperFunction($context, self::MAP_TARGET), $i64Idx);
        $storedHt = $context->builder->call(self::helperFunction($context, self::MAP_HT), $i64Idx);
        $keyStr = $context->builder->call(self::helperFunction($context, self::MAP_KEY), $i64Idx);
        $targetMatch = $context->builder->icmp(Builder::INT_EQ, $storedTarget, $targetI64);
        $htNonNull = $context->builder->icmp(Builder::INT_NE, $storedHt, $i64->constInt(0, false));
        $doClear = $context->builder->and($targetMatch, $htNonNull);
        $context->builder->branchIf($doClear, $clearBb, $loopInc);

        $context->builder->positionAtEnd($clearBb);
        $context->builder->call(
            $unsetKey,
            $context->builder->pointerCast(
                $context->builder->intToPtr($storedHt, $i8p),
                $htPtr
            ),
            $keyStr
        );
        $context->builder->call(self::helperFunction($context, self::CLEAR_MAP), $i64Idx);
        $context->builder->branch($loopInc);

        $context->builder->positionAtEnd($loopInc);
        $context->builder->store(
            $context->builder->add($context->builder->load($idx), $i32->constInt(1, false)),
            $idx
        );
        $context->builder->branch($loopCond);
        $context->builder->clearInsertionPosition();
    }

    private static function cstrToString(Context $context, Value $keyCstr): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $keyLen = $context->builder->call($context->lookupFunction('strlen'), $keyCstr);

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->sext($keyLen, $i64),
            $keyCstr
        );
    }

    private static function literalCstr(Context $context, string $text): Value
    {
        $i8p = $context->getTypeFromString('int8*');

        return $context->builder->pointerCast($context->constantFromString($text), $i8p);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after WeakRefRegistryJitHelper compile (#9191)');
        }

        return $fn;
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        $missing = false;
        foreach (self::COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                $missing = true;
                break;
            }
        }
        if (!$missing) {
            return;
        }

        $runtime = $context->runtime;
        $path = \dirname(__DIR__, 3).self::HELPER_PATH;
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $path): void {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'WeakRefRegistryJitHelper.php');
            if (null === $block) {
                throw new \LogicException('WeakRefRegistryJitHelper.php parseAndCompile failed (#9191)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                throw new \LogicException($logical.' was not compiled for JIT (#9191)');
            }
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
            $context->context->functionType($i32, true, $i8p, $sizeT, $i8p, $i8p)
        );
        self::ensureExternal(
            $context,
            'strlen',
            $context->context->functionType($sizeT, false, $i8p)
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
            if (null === $fn) {
                throw new \LogicException($name.' missing after WeakRefRegistryRuntime bridge (#9191)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
