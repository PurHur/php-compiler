<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\WeakRefRegistryJitHelper;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringCompare;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for phpc_weakref_* registry (#9191, #26028, #26795, #27621).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer InetRuntime #26010).
 * Replaces lib/AOT/runtime/phpc_weakref.c. Ref + map slot tables are LLVM globals for AOT
 * standalone (JitHelper statics are unreliable under NestedJIT); format/resolve still use
 * WeakRefRegistryJitHelper PHP (#9191).
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

    private const CLEAR_OBJECT = 'PHPCompiler\\ext\\standard\\WeakRefRegistryJitHelper::clearObject';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::RESET,
        self::REGISTER_REF,
        self::REGISTER_MAP,
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
        self::CLEAR_OBJECT,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        // Preserve caller insert block — clearInsertionPosition alone orphans mid-emit
        // (Refcount delref → ensureLinked) (#27550 / #27621).
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);

        $probe = $context->module->getNamedFunction('phpc_weakref_register_ref');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::ensureGcNotifyObjectFreed($context);
            self::registerLinkedRuntime($context);
            if (null !== $savedInsert) {
                BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
            } else {
                $context->builder->clearInsertionPosition();
            }

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
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
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
        self::ensureRefGlobals($context);
        self::ensureMapGlobals($context);
        $zero32 = $context->getTypeFromString('int32')->constInt(0, false);
        $context->builder->store($zero32, $context->module->getNamedGlobal(self::G_REF_COUNT));
        $context->builder->store($zero32, $context->module->getNamedGlobal(self::G_MAP_COUNT));
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private const MAX_REFS_LLVM = 4096;

    private const MAX_MAPS_LLVM = 4096;

    private const G_REF_COUNT = 'phpc_wr_ref_count';

    private const G_REF_TARGETS = 'phpc_wr_ref_targets';

    private const G_REF_SLOTS = 'phpc_wr_ref_slots';

    private const G_LAST_SLOT = 'phpc_wr_last_slot';

    private const G_MAP_COUNT = 'phpc_wr_map_count';

    private const G_MAP_TARGETS = 'phpc_wr_map_targets';

    private const G_MAP_HTS = 'phpc_wr_map_hts';

    private const G_MAP_KEYS = 'phpc_wr_map_keys';

    private static function ensureRefGlobals(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        if (null === $context->module->getNamedGlobal(self::G_REF_COUNT)) {
            $g = $context->module->addGlobal($i32, self::G_REF_COUNT);
            $g->setInitializer($i32->constInt(0, false));
        }
        if (null === $context->module->getNamedGlobal(self::G_LAST_SLOT)) {
            $g = $context->module->addGlobal($i8p, self::G_LAST_SLOT);
            $g->setInitializer($i8p->constNull());
        }
        if (null === $context->module->getNamedGlobal(self::G_REF_TARGETS)) {
            $arrTy = $i8p->arrayType(self::MAX_REFS_LLVM);
            $g = $context->module->addGlobal($arrTy, self::G_REF_TARGETS);
            $g->setInitializer($arrTy->constNull());
        }
        if (null === $context->module->getNamedGlobal(self::G_REF_SLOTS)) {
            $arrTy = $i8p->arrayType(self::MAX_REFS_LLVM);
            $g = $context->module->addGlobal($arrTy, self::G_REF_SLOTS);
            $g->setInitializer($arrTy->constNull());
        }
    }

    private static function ensureMapGlobals(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');
        if (null === $context->module->getNamedGlobal(self::G_MAP_COUNT)) {
            $g = $context->module->addGlobal($i32, self::G_MAP_COUNT);
            $g->setInitializer($i32->constInt(0, false));
        }
        if (null === $context->module->getNamedGlobal(self::G_MAP_TARGETS)) {
            $arrTy = $i8p->arrayType(self::MAX_MAPS_LLVM);
            $g = $context->module->addGlobal($arrTy, self::G_MAP_TARGETS);
            $g->setInitializer($arrTy->constNull());
        }
        if (null === $context->module->getNamedGlobal(self::G_MAP_HTS)) {
            $arrTy = $i8p->arrayType(self::MAX_MAPS_LLVM);
            $g = $context->module->addGlobal($arrTy, self::G_MAP_HTS);
            $g->setInitializer($arrTy->constNull());
        }
        if (null === $context->module->getNamedGlobal(self::G_MAP_KEYS)) {
            $arrTy = $strPtr->arrayType(self::MAX_MAPS_LLVM);
            $g = $context->module->addGlobal($arrTy, self::G_MAP_KEYS);
            $g->setInitializer($arrTy->constNull());
        }
    }

    private static function implementRegisterRefBridge(Context $context): void
    {
        $abiName = 'phpc_weakref_register_ref';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        self::ensureRefGlobals($context);
        $voidTy = $context->getTypeFromString('void');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($voidTy, false, $i8p, $i8p);
        $fn = null !== $probe ? $probe : $context->module->addFunction($abiName, $ft);
        $entry = $fn->appendBasicBlock('wr_reg_ref_entry');
        $done = $fn->appendBasicBlock('wr_reg_ref_done');
        $checkSlot = $fn->appendBasicBlock('wr_reg_ref_check_slot');
        $checkCap = $fn->appendBasicBlock('wr_reg_ref_check_cap');
        $store = $fn->appendBasicBlock('wr_reg_ref_store');
        $context->builder->positionAtEnd($entry);

        $target = $fn->getParam(0);
        $slot = $fn->getParam(1);
        $null = $i8p->constNull();
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $target, $null),
            $done,
            $checkSlot
        );
        $context->builder->positionAtEnd($checkSlot);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $slot, $null),
            $done,
            $checkCap
        );
        $context->builder->positionAtEnd($checkCap);
        $countPtr = $context->module->getNamedGlobal(self::G_REF_COUNT);
        $count = $context->builder->load($countPtr);
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_SGE,
                $count,
                $i32->constInt(self::MAX_REFS_LLVM, false)
            ),
            $done,
            $store
        );
        $context->builder->positionAtEnd($store);
        $targets = $context->module->getNamedGlobal(self::G_REF_TARGETS);
        $slots = $context->module->getNamedGlobal(self::G_REF_SLOTS);
        $zero = $context->context->int32Type()->constInt(0, false);
        $context->builder->store(
            $target,
            $context->builder->gep($targets, $zero, $count)
        );
        $context->builder->store(
            $slot,
            $context->builder->gep($slots, $zero, $count)
        );
        $context->builder->store($slot, $context->module->getNamedGlobal(self::G_LAST_SLOT));
        $context->builder->store(
            $context->builder->add($count, $i32->constInt(1, false)),
            $countPtr
        );
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function implementRegisterMapBridge(Context $context): void
    {
        $abiName = 'phpc_weakref_register_map';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        self::ensureMapGlobals($context);
        self::ensureExternals($context);
        $voidTy = $context->getTypeFromString('void');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $ft = $context->context->functionType($voidTy, false, $i8p, $i8p, $i8p);
        $fn = null !== $probe ? $probe : $context->module->addFunction($abiName, $ft);
        $entry = $fn->appendBasicBlock('wr_reg_map_entry');
        $done = $fn->appendBasicBlock('wr_reg_map_done');
        $checkHt = $fn->appendBasicBlock('wr_reg_map_check_ht');
        $checkKey = $fn->appendBasicBlock('wr_reg_map_check_key');
        $checkCap = $fn->appendBasicBlock('wr_reg_map_check_cap');
        $store = $fn->appendBasicBlock('wr_reg_map_store');
        $context->builder->positionAtEnd($entry);

        $target = $fn->getParam(0);
        $ht = $fn->getParam(1);
        $keyCstr = $fn->getParam(2);
        $null = $i8p->constNull();
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $target, $null),
            $done,
            $checkHt
        );
        $context->builder->positionAtEnd($checkHt);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $ht, $null),
            $done,
            $checkKey
        );
        $context->builder->positionAtEnd($checkKey);
        // strlen(3) via LibcExtern::ensureStrlenDecl after always-on drop (#32068).
        LibcExtern::ensureStrlenDecl($context);
        $keyLen = $context->builder->call($context->lookupFunction('strlen'), $keyCstr);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $keyLen, $sizeT->constInt(0, false)),
            $done,
            $checkCap
        );
        $context->builder->positionAtEnd($checkCap);
        $countPtr = $context->module->getNamedGlobal(self::G_MAP_COUNT);
        $count = $context->builder->load($countPtr);
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_SGE,
                $count,
                $i32->constInt(self::MAX_MAPS_LLVM, false)
            ),
            $done,
            $store
        );
        $context->builder->positionAtEnd($store);
        $keyStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zExt($keyLen, $i64),
            $keyCstr
        );
        $targets = $context->module->getNamedGlobal(self::G_MAP_TARGETS);
        $hts = $context->module->getNamedGlobal(self::G_MAP_HTS);
        $keys = $context->module->getNamedGlobal(self::G_MAP_KEYS);
        $zero = $context->context->int32Type()->constInt(0, false);
        $context->builder->store($target, $context->builder->gep($targets, $zero, $count));
        $context->builder->store($ht, $context->builder->gep($hts, $zero, $count));
        $context->builder->store($keyStr, $context->builder->gep($keys, $zero, $count));
        $context->builder->store(
            $context->builder->add($count, $i32->constInt(1, false)),
            $countPtr
        );
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function implementUnregisterMapBridge(Context $context): void
    {
        $abiName = 'phpc_weakref_unregister_map';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        self::ensureMapGlobals($context);
        self::ensureExternals($context);
        $voidTy = $context->getTypeFromString('void');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $ft = $context->context->functionType($voidTy, false, $i8p, $i8p, $i8p);
        $fn = null !== $probe ? $probe : $context->module->addFunction($abiName, $ft);
        $entry = $fn->appendBasicBlock('wr_unmap_entry');
        $doneBb = $fn->appendBasicBlock('wr_unmap_done');
        $checkHtBb = $fn->appendBasicBlock('wr_unmap_check_ht');
        $checkKeyBb = $fn->appendBasicBlock('wr_unmap_check_keylen');
        $loopPrep = $fn->appendBasicBlock('wr_unmap_prep');
        $context->builder->positionAtEnd($entry);

        $target = $fn->getParam(0);
        $ht = $fn->getParam(1);
        $keyCstr = $fn->getParam(2);
        $null = $i8p->constNull();
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $target, $null),
            $doneBb,
            $checkHtBb
        );

        $context->builder->positionAtEnd($checkHtBb);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $ht, $null),
            $doneBb,
            $checkKeyBb
        );

        $context->builder->positionAtEnd($checkKeyBb);
        // strlen(3) via LibcExtern::ensureStrlenDecl after always-on drop (#32068).
        LibcExtern::ensureStrlenDecl($context);
        $keyLen = $context->builder->call($context->lookupFunction('strlen'), $keyCstr);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $keyLen, $sizeT->constInt(0, false)),
            $doneBb,
            $loopPrep
        );

        self::emitUnregisterMapLoop($context, $fn, $target, $ht, $keyCstr, $loopPrep, $doneBb);

        $context->builder->positionAtEnd($doneBb);
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
        $zero = $i64->constInt(0, false);
        $objPtr = $context->builder->pointerCast($fn->getParam(0), $i64);
        $badBuf = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $buf, $null),
            $context->builder->icmp(Builder::INT_EQ, $bufLen, $sizeT->constInt(0, false))
        );
        $badObj = $context->builder->icmp(Builder::INT_EQ, $objPtr, $zero);
        $bad = $context->builder->or($badBuf, $badObj);
        $context->builder->branchIf($bad, $doneBb, $workBb);

        $context->builder->positionAtEnd($workBb);
        $formatted = $context->builder->call(self::helperFunction($context, self::FORMAT_KEY), $objPtr);
        // snprintf(3) via LibcExtern::ensureSnprintf after always-on drop (#32092).
        LibcExtern::ensureSnprintf($context);
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
        // Match Refcount delref: object bit set (not equality-to-8 after mask) (#27621).
        $isObject = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->and(
                $typeinfo,
                $i32->constInt(self::TYPEINFO_TYPE_OBJECT, false)
            ),
            $i32->constInt(0, false)
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

        self::ensureRefGlobals($context);
        self::ensureMapGlobals($context);
        self::ensureExternals($context);
        $voidTy = $context->getTypeFromString('void');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($voidTy, false, $i8p);
        $fn = null !== $probe ? $probe : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('wr_clear_entry');
        $nullLast = $fn->appendBasicBlock('wr_clear_null_last');
        $loopHead = $fn->appendBasicBlock('wr_clear_loop');
        $body = $fn->appendBasicBlock('wr_clear_body');
        $match = $fn->appendBasicBlock('wr_clear_match');
        $next = $fn->appendBasicBlock('wr_clear_next');
        $mapsInit = $fn->appendBasicBlock('wr_clear_maps_init');
        $done = $fn->appendBasicBlock('wr_clear_done');

        $null = $i8p->constNull();
        $zero32 = $i32->constInt(0, false);
        $one32 = $i32->constInt(1, false);

        $nullSlot = static function (Value $slot) use ($context, $null): void {
            // Slot arg is the object's void** property pointer — null the indirection
            // so __object__load_value_slot takes the null path (#26795).
            $voidpp = $context->getTypeFromString('void**');
            $voidp = $context->getTypeFromString('void*');
            $context->builder->store(
                $voidp->constNull(),
                $context->builder->pointerCast($slot, $voidpp)
            );
        };

        $context->builder->positionAtEnd($entry);
        $target = $fn->getParam(0);
        $lastSlot = $context->builder->load($context->module->getNamedGlobal(self::G_LAST_SLOT));
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $lastSlot, $null),
            $loopHead,
            $nullLast
        );

        $context->builder->positionAtEnd($nullLast);
        $nullSlot($lastSlot);
        $context->builder->store($null, $context->module->getNamedGlobal(self::G_LAST_SLOT));
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $idx = $context->builder->phi($i32);
        $idx->addIncoming($zero32, $entry);
        $idx->addIncoming($zero32, $nullLast);
        $count = $context->builder->load($context->module->getNamedGlobal(self::G_REF_COUNT));
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLT, $idx, $count),
            $body,
            $mapsInit
        );

        $context->builder->positionAtEnd($body);
        $zero = $context->context->int32Type()->constInt(0, false);
        $targets = $context->module->getNamedGlobal(self::G_REF_TARGETS);
        $slots = $context->module->getNamedGlobal(self::G_REF_SLOTS);
        $t = $context->builder->load($context->builder->gep($targets, $zero, $idx));
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $t, $target),
            $match,
            $next
        );

        $context->builder->positionAtEnd($match);
        $slot = $context->builder->load($context->builder->gep($slots, $zero, $idx));
        $doNull = $fn->appendBasicBlock('wr_clear_do_null');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_NE, $slot, $null),
            $doNull,
            $next
        );
        $context->builder->positionAtEnd($doNull);
        $nullSlot($slot);
        $context->builder->store($null, $context->builder->gep($targets, $zero, $idx));
        $context->builder->store($null, $context->builder->gep($slots, $zero, $idx));
        $context->builder->branch($next);

        $context->builder->positionAtEnd($next);
        $nextIdx = $context->builder->add($idx, $one32);
        $idx->addIncoming($nextIdx, $next);
        $context->builder->branch($loopHead);

        // WeakMap HT keys: NestedJIT stubs phpc_weakref_unset_map_key and JitHelper map
        // statics are unreliable under AOT — purge via LLVM globals (#27621 / zend_weakrefs.c).
        self::emitClearMapLoop($context, $fn, $target, $mapsInit, $done);

        $context->builder->positionAtEnd($done);
        $context->builder->returnVoid();
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

    private static function emitClearMapLoop(
        Context $context,
        Value $fn,
        Value $target,
        $loopInit,
        $doneBb,
    ): void {
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $null = $i8p->constNull();
        $strNull = $strPtr->constNull();
        $unsetKey = $context->lookupFunction('__hashtable__unsetStringKey');

        $loopCond = $fn->appendBasicBlock('wr_clear_maps_cond');
        $loopBody = $fn->appendBasicBlock('wr_clear_maps_body');
        $clearBb = $fn->appendBasicBlock('wr_clear_maps_do');
        $loopInc = $fn->appendBasicBlock('wr_clear_maps_inc');

        $context->builder->positionAtEnd($loopInit);
        $count = $context->builder->load($context->module->getNamedGlobal(self::G_MAP_COUNT));
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
        $zero = $context->context->int32Type()->constInt(0, false);
        $targets = $context->module->getNamedGlobal(self::G_MAP_TARGETS);
        $hts = $context->module->getNamedGlobal(self::G_MAP_HTS);
        $keys = $context->module->getNamedGlobal(self::G_MAP_KEYS);
        $storedTarget = $context->builder->load($context->builder->gep($targets, $zero, $i));
        $storedHt = $context->builder->load($context->builder->gep($hts, $zero, $i));
        $keyStr = $context->builder->load($context->builder->gep($keys, $zero, $i));
        $targetMatch = $context->builder->icmp(Builder::INT_EQ, $storedTarget, $target);
        $htNonNull = $context->builder->icmp(Builder::INT_NE, $storedHt, $null);
        $keyNonNull = $context->builder->icmp(Builder::INT_NE, $keyStr, $strNull);
        $doClear = $context->builder->and(
            $targetMatch,
            $context->builder->and($htNonNull, $keyNonNull)
        );
        $context->builder->branchIf($doClear, $clearBb, $loopInc);

        $context->builder->positionAtEnd($clearBb);
        $context->builder->call(
            $unsetKey,
            $context->builder->pointerCast($storedHt, $htPtr),
            $keyStr
        );
        $context->builder->store($null, $context->builder->gep($targets, $zero, $i));
        $context->builder->store($null, $context->builder->gep($hts, $zero, $i));
        $context->builder->store($strNull, $context->builder->gep($keys, $zero, $i));
        $context->builder->branch($loopInc);

        $context->builder->positionAtEnd($loopInc);
        $context->builder->store(
            $context->builder->add($context->builder->load($idx), $i32->constInt(1, false)),
            $idx
        );
        $context->builder->branch($loopCond);
        $context->builder->clearInsertionPosition();
    }

    private static function emitUnregisterMapLoop(
        Context $context,
        Value $fn,
        Value $target,
        Value $ht,
        Value $keyCstr,
        $loopPrep,
        $doneBb,
    ): void {
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');
        $null = $i8p->constNull();
        $strNull = $strPtr->constNull();

        $loopCond = $fn->appendBasicBlock('wr_unmap_cond');
        $loopBody = $fn->appendBasicBlock('wr_unmap_body');
        $checkHtBb = $fn->appendBasicBlock('wr_unmap_check_ht');
        $checkKeyBb = $fn->appendBasicBlock('wr_unmap_check_key');
        $clearBb = $fn->appendBasicBlock('wr_unmap_do');
        $loopInc = $fn->appendBasicBlock('wr_unmap_inc');

        $context->builder->positionAtEnd($loopPrep);
        $keyStr = self::cstrToString($context, $keyCstr);
        $count = $context->builder->load($context->module->getNamedGlobal(self::G_MAP_COUNT));
        $idx = $context->builder->alloca($i32, 1, 'wr_unmap_i');
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
        $zero = $context->context->int32Type()->constInt(0, false);
        $targets = $context->module->getNamedGlobal(self::G_MAP_TARGETS);
        $hts = $context->module->getNamedGlobal(self::G_MAP_HTS);
        $keys = $context->module->getNamedGlobal(self::G_MAP_KEYS);
        $storedTarget = $context->builder->load($context->builder->gep($targets, $zero, $i));
        $targetMatch = $context->builder->icmp(Builder::INT_EQ, $storedTarget, $target);
        $context->builder->branchIf($targetMatch, $checkHtBb, $loopInc);

        $context->builder->positionAtEnd($checkHtBb);
        $storedHt = $context->builder->load($context->builder->gep($hts, $zero, $i));
        $htMatch = $context->builder->icmp(Builder::INT_EQ, $storedHt, $ht);
        $context->builder->branchIf($htMatch, $checkKeyBb, $loopInc);

        $context->builder->positionAtEnd($checkKeyBb);
        $storedKey = $context->builder->load($context->builder->gep($keys, $zero, $i));
        $keyNonNull = $context->builder->icmp(Builder::INT_NE, $storedKey, $strNull);
        $keyMatchBb = $fn->appendBasicBlock('wr_unmap_key_cmp');
        $context->builder->branchIf($keyNonNull, $keyMatchBb, $loopInc);

        $context->builder->positionAtEnd($keyMatchBb);
        $keyMatch = JitStringCompare::identical($context, $storedKey, $keyStr);
        $context->builder->branchIf($keyMatch, $clearBb, $loopInc);

        $context->builder->positionAtEnd($clearBb);
        $context->builder->store($null, $context->builder->gep($targets, $zero, $i));
        $context->builder->store($null, $context->builder->gep($hts, $zero, $i));
        $context->builder->store($strNull, $context->builder->gep($keys, $zero, $i));
        $context->builder->branch($doneBb);

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
        // strlen(3) via LibcExtern::ensureStrlenDecl after always-on drop (#32068).
        LibcExtern::ensureStrlenDecl($context);
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

        return JitVmHelperLink::lookupCompiled($context, $logical, '#26028');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#26028'
        );
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
        self::ensureExternal(
            $context,
            'memcmp',
            $context->context->functionType($i32, false, $i8p, $i8p, $sizeT)
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
