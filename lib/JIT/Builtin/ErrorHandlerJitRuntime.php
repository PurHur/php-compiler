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
 * JIT/AOT link for __phpc_error_handler_* (#9472, #5316, #17671).
 *
 * Stack state uses LLVM module globals ({@see DefineRuntime} pattern) because nested-JIT
 * static property stores in {@see ErrorHandlerJitHelper} omit string slots on standalone AOT.
 * Thin no-op ABI stubs deleted (#21346). VM SSOT remains {@see ErrorHandlerJitHelper}.
 * php-src: ext/standard/basic_functions.c — set_error_handler, restore_error_handler
 */
final class ErrorHandlerJitRuntime
{
    private const MAX_DEPTH = 8;

    private const GLOBAL_DEPTH = 'phpc_eh_stack_depth';

    private const GLOBAL_TOP_FN = 'phpc_eh_stack_top_fn';

    private const GLOBAL_TOP_MASK = 'phpc_eh_stack_top_mask';

    private const GLOBAL_TOP_NAME = 'phpc_eh_stack_top_name';

    private const GLOBAL_SAVED_FN = 'phpc_eh_stack_saved_fn';

    private const GLOBAL_SAVED_MASK = 'phpc_eh_stack_saved_mask';

    private const GLOBAL_SAVED_NAME = 'phpc_eh_stack_saved_name';

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__phpc_error_handler_dispatch',
        '__phpc_error_handler_set_apply',
        '__phpc_error_handler_restore_apply',
        '__phpc_error_handler_get_apply',
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
        $abiName = '__phpc_error_handler_dispatch';
        $probe = $context->module->getNamedFunction($abiName);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, 'eh_dispatch_entry')) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $cbFnTy = $context->context->functionType($i32, false, $i32, $i8p, $sizeT, $i32);
        $cbPtrTy = $cbFnTy->pointerType(0);
        $ft = $context->context->functionType($i32, false, $i32, $i8p, $sizeT, $i32);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = self::bridgeEntryForEmit($fn, 'eh_dispatch_entry');
        $context->builder->positionAtEnd($entry);

        $errno = $fn->getParam(0);
        $msg = $fn->getParam(1);
        $msgLen = $fn->getParam(2);
        $line = $fn->getParam(3);
        $zeroI32 = $i32->constInt(0, false);

        $resolveBb = $fn->appendBasicBlock('eh_dispatch_resolve');
        $context->builder->branch($resolveBb);
        $context->builder->positionAtEnd($resolveBb);
        [$fnAddr, $afterResolveBb] = self::emitResolveHandlerAddrFromGlobals($context, $fn, $errno);
        $context->builder->positionAtEnd($afterResolveBb);
        $noHandlerBb = $fn->appendBasicBlock('eh_dispatch_no_handler');
        $callBb = $fn->appendBasicBlock('eh_dispatch_call');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $fnAddr, $i64->constInt(0, false)),
            $noHandlerBb,
            $callBb
        );

        $context->builder->positionAtEnd($noHandlerBb);
        $context->builder->returnValue($zeroI32);

        $context->builder->positionAtEnd($callBb);
        $msgNullBb = $fn->appendBasicBlock('eh_dispatch_msg_null');
        $msgOkBb = $fn->appendBasicBlock('eh_dispatch_msg_ok');
        $msgDoneBb = $fn->appendBasicBlock('eh_dispatch_msg_done');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $msg, $i8p->constNull()),
            $msgNullBb,
            $msgOkBb
        );

        $context->builder->positionAtEnd($msgNullBb);
        $emptyStr = $context->builder->pointerCast($context->constantFromString(''), $i8p);
        $emptyLen = $sizeT->constInt(0, false);
        $context->builder->branch($msgDoneBb);

        $context->builder->positionAtEnd($msgOkBb);
        $context->builder->branch($msgDoneBb);

        $context->builder->positionAtEnd($msgDoneBb);
        $msgPhi = $context->builder->phi($i8p);
        $msgPhi->addIncoming($emptyStr, $msgNullBb);
        $msgPhi->addIncoming($msg, $msgOkBb);
        $lenPhi = $context->builder->phi($sizeT);
        $lenPhi->addIncoming($emptyLen, $msgNullBb);
        $lenPhi->addIncoming($msgLen, $msgOkBb);

        $fnPtr = $context->builder->intToPtr($fnAddr, $i8p);
        $cb = $context->builder->pointerCast($fnPtr, $cbPtrTy);
        $handled = self::emitIndirectCall($context, $cbFnTy, $cb, $errno, $msgPhi, $lenPhi, $line);
        $truthy = $context->builder->icmp(Builder::INT_NE, $handled, $zeroI32);
        $context->builder->returnValue($context->builder->zext($truthy, $i32));
        $context->registerFunction($abiName, $fn);
    }

    private static function implementSetApplyBridge(Context $context): void
    {
        $abiName = '__phpc_error_handler_set_apply';
        $probe = $context->module->getNamedFunction($abiName);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, 'eh_set_entry')) {
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
        $ft = $context->context->functionType($voidTy, false, $valPtr, $i8p, $sizeT, $i8p, $i32);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = self::bridgeEntryForEmit($fn, 'eh_set_entry');
        $context->builder->positionAtEnd($entry);

        $out = $fn->getParam(0);
        $name = $fn->getParam(1);
        $nameLen = $fn->getParam(2);
        $fnOpaque = $fn->getParam(3);
        $mask = $fn->getParam(4);

        $fnAddr = $context->builder->ptrToInt($fnOpaque, $i64);
        $handlerName = self::optionalCstrToString($context, $fn, $name, $nameLen);
        $maskI64 = $context->builder->sext($mask, $i64);
        $depth = self::loadStackGlobal($context, self::GLOBAL_DEPTH);
        $zeroI64 = $i64->constInt(0, false);
        $oneI64 = $i64->constInt(1, false);
        $maxI64 = $i64->constInt(self::MAX_DEPTH, false);
        $nullStr = $strPtr->constNull();

        $hasPrevBb = $fn->appendBasicBlock('eh_set_has_prev');
        $noPrevBb = $fn->appendBasicBlock('eh_set_no_prev');
        $prevDoneBb = $fn->appendBasicBlock('eh_set_prev_done');
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

        $atMaxBb = $fn->appendBasicBlock('eh_set_at_max');
        $applyBb = $fn->appendBasicBlock('eh_set_apply');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SGE, $depth, $maxI64),
            $atMaxBb,
            $applyBb
        );

        $context->builder->positionAtEnd($atMaxBb);
        self::writeNullableStringToValue($context, $fn, $out, $previous);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($applyBb);
        $saveBb = $fn->appendBasicBlock('eh_set_save');
        $noSaveBb = $fn->appendBasicBlock('eh_set_no_save');
        $saveDoneBb = $fn->appendBasicBlock('eh_set_save_done');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $depth, $oneI64),
            $saveBb,
            $noSaveBb
        );

        $context->builder->positionAtEnd($saveBb);
        self::storeStackGlobal($context, self::GLOBAL_SAVED_FN, self::loadStackGlobal($context, self::GLOBAL_TOP_FN));
        self::storeStackGlobal($context, self::GLOBAL_SAVED_MASK, self::loadStackGlobal($context, self::GLOBAL_TOP_MASK));
        self::storeStackGlobal($context, self::GLOBAL_SAVED_NAME, self::loadStackGlobal($context, self::GLOBAL_TOP_NAME));
        $context->builder->branch($saveDoneBb);

        $context->builder->positionAtEnd($noSaveBb);
        $context->builder->branch($saveDoneBb);

        $context->builder->positionAtEnd($saveDoneBb);
        self::storeStackGlobal($context, self::GLOBAL_TOP_FN, $fnAddr);
        self::storeStackGlobal($context, self::GLOBAL_TOP_MASK, $maskI64);
        self::storeStackGlobal($context, self::GLOBAL_TOP_NAME, $handlerName);
        self::storeStackGlobal($context, self::GLOBAL_DEPTH, $context->builder->add($depth, $oneI64));
        self::writeNullableStringToValue($context, $fn, $out, $previous);
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function implementRestoreApplyBridge(Context $context): void
    {
        $abiName = '__phpc_error_handler_restore_apply';
        $probe = $context->module->getNamedFunction($abiName);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, 'eh_restore_entry')) {
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

        $entry = self::bridgeEntryForEmit($fn, 'eh_restore_entry');
        $context->builder->positionAtEnd($entry);

        $out = $fn->getParam(0);
        $depth = self::loadStackGlobal($context, self::GLOBAL_DEPTH);
        $zeroI64 = $i64->constInt(0, false);
        $oneI64 = $i64->constInt(1, false);
        $oneI32 = $i32->constInt(1, false);
        $nullStr = $strPtr->constNull();

        $emptyBb = $fn->appendBasicBlock('eh_restore_empty');
        $popBb = $fn->appendBasicBlock('eh_restore_pop');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLE, $depth, $zeroI64),
            $emptyBb,
            $popBb
        );

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->call($context->lookupFunction('__value__writeBool'), $out, $oneI32);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($popBb);
        $nestedBb = $fn->appendBasicBlock('eh_restore_nested');
        $clearBb = $fn->appendBasicBlock('eh_restore_clear');
        $restoreDoneBb = $fn->appendBasicBlock('eh_restore_done');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SGT, $depth, $oneI64),
            $nestedBb,
            $clearBb
        );

        $context->builder->positionAtEnd($nestedBb);
        self::storeStackGlobal($context, self::GLOBAL_TOP_FN, self::loadStackGlobal($context, self::GLOBAL_SAVED_FN));
        self::storeStackGlobal($context, self::GLOBAL_TOP_MASK, self::loadStackGlobal($context, self::GLOBAL_SAVED_MASK));
        self::storeStackGlobal($context, self::GLOBAL_TOP_NAME, self::loadStackGlobal($context, self::GLOBAL_SAVED_NAME));
        self::storeStackGlobal($context, self::GLOBAL_SAVED_FN, $zeroI64);
        self::storeStackGlobal($context, self::GLOBAL_SAVED_MASK, $zeroI64);
        self::storeStackGlobal($context, self::GLOBAL_SAVED_NAME, $nullStr);
        $context->builder->branch($restoreDoneBb);

        $context->builder->positionAtEnd($clearBb);
        self::storeStackGlobal($context, self::GLOBAL_TOP_FN, $zeroI64);
        self::storeStackGlobal($context, self::GLOBAL_TOP_MASK, $zeroI64);
        self::storeStackGlobal($context, self::GLOBAL_TOP_NAME, $nullStr);
        $context->builder->branch($restoreDoneBb);

        $context->builder->positionAtEnd($restoreDoneBb);
        self::storeStackGlobal($context, self::GLOBAL_DEPTH, $context->builder->sub($depth, $oneI64));
        $context->builder->call($context->lookupFunction('__value__writeBool'), $out, $oneI32);
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function implementGetApplyBridge(Context $context): void
    {
        $abiName = '__phpc_error_handler_get_apply';
        $probe = $context->module->getNamedFunction($abiName);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, 'eh_get_entry')) {
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

        $entry = self::bridgeEntryForEmit($fn, 'eh_get_entry');
        $context->builder->positionAtEnd($entry);

        $out = $fn->getParam(0);
        $depth = self::loadStackGlobal($context, self::GLOBAL_DEPTH);
        $zeroI64 = $i64->constInt(0, false);
        $nullStr = $strPtr->constNull();

        $inactiveBb = $fn->appendBasicBlock('eh_get_inactive');
        $activeBb = $fn->appendBasicBlock('eh_get_active');
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
        $nullBb = $fn->appendBasicBlock('eh_name_null');
        $useBb = $fn->appendBasicBlock('eh_name_use');
        $doneBb = $fn->appendBasicBlock('eh_name_done');

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
        $i64 = $context->getTypeFromString('int64');
        $nullBb = $fn->appendBasicBlock('eh_prev_null');
        $checkBb = $fn->appendBasicBlock('eh_prev_check');
        $emptyBb = $fn->appendBasicBlock('eh_prev_empty');
        $writeBb = $fn->appendBasicBlock('eh_prev_write');
        $doneBb = $fn->appendBasicBlock('eh_prev_done');

        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $maybeStr, $strPtr->constNull()),
            $nullBb,
            $checkBb
        );

        $context->builder->positionAtEnd($nullBb);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $out);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($checkBb);
        $strMap = $context->structFieldMap['__string__'];
        $strLen = $context->builder->load(
            $context->builder->structGep($maybeStr, $strMap['length'])
        );
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $strLen, $i64->constInt(0, false)),
            $emptyBb,
            $writeBb
        );

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $out);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($writeBb);
        $context->builder->call($context->lookupFunction('__value__writeString'), $out, $maybeStr);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
    }

    private static function emitIndirectCall(Context $context, $fnTy, Value $fnPtr, Value ...$args): Value
    {
        $b = $context->builder;
        if (!$b instanceof LLVMBuilderImpl) {
            throw new \LogicException('LLVM builder required for error handler indirect call');
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

    /**
     * @return array{0: Value, 1: BasicBlock}
     */
    private static function emitResolveHandlerAddrFromGlobals(
        Context $context,
        LlvmFunction $fn,
        Value $errno
    ): array {
        $i64 = $context->getTypeFromString('int64');
        $zeroI64 = $i64->constInt(0, false);
        $depth = self::loadStackGlobal($context, self::GLOBAL_DEPTH);
        $errnoI64 = $context->builder->sext($errno, $i64);

        $doneBb = $fn->appendBasicBlock('eh_resolve_done');
        $afterBb = $fn->appendBasicBlock('eh_resolve_after');
        $inactiveBb = $fn->appendBasicBlock('eh_resolve_inactive');
        $checkFnBb = $fn->appendBasicBlock('eh_resolve_check_fn');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLE, $depth, $zeroI64),
            $inactiveBb,
            $checkFnBb
        );

        $context->builder->positionAtEnd($inactiveBb);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($checkFnBb);
        $topFn = self::loadStackGlobal($context, self::GLOBAL_TOP_FN);
        $noFnBb = $fn->appendBasicBlock('eh_resolve_no_fn');
        $checkMaskBb = $fn->appendBasicBlock('eh_resolve_check_mask');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $topFn, $zeroI64),
            $noFnBb,
            $checkMaskBb
        );

        $context->builder->positionAtEnd($noFnBb);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($checkMaskBb);
        $topMask = self::loadStackGlobal($context, self::GLOBAL_TOP_MASK);
        $masked = $context->builder->and($topMask, $errnoI64);
        $noMaskBb = $fn->appendBasicBlock('eh_resolve_no_mask');
        $matchBb = $fn->appendBasicBlock('eh_resolve_match');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $masked, $zeroI64),
            $noMaskBb,
            $matchBb
        );

        $context->builder->positionAtEnd($noMaskBb);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($matchBb);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $addrPhi = $context->builder->phi($i64);
        $addrPhi->addIncoming($zeroI64, $inactiveBb);
        $addrPhi->addIncoming($zeroI64, $noFnBb);
        $addrPhi->addIncoming($zeroI64, $noMaskBb);
        $addrPhi->addIncoming($topFn, $matchBb);
        $context->builder->branch($afterBb);

        return [$addrPhi, $afterBb];
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
            self::GLOBAL_TOP_MASK => [$i64, $zeroI64],
            self::GLOBAL_TOP_NAME => [$strPtr, $nullStr],
            self::GLOBAL_SAVED_FN => [$i64, $zeroI64],
            self::GLOBAL_SAVED_MASK => [$i64, $zeroI64],
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
            '__value__readString',
            $context->context->functionType($strPtr, false, $valPtr)
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
            $context->module->getNamedFunction('__phpc_error_handler_set_apply'),
            'eh_set_entry'
        ) && JitVmHelperLink::hasNamedBridgeEntry(
            $context->module->getNamedFunction('__phpc_error_handler_get_apply'),
            'eh_get_entry'
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
                throw new \LogicException($name.' missing after ErrorHandlerJitRuntime bridge (#9472)');
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
