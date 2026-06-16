<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\LLVMAbstract\Builder as LLVMBuilderImpl;
use PHPLLVM\Value;
use llvm\LLVMValueRef_ptr;

/**
 * JIT/AOT spl_autoload_register() callback stack — PHP LLVM lowering (#1776, #2441, #5300).
 *
 * Replaces {@see lib/AOT/runtime/phpc_spl_autoload.c}; stack semantics match
 * {@see \PHPCompiler\ext\standard\VmSplAutoload} prepend/append order.
 */
final class SplAutoloadOutput
{
    public const MAX = 32;

    public const GLOBAL_STACK = '__phpc_spl_autoload_stack';

    public const GLOBAL_DEPTH = '__phpc_spl_autoload_depth';

    public const GLOBAL_META_STACK = '__phpc_spl_autoload_meta_stack';

  /** @var array<string, Value> */
    private static array $stackGlobalsByModule = [];

    /** @var array<string, Value> */
    private static array $depthGlobalsByModule = [];

    /** @var array<string, Value> */
    private static array $metaGlobalsByModule = [];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $moduleKey = spl_object_hash($context->module);
        $resumeBlock = $context->builder->getInsertBlock();

        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $void = $context->context->voidType();
        $cbFnTy = $context->context->functionType($i32, false, $i8p, $sizeT);
        $cbPtrTy = $cbFnTy->pointerType(0);

        $stackGlobal = $context->module->getNamedGlobal(self::GLOBAL_STACK);
        if (null === $stackGlobal) {
            $stackTy = $i8p->arrayType(self::MAX);
            $stackGlobal = $context->module->addGlobal($stackTy, self::GLOBAL_STACK);
            $stackGlobal->setInitializer($stackTy->constNull());
            $depthGlobal = $context->module->addGlobal($i32, self::GLOBAL_DEPTH);
            $depthGlobal->setInitializer($i32->constInt(0, false));
            self::$stackGlobalsByModule[$moduleKey] = $stackGlobal;
            self::$depthGlobalsByModule[$moduleKey] = $depthGlobal;
        } else {
            self::$stackGlobalsByModule[$moduleKey] = $stackGlobal;
            $depthGlobal = $context->module->getNamedGlobal(self::GLOBAL_DEPTH);
            if (null !== $depthGlobal) {
                self::$depthGlobalsByModule[$moduleKey] = $depthGlobal;
            }
        }

        $metaGlobal = $context->module->getNamedGlobal(self::GLOBAL_META_STACK);
        if (null === $metaGlobal) {
            $metaTy = $i8p->arrayType(self::MAX);
            $metaGlobal = $context->module->addGlobal($metaTy, self::GLOBAL_META_STACK);
            $metaGlobal->setInitializer($metaTy->constNull());
        }
        self::$metaGlobalsByModule[$moduleKey] = $metaGlobal;

        $registerProbe = $context->module->getNamedFunction('__phpc_spl_autoload_register_apply');
        $unregisterProbe = $context->module->getNamedFunction('__phpc_spl_autoload_unregister_apply');
        $dispatchProbe = $context->module->getNamedFunction('__phpc_spl_autoload_dispatch');
        $registerReady = null !== $registerProbe
            && $registerProbe->countBasicBlocks() > 0
            && 3 === $registerProbe->countParams();
        $unregisterReady = null !== $unregisterProbe && $unregisterProbe->countBasicBlocks() > 0;
        $dispatchReady = null !== $dispatchProbe && $dispatchProbe->countBasicBlocks() > 0;
        if ($registerReady && $unregisterReady && $dispatchReady) {
            if (null !== $resumeBlock) {
                $context->builder->positionAtEnd($resumeBlock);
            } else {
                $context->builder->clearInsertionPosition();
            }

            return;
        }

        if (!$registerReady) {
            self::emitRegisterApply($context, $moduleKey, $i32, $i8p, $cbPtrTy, $void);
        }
        if (!$unregisterReady) {
            self::emitUnregisterApply($context, $moduleKey, $i32, $i8p);
        }
        if (!$dispatchReady) {
            self::emitDispatch($context, $moduleKey, $i32, $i8p, $sizeT, $cbFnTy, $cbPtrTy);
        }

        if (null !== $resumeBlock) {
            $context->builder->positionAtEnd($resumeBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function stackGlobal(Context $context, string $moduleKey): Value
    {
        return self::$stackGlobalsByModule[$moduleKey]
            ?? $context->module->getNamedGlobal(self::GLOBAL_STACK)
            ?? throw new \LogicException('spl_autoload stack global is not linked');
    }

    private static function depthGlobal(Context $context, string $moduleKey): Value
    {
        return self::$depthGlobalsByModule[$moduleKey]
            ?? $context->module->getNamedGlobal(self::GLOBAL_DEPTH)
            ?? throw new \LogicException('spl_autoload depth global is not linked');
    }

    public static function loadDepth(Context $context): Value
    {
        $moduleKey = spl_object_hash($context->module);

        return $context->builder->load(self::depthGlobal($context, $moduleKey));
    }

    public static function loadMetaAt(Context $context, $i32, Value $index): Value
    {
        $moduleKey = spl_object_hash($context->module);

        return $context->builder->load(self::metaStackEntryPtr($context, $moduleKey, $i32, $index));
    }

    private static function emitRegisterApply(
        Context $context,
        string $moduleKey,
        $i32,
        $i8p,
        $cbPtrTy,
        $void
    ): void {
        $fn = $context->module->addFunction(
            '__phpc_spl_autoload_register_apply',
            $context->context->functionType($void, false, $i8p, $i8p, $i32)
        );
        $context->registerFunction('__phpc_spl_autoload_register_apply', $fn);

        $entry = $fn->appendBasicBlock('spl_reg_entry');
        $bbDone = $fn->appendBasicBlock('spl_reg_done');
        $bbAppend = $fn->appendBasicBlock('spl_reg_append');
        $bbPrependCheck = $fn->appendBasicBlock('spl_reg_prepend_check');
        $bbPrependShiftInit = $fn->appendBasicBlock('spl_reg_prepend_shift_init');
        $bbPrependShiftHead = $fn->appendBasicBlock('spl_reg_prepend_shift_head');
        $bbPrependShiftBody = $fn->appendBasicBlock('spl_reg_prepend_shift_body');
        $bbPrependStore = $fn->appendBasicBlock('spl_reg_prepend_store');

        $context->builder->positionAtEnd($entry);
        $fnOpaque = $fn->getParam(0);
        $metaOpaque = $fn->getParam(1);
        $prepend = $fn->getParam(2);

        $depth = $context->builder->load(self::depthGlobal($context, $moduleKey));
        $maxDepth = $context->builder->icmp(
            Builder::INT_SGE,
            $depth,
            $i32->constInt(self::MAX, false)
        );
        $fnNull = $context->builder->icmp(
            Builder::INT_EQ,
            $fnOpaque,
            $i8p->constNull()
        );
        $skip = $context->builder->or($maxDepth, $fnNull);
        $context->builder->branchIf($skip, $bbDone, $bbPrependCheck);

        $context->builder->positionAtEnd($bbPrependCheck);
        $wantPrepend = $context->builder->icmp(Builder::INT_NE, $prepend, $i32->constInt(0, false));
        $depthPos = $context->builder->icmp(Builder::INT_SGT, $depth, $i32->constInt(0, false));
        $doPrepend = $context->builder->and($wantPrepend, $depthPos);
        $context->builder->branchIf($doPrepend, $bbPrependShiftInit, $bbAppend);

        $zeroI32 = $i32->constInt(0, false);
        $oneI32 = $i32->constInt(1, false);

        $context->builder->positionAtEnd($bbAppend);
        self::storeStackEntry($context, $moduleKey, $i32, $depth, $fnOpaque);
        self::storeMetaStackEntry($context, $moduleKey, $i32, $depth, $metaOpaque);
        $context->builder->store($context->builder->add($depth, $oneI32), self::depthGlobal($context, $moduleKey));
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbPrependShiftInit);
        $iSlot = $context->builder->alloca($i32, 1, 'spl_i');
        $context->builder->store($depth, $iSlot);
        $context->builder->branch($bbPrependShiftHead);

        $context->builder->positionAtEnd($bbPrependShiftHead);
        $iVal = $context->builder->load($iSlot);
        $iGtZero = $context->builder->icmp(Builder::INT_SGT, $iVal, $zeroI32);
        $context->builder->branchIf($iGtZero, $bbPrependShiftBody, $bbPrependStore);

        $context->builder->positionAtEnd($bbPrependShiftBody);
        $prevIdx = $context->builder->sub($iVal, $oneI32);
        $curEntry = self::stackEntryPtr($context, $moduleKey, $i32, $iVal);
        $prevEntry = self::stackEntryPtr($context, $moduleKey, $i32, $prevIdx);
        $context->builder->store($context->builder->load($prevEntry), $curEntry);
        $curMeta = self::metaStackEntryPtr($context, $moduleKey, $i32, $iVal);
        $prevMeta = self::metaStackEntryPtr($context, $moduleKey, $i32, $prevIdx);
        $context->builder->store($context->builder->load($prevMeta), $curMeta);
        $context->builder->store($prevIdx, $iSlot);
        $context->builder->branch($bbPrependShiftHead);

        $context->builder->positionAtEnd($bbPrependStore);
        self::storeStackEntry($context, $moduleKey, $i32, $zeroI32, $fnOpaque);
        self::storeMetaStackEntry($context, $moduleKey, $i32, $zeroI32, $metaOpaque);
        $context->builder->store($context->builder->add($depth, $oneI32), self::depthGlobal($context, $moduleKey));
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);
        $context->builder->returnVoid();
    }

    private static function emitUnregisterApply(
        Context $context,
        string $moduleKey,
        $i32,
        $i8p
    ): void {
        $fn = $context->module->addFunction(
            '__phpc_spl_autoload_unregister_apply',
            $context->context->functionType($i32, false, $i8p)
        );
        $context->registerFunction('__phpc_spl_autoload_unregister_apply', $fn);

        $entry = $fn->appendBasicBlock('spl_unreg_entry');
        $bbLoopHead = $fn->appendBasicBlock('spl_unreg_loop_head');
        $bbLoopBody = $fn->appendBasicBlock('spl_unreg_loop_body');
        $bbFound = $fn->appendBasicBlock('spl_unreg_found');
        $bbNext = $fn->appendBasicBlock('spl_unreg_next');
        $bbShiftHead = $fn->appendBasicBlock('spl_unreg_shift_head');
        $bbShiftBody = $fn->appendBasicBlock('spl_unreg_shift_body');
        $bbDecDepth = $fn->appendBasicBlock('spl_unreg_dec_depth');
        $bbRetZero = $fn->appendBasicBlock('spl_unreg_ret_zero');
        $bbRetOne = $fn->appendBasicBlock('spl_unreg_ret_one');

        $zeroI32 = $i32->constInt(0, false);
        $oneI32 = $i32->constInt(1, false);

        $context->builder->positionAtEnd($entry);
        $fnOpaque = $fn->getParam(0);
        $iSlot = $context->builder->alloca($i32, 1, 'spl_unreg_i');
        $jSlot = $context->builder->alloca($i32, 1, 'spl_unreg_j');
        $context->builder->store($zeroI32, $iSlot);
        $fnNull = $context->builder->icmp(
            \PHPLLVM\Builder::INT_EQ,
            $fnOpaque,
            $i8p->constNull()
        );
        $context->builder->branchIf($fnNull, $bbRetZero, $bbLoopHead);

        $context->builder->positionAtEnd($bbLoopHead);
        $iVal = $context->builder->load($iSlot);
        $depth = $context->builder->load(self::depthGlobal($context, $moduleKey));
        $inRange = $context->builder->icmp(\PHPLLVM\Builder::INT_SLT, $iVal, $depth);
        $context->builder->branchIf($inRange, $bbLoopBody, $bbRetZero);

        $context->builder->positionAtEnd($bbLoopBody);
        $entryPtr = self::stackEntryPtr($context, $moduleKey, $i32, $iVal);
        $stackFn = $context->builder->load($entryPtr);
        $match = $context->builder->icmp(\PHPLLVM\Builder::INT_EQ, $stackFn, $fnOpaque);
        $context->builder->branchIf($match, $bbFound, $bbNext);

        $context->builder->positionAtEnd($bbNext);
        $context->builder->store(
            $context->builder->add($context->builder->load($iSlot), $oneI32),
            $iSlot
        );
        $context->builder->branch($bbLoopHead);

        $context->builder->positionAtEnd($bbFound);
        $context->builder->store($context->builder->load($iSlot), $jSlot);
        $context->builder->branch($bbShiftHead);

        $context->builder->positionAtEnd($bbShiftHead);
        $jVal = $context->builder->load($jSlot);
        $depthShift = $context->builder->load(self::depthGlobal($context, $moduleKey));
        $lastIdx = $context->builder->sub($depthShift, $oneI32);
        $shiftDone = $context->builder->icmp(\PHPLLVM\Builder::INT_SGE, $jVal, $lastIdx);
        $context->builder->branchIf($shiftDone, $bbDecDepth, $bbShiftBody);

        $context->builder->positionAtEnd($bbShiftBody);
        $jBody = $context->builder->load($jSlot);
        $nextIdx = $context->builder->add($jBody, $oneI32);
        $curEntry = self::stackEntryPtr($context, $moduleKey, $i32, $jBody);
        $nextEntry = self::stackEntryPtr($context, $moduleKey, $i32, $nextIdx);
        $context->builder->store($context->builder->load($nextEntry), $curEntry);
        $curMeta = self::metaStackEntryPtr($context, $moduleKey, $i32, $jBody);
        $nextMeta = self::metaStackEntryPtr($context, $moduleKey, $i32, $nextIdx);
        $context->builder->store($context->builder->load($nextMeta), $curMeta);
        $context->builder->store($nextIdx, $jSlot);
        $context->builder->branch($bbShiftHead);

        $context->builder->positionAtEnd($bbDecDepth);
        $newDepth = $context->builder->sub(
            $context->builder->load(self::depthGlobal($context, $moduleKey)),
            $oneI32
        );
        $context->builder->store($newDepth, self::depthGlobal($context, $moduleKey));
        $context->builder->branch($bbRetOne);

        $context->builder->positionAtEnd($bbRetZero);
        $context->builder->returnValue($zeroI32);

        $context->builder->positionAtEnd($bbRetOne);
        $context->builder->returnValue($oneI32);
    }

    private static function emitDispatch(
        Context $context,
        string $moduleKey,
        $i32,
        $i8p,
        $sizeT,
        $cbFnTy,
        $cbPtrTy
    ): void {
        $fn = $context->module->addFunction(
            '__phpc_spl_autoload_dispatch',
            $context->context->functionType($i32, false, $i8p, $sizeT)
        );
        $context->registerFunction('__phpc_spl_autoload_dispatch', $fn);

        $entry = $fn->appendBasicBlock('spl_disp_entry');
        $bbLoopHead = $fn->appendBasicBlock('spl_disp_loop_head');
        $bbLoopBody = $fn->appendBasicBlock('spl_disp_loop_body');
        $bbCall = $fn->appendBasicBlock('spl_disp_call');
        $bbNext = $fn->appendBasicBlock('spl_disp_next');
        $bbRetZero = $fn->appendBasicBlock('spl_disp_ret_zero');
        $bbRetOne = $fn->appendBasicBlock('spl_disp_ret_one');

        $context->builder->positionAtEnd($entry);
        $classPtr = $fn->getParam(0);
        $classLen = $fn->getParam(1);
        $iSlot = $context->builder->alloca($i32, 1, 'spl_disp_i');
        $context->builder->store($i32->constInt(0, false), $iSlot);

        $badName = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $classPtr, $i8p->constNull()),
            $context->builder->icmp(Builder::INT_EQ, $classLen, $sizeT->constInt(0, false))
        );
        $context->builder->branchIf($badName, $bbRetZero, $bbLoopHead);

        $context->builder->positionAtEnd($bbLoopHead);
        $iVal = $context->builder->load($iSlot);
        $depth = $context->builder->load(self::depthGlobal($context, $moduleKey));
        $inRange = $context->builder->icmp(Builder::INT_SLT, $iVal, $depth);
        $context->builder->branchIf($inRange, $bbLoopBody, $bbRetZero);

        $context->builder->positionAtEnd($bbLoopBody);
        $entryPtr = self::stackEntryPtr($context, $moduleKey, $i32, $iVal);
        $fnOpaque = $context->builder->load($entryPtr);
        $fnNull = $context->builder->icmp(Builder::INT_EQ, $fnOpaque, $i8p->constNull());
        $context->builder->branchIf($fnNull, $bbNext, $bbCall);

        $context->builder->positionAtEnd($bbCall);
        $cb = $context->builder->pointerCast($fnOpaque, $cbPtrTy);
        $ret = self::emitIndirectCall($context, $cbFnTy, $cb, $classPtr, $classLen);
        $ok = $context->builder->icmp(Builder::INT_NE, $ret, $i32->constInt(0, false));
        $context->builder->branchIf($ok, $bbRetOne, $bbNext);

        $context->builder->positionAtEnd($bbNext);
        $context->builder->store(
            $context->builder->add($iVal, $i32->constInt(1, false)),
            $iSlot
        );
        $context->builder->branch($bbLoopHead);

        $context->builder->positionAtEnd($bbRetZero);
        $context->builder->returnValue($i32->constInt(0, false));

        $context->builder->positionAtEnd($bbRetOne);
        $context->builder->returnValue($i32->constInt(1, false));
    }

    private static function stackEntryPtr(Context $context, string $moduleKey, $i32, Value $index): Value
    {
        return $context->builder->inBoundsGEP(
            self::stackGlobal($context, $moduleKey),
            $i32->constInt(0, false),
            $index
        );
    }

    private static function metaStackGlobal(Context $context, string $moduleKey): Value
    {
        return self::$metaGlobalsByModule[$moduleKey]
            ?? $context->module->getNamedGlobal(self::GLOBAL_META_STACK)
            ?? throw new \LogicException('spl_autoload meta stack global is not linked');
    }

    private static function metaStackEntryPtr(Context $context, string $moduleKey, $i32, Value $index): Value
    {
        return $context->builder->inBoundsGEP(
            self::metaStackGlobal($context, $moduleKey),
            $i32->constInt(0, false),
            $index
        );
    }

    private static function storeMetaStackEntry(
        Context $context,
        string $moduleKey,
        $i32,
        Value $index,
        Value $metaOpaque
    ): void {
        $context->builder->store($metaOpaque, self::metaStackEntryPtr($context, $moduleKey, $i32, $index));
    }

    private static function storeStackEntry(
        Context $context,
        string $moduleKey,
        $i32,
        Value $index,
        Value $fnOpaque
    ): void {
        $context->builder->store($fnOpaque, self::stackEntryPtr($context, $moduleKey, $i32, $index));
    }

    private static function emitIndirectCall(Context $context, $fnTy, Value $fnPtr, Value ...$args): Value
    {
        $b = $context->builder;
        if (!$b instanceof LLVMBuilderImpl) {
            throw new \LogicException('LLVM builder required for spl_autoload indirect call');
        }
        $valueWrapper = $b->llvm->lib->makeArray(
            LLVMValueRef_ptr::class,
            array_map(static fn (Value $value) => $value->value, $args)
        );

        return $b->llvm->factory->value(
            $context->context,
            $b->llvm->lib->LLVMBuildCall2(
                $b->builder,
                $fnTy->type,
                $fnPtr->value,
                $valueWrapper,
                \count($args),
                ''
            )
        );
    }
}
