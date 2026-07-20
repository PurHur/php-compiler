<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\LLVMAbstract\Builder as LLVMBuilderImpl;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;
use llvm\LLVMValueRef_ptr;

/**
 * JIT/AOT link for __phpc_exception_handler_* (#9473, #21325).
 *
 * Stack state uses LLVM module globals ({@see ErrorHandlerJitRuntime} / #17671) because
 * NestedJIT static string stores in {@see \PHPCompiler\ext\standard\ExceptionHandlerJitHelper}
 * still emit `store %__string__*, i64*` and fail module verify. Thin no-op ABI stubs deleted
 * (#21325). Depth/top/saved mirrors error-handler stack (MAX effective depth 2 saved).
 *
 * VM SSOT / unit semantics remain {@see \PHPCompiler\ext\standard\ExceptionHandlerJitHelper}.
 * php-src: ext/standard/basic_functions.c — set_exception_handler, restore_exception_handler
 */
final class ExceptionHandlerJitRuntime
{
    private const MAX_DEPTH = 8;

    private const GLOBAL_DEPTH = 'phpc_xh_stack_depth';

    private const GLOBAL_TOP_FN = 'phpc_xh_stack_top_fn';

    private const GLOBAL_TOP_NAME = 'phpc_xh_stack_top_name';

    private const GLOBAL_SAVED_FN = 'phpc_xh_stack_saved_fn';

    private const GLOBAL_SAVED_NAME = 'phpc_xh_stack_saved_name';

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__phpc_exception_handler_dispatch',
        '__phpc_exception_handler_set_apply',
        '__phpc_exception_handler_restore_apply',
        '__phpc_exception_handler_get_apply',
    ];

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        if (self::fullStackReady($context)) {
            self::registerLinkedRuntime($context);

            return;
        }

        $restoreBlock = self::captureInsertBlock($context);
        self::ensureValueWriters($context);
        self::ensureStackGlobals($context);
        self::implementDispatchBridge($context);
        self::implementSetApplyBridge($context);
        self::implementRestoreApplyBridge($context);
        self::implementGetApplyBridge($context);
        self::registerLinkedRuntime($context);
        self::restoreInsertBlock($context, $restoreBlock);
    }

    private static function implementDispatchBridge(Context $context): void
    {
        $abiName = '__phpc_exception_handler_dispatch';
        $probe = $context->module->getNamedFunction($abiName);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, 'xh_dispatch_entry')) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $objPtr = $context->getTypeFromString('__object__*');
        $cbFnTy = $context->context->functionType($i32, false, $objPtr);
        $cbPtrTy = $cbFnTy->pointerType(0);
        $ft = $context->context->functionType($i32, false, $objPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = self::bridgeEntryForEmit($fn, 'xh_dispatch_entry');
        $context->builder->positionAtEnd($entry);

        $exception = $fn->getParam(0);
        $zeroI32 = $i32->constInt(0, false);
        $oneI32 = $i32->constInt(1, false);
        $zeroI64 = $i64->constInt(0, false);

        $depth = self::loadStackGlobal($context, self::GLOBAL_DEPTH);
        $emptyBb = $fn->appendBasicBlock('xh_dispatch_empty');
        $resolveBb = $fn->appendBasicBlock('xh_dispatch_resolve');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLE, $depth, $zeroI64),
            $emptyBb,
            $resolveBb
        );

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->returnValue($zeroI32);

        $context->builder->positionAtEnd($resolveBb);
        $fnAddr = self::loadStackGlobal($context, self::GLOBAL_TOP_FN);
        $noFnBb = $fn->appendBasicBlock('xh_dispatch_no_fn');
        $callBb = $fn->appendBasicBlock('xh_dispatch_call');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $fnAddr, $zeroI64),
            $noFnBb,
            $callBb
        );

        $context->builder->positionAtEnd($noFnBb);
        $context->builder->returnValue($zeroI32);

        $context->builder->positionAtEnd($callBb);
        $fnPtr = $context->builder->intToPtr($fnAddr, $i8p);
        $cb = $context->builder->pointerCast($fnPtr, $cbPtrTy);
        // Invoke user shim; Zend ignores the handler's PHP return — exception is consumed (#21325).
        self::emitIndirectCall($context, $cbFnTy, $cb, $exception);
        $context->builder->returnValue($oneI32);
        $context->registerFunction($abiName, $fn);
    }

    private static function implementSetApplyBridge(Context $context): void
    {
        $abiName = '__phpc_exception_handler_set_apply';
        $probe = $context->module->getNamedFunction($abiName);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, 'xh_set_entry')) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $strPtr = $context->getTypeFromString('__string__*');
        $valPtr = $context->getTypeFromString('__value__*');
        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false, $valPtr, $i8p, $sizeT, $i8p);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = self::bridgeEntryForEmit($fn, 'xh_set_entry');
        $context->builder->positionAtEnd($entry);

        $out = $fn->getParam(0);
        $name = $fn->getParam(1);
        $nameLen = $fn->getParam(2);
        $fnOpaque = $fn->getParam(3);

        $fnAddr = $context->builder->ptrToInt($fnOpaque, $i64);
        $handlerName = self::optionalCstrToString($context, $fn, $name, $nameLen);
        $depth = self::loadStackGlobal($context, self::GLOBAL_DEPTH);
        $zeroI64 = $i64->constInt(0, false);
        $oneI64 = $i64->constInt(1, false);
        $maxI64 = $i64->constInt(self::MAX_DEPTH, false);
        $nullStr = $strPtr->constNull();

        $popBb = $fn->appendBasicBlock('xh_set_pop');
        $pushBb = $fn->appendBasicBlock('xh_set_push');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $fnAddr, $zeroI64),
            $popBb,
            $pushBb
        );

        // set(null) pops like ExceptionHandlerJitHelper::setApply(0, '').
        $context->builder->positionAtEnd($popBb);
        $popEmptyBb = $fn->appendBasicBlock('xh_set_pop_empty');
        $popDoBb = $fn->appendBasicBlock('xh_set_pop_do');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLE, $depth, $zeroI64),
            $popEmptyBb,
            $popDoBb
        );

        $context->builder->positionAtEnd($popEmptyBb);
        self::writeNullableStringToValue($context, $fn, $out, $nullStr);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($popDoBb);
        $removed = self::loadStackGlobal($context, self::GLOBAL_TOP_NAME);
        self::emitPopTop($context, $depth, $zeroI64, $oneI64, $nullStr);
        self::writeNullableStringToValue($context, $fn, $out, $removed);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($pushBb);
        $hasPrevBb = $fn->appendBasicBlock('xh_set_has_prev');
        $noPrevBb = $fn->appendBasicBlock('xh_set_no_prev');
        $prevDoneBb = $fn->appendBasicBlock('xh_set_prev_done');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SGT, $depth, $zeroI64),
            $hasPrevBb,
            $noPrevBb
        );

        $context->builder->positionAtEnd($hasPrevBb);
        $prevFromStack = self::loadStackGlobal($context, self::GLOBAL_TOP_NAME);
        $context->builder->branch($prevDoneBb);

        $context->builder->positionAtEnd($noPrevBb);
        $context->builder->branch($prevDoneBb);

        $context->builder->positionAtEnd($prevDoneBb);
        $previous = $context->builder->phi($strPtr);
        $previous->addIncoming($prevFromStack, $hasPrevBb);
        $previous->addIncoming($nullStr, $noPrevBb);

        $atMaxBb = $fn->appendBasicBlock('xh_set_at_max');
        $applyBb = $fn->appendBasicBlock('xh_set_apply');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SGE, $depth, $maxI64),
            $atMaxBb,
            $applyBb
        );

        $context->builder->positionAtEnd($atMaxBb);
        self::writeNullableStringToValue($context, $fn, $out, $previous);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($applyBb);
        $saveBb = $fn->appendBasicBlock('xh_set_save');
        $noSaveBb = $fn->appendBasicBlock('xh_set_no_save');
        $saveDoneBb = $fn->appendBasicBlock('xh_set_save_done');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $depth, $oneI64),
            $saveBb,
            $noSaveBb
        );

        $context->builder->positionAtEnd($saveBb);
        self::storeStackGlobal($context, self::GLOBAL_SAVED_FN, self::loadStackGlobal($context, self::GLOBAL_TOP_FN));
        self::storeStackGlobal($context, self::GLOBAL_SAVED_NAME, self::loadStackGlobal($context, self::GLOBAL_TOP_NAME));
        $context->builder->branch($saveDoneBb);

        $context->builder->positionAtEnd($noSaveBb);
        $context->builder->branch($saveDoneBb);

        $context->builder->positionAtEnd($saveDoneBb);
        self::storeStackGlobal($context, self::GLOBAL_TOP_FN, $fnAddr);
        self::storeStackGlobal($context, self::GLOBAL_TOP_NAME, $handlerName);
        self::storeStackGlobal($context, self::GLOBAL_DEPTH, $context->builder->add($depth, $oneI64));
        self::writeNullableStringToValue($context, $fn, $out, $previous);
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function implementRestoreApplyBridge(Context $context): void
    {
        $abiName = '__phpc_exception_handler_restore_apply';
        $probe = $context->module->getNamedFunction($abiName);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, 'xh_restore_entry')) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $valPtr = $context->getTypeFromString('__value__*');
        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false, $valPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = self::bridgeEntryForEmit($fn, 'xh_restore_entry');
        $context->builder->positionAtEnd($entry);

        $out = $fn->getParam(0);
        $depth = self::loadStackGlobal($context, self::GLOBAL_DEPTH);
        $zeroI64 = $i64->constInt(0, false);
        $oneI64 = $i64->constInt(1, false);
        $oneI32 = $i32->constInt(1, false);
        $nullStr = $strPtr->constNull();

        $emptyBb = $fn->appendBasicBlock('xh_restore_empty');
        $popBb = $fn->appendBasicBlock('xh_restore_pop');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLE, $depth, $zeroI64),
            $emptyBb,
            $popBb
        );

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->call($context->lookupFunction('__value__writeBool'), $out, $oneI32);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($popBb);
        self::emitPopTop($context, $depth, $zeroI64, $oneI64, $nullStr);
        $context->builder->call($context->lookupFunction('__value__writeBool'), $out, $oneI32);
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function implementGetApplyBridge(Context $context): void
    {
        $abiName = '__phpc_exception_handler_get_apply';
        $probe = $context->module->getNamedFunction($abiName);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, 'xh_get_entry')) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $valPtr = $context->getTypeFromString('__value__*');
        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false, $valPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = self::bridgeEntryForEmit($fn, 'xh_get_entry');
        $context->builder->positionAtEnd($entry);

        $out = $fn->getParam(0);
        $depth = self::loadStackGlobal($context, self::GLOBAL_DEPTH);
        $zeroI64 = $i64->constInt(0, false);
        $nullStr = $strPtr->constNull();

        $inactiveBb = $fn->appendBasicBlock('xh_get_inactive');
        $activeBb = $fn->appendBasicBlock('xh_get_active');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLE, $depth, $zeroI64),
            $inactiveBb,
            $activeBb
        );

        $context->builder->positionAtEnd($inactiveBb);
        self::writeNullableStringToValue($context, $fn, $out, $nullStr);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($activeBb);
        $active = self::loadStackGlobal($context, self::GLOBAL_TOP_NAME);
        self::writeNullableStringToValue($context, $fn, $out, $active);
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function emitPopTop(
        Context $context,
        Value $depth,
        Value $zeroI64,
        Value $oneI64,
        Value $nullStr
    ): void {
        $fn = $context->builder->getInsertBlock()->getParent();
        $nestedBb = $fn->appendBasicBlock('xh_pop_nested');
        $clearBb = $fn->appendBasicBlock('xh_pop_clear');
        $doneBb = $fn->appendBasicBlock('xh_pop_done');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SGT, $depth, $oneI64),
            $nestedBb,
            $clearBb
        );

        $context->builder->positionAtEnd($nestedBb);
        self::storeStackGlobal($context, self::GLOBAL_TOP_FN, self::loadStackGlobal($context, self::GLOBAL_SAVED_FN));
        self::storeStackGlobal($context, self::GLOBAL_TOP_NAME, self::loadStackGlobal($context, self::GLOBAL_SAVED_NAME));
        self::storeStackGlobal($context, self::GLOBAL_SAVED_FN, $zeroI64);
        self::storeStackGlobal($context, self::GLOBAL_SAVED_NAME, $nullStr);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($clearBb);
        self::storeStackGlobal($context, self::GLOBAL_TOP_FN, $zeroI64);
        self::storeStackGlobal($context, self::GLOBAL_TOP_NAME, $nullStr);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        self::storeStackGlobal($context, self::GLOBAL_DEPTH, $context->builder->sub($depth, $oneI64));
    }

    private static function optionalCstrToString(
        Context $context,
        LlvmFunction $fn,
        Value $ptr,
        Value $len
    ): Value {
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $strPtr = $context->getTypeFromString('__string__*');
        $nullBb = $fn->appendBasicBlock('xh_name_null');
        $useBb = $fn->appendBasicBlock('xh_name_use');
        $doneBb = $fn->appendBasicBlock('xh_name_done');

        $hasName = $context->builder->and(
            $context->builder->icmp(Builder::INT_NE, $ptr, $i8p->constNull()),
            $context->builder->icmp(Builder::INT_UGT, $len, $sizeT->constInt(0, false))
        );
        $context->builder->branchIf($hasName, $useBb, $nullBb);

        $context->builder->positionAtEnd($nullBb);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($useBb);
        $nameStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zExt($len, $i64),
            $context->builder->pointerCast($ptr, $context->getTypeFromString('char*'))
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $phi = $context->builder->phi($strPtr);
        $phi->addIncoming($strPtr->constNull(), $nullBb);
        $phi->addIncoming($nameStr, $useBb);

        return $phi;
    }

    private static function writeNullableStringToValue(
        Context $context,
        LlvmFunction $fn,
        Value $out,
        Value $maybeStr
    ): void {
        $strPtr = $context->getTypeFromString('__string__*');
        $nullBb = $fn->appendBasicBlock('xh_prev_null');
        $strBb = $fn->appendBasicBlock('xh_prev_str');
        $doneBb = $fn->appendBasicBlock('xh_prev_done');

        $empty = $context->builder->load($context->constantStringFromString(''));
        $isNullPtr = $context->builder->icmp(Builder::INT_EQ, $maybeStr, $strPtr->constNull());
        $isEmpty = $context->builder->or(
            $isNullPtr,
            $context->builder->icmp(Builder::INT_EQ, $maybeStr, $empty)
        );

        $context->builder->branchIf($isEmpty, $nullBb, $strBb);

        $context->builder->positionAtEnd($nullBb);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $out);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($strBb);
        $context->builder->call($context->lookupFunction('__value__writeString'), $out, $maybeStr);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
    }

    private static function emitIndirectCall(Context $context, $fnTy, Value $fnPtr, Value ...$args): Value
    {
        $b = $context->builder;
        if (!$b instanceof LLVMBuilderImpl) {
            throw new \LogicException('LLVM builder required for exception handler indirect call');
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

    private static function ensureStackGlobals(Context $context): void
    {
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $zeroI64 = $i64->constInt(0, false);
        $nullStr = $strPtr->constNull();
        $specs = [
            self::GLOBAL_DEPTH => [$i64, $zeroI64],
            self::GLOBAL_TOP_FN => [$i64, $zeroI64],
            self::GLOBAL_TOP_NAME => [$strPtr, $nullStr],
            self::GLOBAL_SAVED_FN => [$i64, $zeroI64],
            self::GLOBAL_SAVED_NAME => [$strPtr, $nullStr],
        ];
        foreach ($specs as $name => [$ty, $init]) {
            if (null === $context->module->getNamedGlobal($name)) {
                $context->module->addGlobal($ty, $name)->setInitializer($init);
            }
        }
    }

    private static function loadStackGlobal(Context $context, string $name): Value
    {
        $global = $context->module->getNamedGlobal($name);
        if (null === $global) {
            self::ensureStackGlobals($context);
            $global = $context->module->getNamedGlobal($name);
        }

        return $context->builder->load($global);
    }

    private static function storeStackGlobal(Context $context, string $name, Value $value): void
    {
        $global = $context->module->getNamedGlobal($name);
        if (null === $global) {
            self::ensureStackGlobals($context);
            $global = $context->module->getNamedGlobal($name);
        }
        $context->builder->store($value, $global);
    }

    private static function ensureValueWriters(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $valPtr = $context->getTypeFromString('__value__*');
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $voidTy = $context->getTypeFromString('void');

        self::ensureExternal(
            $context,
            '__string__init',
            $context->context->functionType($strPtr, false, $i64, $context->getTypeFromString('char*'))
        );
        self::ensureExternal(
            $context,
            '__value__writeString',
            $context->context->functionType($voidTy, false, $valPtr, $strPtr)
        );
        self::ensureExternal(
            $context,
            '__value__writeNull',
            $context->context->functionType($voidTy, false, $valPtr)
        );
        self::ensureExternal(
            $context,
            '__value__writeBool',
            $context->context->functionType($voidTy, false, $valPtr, $i32)
        );
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

    private static function fullStackReady(Context $context): bool
    {
        return JitVmHelperLink::hasNamedBridgeEntry(
            $context->module->getNamedFunction('__phpc_exception_handler_set_apply'),
            'xh_set_entry'
        ) && JitVmHelperLink::hasNamedBridgeEntry(
            $context->module->getNamedFunction('__phpc_exception_handler_get_apply'),
            'xh_get_entry'
        );
    }

    private static function bridgeEntryForEmit(LlvmFunction $fn, string $entryBlockName): BasicBlock
    {
        try {
            foreach ($fn->getBasicBlocks() as $block) {
                if ($block->getName() === $entryBlockName) {
                    return $block;
                }
            }
            $blocks = $fn->getBasicBlocks();
            $entry = $blocks[0] ?? null;
            if (null !== $entry && null === $entry->getTerminator()) {
                return $entry;
            }
        } catch (\Throwable) {
        }

        return $fn->appendBasicBlock($entryBlockName);
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after ExceptionHandlerJitRuntime implement (#21325)');
            }
            $context->registerFunction($name, $fn);
        }
    }

    private static function captureInsertBlock(Context $context): ?BasicBlock
    {
        try {
            return $context->builder->getInsertBlock();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function restoreInsertBlock(Context $context, ?BasicBlock $block): void
    {
        if (null !== $block) {
            $context->builder->positionAtEnd($block);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
