<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\LLVMAbstract\Builder as LLVMBuilderImpl;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;
use llvm\LLVMValueRef_ptr;

/**
 * JIT/AOT link for __phpc_exception_handler_* via ExceptionHandlerJitHelper PHP (#9473).
 *
 * Stack storage lives in compiled {@see ExceptionHandlerJitHelper}; thin LLVM bridges forward the ABI.
 * php-src: ext/standard/basic_functions.c — set_exception_handler, restore_exception_handler
 */
final class ExceptionHandlerJitRuntime
{
    private const HELPER_PATH = '/ext/standard/ExceptionHandlerJitHelper.php';

    private const SET_APPLY_HELPER = 'PHPCompiler\\ext\\standard\\ExceptionHandlerJitHelper::setApply';

    private const RESTORE_HELPER = 'PHPCompiler\\ext\\standard\\ExceptionHandlerJitHelper::restoreApply';

    private const DEPTH_HELPER = 'PHPCompiler\\ext\\standard\\ExceptionHandlerJitHelper::currentDepth';

    private const FN_AT_HELPER = 'PHPCompiler\\ext\\standard\\ExceptionHandlerJitHelper::handlerFnAddrAt';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::SET_APPLY_HELPER,
        self::RESTORE_HELPER,
        self::DEPTH_HELPER,
        self::FN_AT_HELPER,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__phpc_exception_handler_dispatch',
        '__phpc_exception_handler_set_apply',
        '__phpc_exception_handler_restore_apply',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__phpc_exception_handler_dispatch');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            $restoreBlock = self::captureInsertBlock($context);
            self::ensureValueWriters($context);
            self::implementStandaloneThinAbi($context);
            self::registerLinkedRuntime($context);
            self::restoreInsertBlock($context, $restoreBlock);

            return;
        }

        $restoreBlock = self::captureInsertBlock($context);
        self::ensureJitHelperCompiled($context);
        self::ensureValueWriters($context);
        self::implementDispatchBridge($context);
        self::implementSetApplyBridge($context);
        self::implementRestoreApplyBridge($context);
        self::registerLinkedRuntime($context);
        self::restoreInsertBlock($context, $restoreBlock);
    }

    private static function implementStandaloneThinAbi(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $valPtr = $context->getTypeFromString('__value__*');
        $objPtr = $context->getTypeFromString('__object__*');
        $voidTy = $context->getTypeFromString('void');
        $savedBuilder = $context->builder;

        $dispatch = self::standaloneAbiFunction(
            $context,
            '__phpc_exception_handler_dispatch',
            $context->context->functionType($i32, false, $objPtr)
        );
        if (0 === $dispatch->countBasicBlocks()) {
            $entry = $dispatch->appendBasicBlock('entry');
            $context->builder = $context->context->builderCreate();
            $context->builder->positionAtEnd($entry);
            $context->builder->returnValue($i32->constInt(0, false));
            $context->builder->clearInsertionPosition();
        }
        $context->registerFunction('__phpc_exception_handler_dispatch', $dispatch);

        $setApply = self::standaloneAbiFunction(
            $context,
            '__phpc_exception_handler_set_apply',
            $context->context->functionType($voidTy, false, $valPtr, $i8p, $sizeT, $i8p)
        );
        if (0 === $setApply->countBasicBlocks()) {
            $entry = $setApply->appendBasicBlock('entry');
            $context->builder = $context->context->builderCreate();
            $context->builder->positionAtEnd($entry);
            $context->builder->call(
                $context->lookupFunction('__value__writeNull'),
                $setApply->getParam(0)
            );
            $context->builder->returnVoid();
            $context->builder->clearInsertionPosition();
        }
        $context->registerFunction('__phpc_exception_handler_set_apply', $setApply);

        $restoreApply = self::standaloneAbiFunction(
            $context,
            '__phpc_exception_handler_restore_apply',
            $context->context->functionType($voidTy, false, $valPtr)
        );
        if (0 === $restoreApply->countBasicBlocks()) {
            $entry = $restoreApply->appendBasicBlock('entry');
            $context->builder = $context->context->builderCreate();
            $context->builder->positionAtEnd($entry);
            $context->builder->call(
                $context->lookupFunction('__value__writeBool'),
                $restoreApply->getParam(0),
                $i32->constInt(0, false)
            );
            $context->builder->returnVoid();
            $context->builder->clearInsertionPosition();
        }
        $context->registerFunction('__phpc_exception_handler_restore_apply', $restoreApply);

        $context->builder = $savedBuilder;
    }

    private static function standaloneAbiFunction(Context $context, string $abiName, $ft): LlvmFunction
    {
        $probe = $context->module->getNamedFunction($abiName);
        if (null === $probe) {
            $context->module->addFunction($abiName, $ft);
            $probe = $context->module->getNamedFunction($abiName);
        }
        if (null === $probe) {
            throw new \LogicException($abiName.' missing after standalone ABI declare (#9473)');
        }

        return $probe;
    }

    private static function implementDispatchBridge(Context $context): void
    {
        $abiName = '__phpc_exception_handler_dispatch';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
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

        $entry = $fn->appendBasicBlock('xh_dispatch_entry');
        $context->builder->positionAtEnd($entry);

        $exception = $fn->getParam(0);
        $zeroI32 = $i32->constInt(0, false);
        $oneI32 = $i32->constInt(1, false);

        $depth = $context->builder->call(self::helperFunction($context, self::DEPTH_HELPER));
        $emptyBb = $fn->appendBasicBlock('xh_dispatch_empty');
        $loopInitBb = $fn->appendBasicBlock('xh_dispatch_loop_init');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLE, $depth, $zeroI32),
            $emptyBb,
            $loopInitBb
        );

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->returnValue($zeroI32);

        $loopCondBb = $fn->appendBasicBlock('xh_dispatch_loop_cond');
        $loopBodyBb = $fn->appendBasicBlock('xh_dispatch_loop_body');
        $loopIncBb = $fn->appendBasicBlock('xh_dispatch_loop_inc');
        $loopDoneBb = $fn->appendBasicBlock('xh_dispatch_loop_done');

        $context->builder->positionAtEnd($loopInitBb);
        $idxVar = $context->builder->alloca($i32, 'xh_idx');
        $context->builder->store($context->builder->sub($depth, $oneI32), $idxVar);
        $context->builder->branch($loopCondBb);

        $context->builder->positionAtEnd($loopCondBb);
        $idx = $context->builder->load($idxVar);
        $continueLoop = $context->builder->icmp(Builder::INT_SGE, $idx, $zeroI32);
        $context->builder->branchIf($continueLoop, $loopBodyBb, $loopDoneBb);

        $context->builder->positionAtEnd($loopBodyBb);
        $fnAddr = $context->builder->call(
            self::helperFunction($context, self::FN_AT_HELPER),
            $context->builder->sext($idx, $i64)
        );
        $noFnBb = $fn->appendBasicBlock('xh_dispatch_no_fn');
        $callBb = $fn->appendBasicBlock('xh_dispatch_call');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $fnAddr, $i64->constInt(0, false)),
            $noFnBb,
            $callBb
        );

        $context->builder->positionAtEnd($noFnBb);
        $context->builder->branch($loopIncBb);

        $context->builder->positionAtEnd($callBb);
        $fnPtr = $context->builder->intToPtr($fnAddr, $i8p);
        $cb = $context->builder->pointerCast($fnPtr, $cbPtrTy);
        $handled = self::emitIndirectCall($context, $cbFnTy, $cb, $exception);
        $truthy = $context->builder->icmp(Builder::INT_NE, $handled, $zeroI32);
        $handledBb = $fn->appendBasicBlock('xh_dispatch_handled');
        $context->builder->branchIf($truthy, $handledBb, $loopIncBb);

        $context->builder->positionAtEnd($handledBb);
        $context->builder->returnValue($oneI32);

        $context->builder->positionAtEnd($loopIncBb);
        $context->builder->store($context->builder->sub($idx, $oneI32), $idxVar);
        $context->builder->branch($loopCondBb);

        $context->builder->positionAtEnd($loopDoneBb);
        $context->builder->returnValue($zeroI32);
        $context->registerFunction($abiName, $fn);
    }

    private static function implementSetApplyBridge(Context $context): void
    {
        $abiName = '__phpc_exception_handler_set_apply';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $valPtr = $context->getTypeFromString('__value__*');
        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false, $valPtr, $i8p, $sizeT, $i8p);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('xh_set_entry');
        $context->builder->positionAtEnd($entry);

        $out = $fn->getParam(0);
        $name = $fn->getParam(1);
        $nameLen = $fn->getParam(2);
        $fnOpaque = $fn->getParam(3);

        $fnAddr = $context->builder->ptrToInt($fnOpaque, $i64);
        $handlerName = self::optionalCstrToString($context, $fn, $name, $nameLen);
        $previous = $context->builder->call(
            self::helperFunction($context, self::SET_APPLY_HELPER),
            $fnAddr,
            $handlerName
        );

        self::writeNullableStringToValue($context, $fn, $out, $previous);
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function implementRestoreApplyBridge(Context $context): void
    {
        $abiName = '__phpc_exception_handler_restore_apply';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i32 = $context->getTypeFromString('int32');
        $valPtr = $context->getTypeFromString('__value__*');
        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false, $valPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('xh_restore_entry');
        $context->builder->positionAtEnd($entry);

        $out = $fn->getParam(0);
        $restored = $context->builder->call(self::helperFunction($context, self::RESTORE_HELPER));
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            $out,
            $context->builder->zext($restored, $i32)
        );
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

        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $maybeStr, $strPtr->constNull()),
            $nullBb,
            $strBb
        );

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

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after ExceptionHandlerJitHelper compile (#9473)');
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'ExceptionHandlerJitHelper.php');
            if (null === $block) {
                throw new \LogicException('ExceptionHandlerJitHelper.php parseAndCompile failed (#9473)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#9473)');
            }
        }
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

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after ExceptionHandlerJitRuntime bridge (#9473)');
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
