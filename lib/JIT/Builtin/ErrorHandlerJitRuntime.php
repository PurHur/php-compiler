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
 * JIT/AOT link for __phpc_error_handler_* via ErrorHandlerJitHelper PHP (#9472, #5316).
 *
 * Stack storage lives in compiled {@see ErrorHandlerJitHelper}; thin LLVM bridges forward the ABI.
 * php-src: ext/standard/basic_functions.c — set_error_handler, restore_error_handler
 */
final class ErrorHandlerJitRuntime
{
    private const HELPER_PATH = '/ext/standard/ErrorHandlerJitHelper.php';

    private const SET_APPLY_HELPER = 'PHPCompiler\\ext\\standard\\ErrorHandlerJitHelper::setApply';

    private const RESTORE_HELPER = 'PHPCompiler\\ext\\standard\\ErrorHandlerJitHelper::restoreApply';

    private const RESOLVE_HELPER = 'PHPCompiler\\ext\\standard\\ErrorHandlerJitHelper::resolveHandlerAddr';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::SET_APPLY_HELPER,
        self::RESTORE_HELPER,
        self::RESOLVE_HELPER,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__phpc_error_handler_dispatch',
        '__phpc_error_handler_set_apply',
        '__phpc_error_handler_restore_apply',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__phpc_error_handler_dispatch');
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
        $voidTy = $context->getTypeFromString('void');
        $savedBuilder = $context->builder;

        $dispatch = self::standaloneAbiFunction(
            $context,
            '__phpc_error_handler_dispatch',
            $context->context->functionType($i32, false, $i32, $i8p, $sizeT, $i32)
        );
        if (0 === $dispatch->countBasicBlocks()) {
            $entry = $dispatch->appendBasicBlock('entry');
            $context->builder = $context->context->builderCreate();
            $context->builder->positionAtEnd($entry);
            $context->builder->returnValue($i32->constInt(0, false));
            $context->builder->clearInsertionPosition();
        }
        $context->registerFunction('__phpc_error_handler_dispatch', $dispatch);

        $setApply = self::standaloneAbiFunction(
            $context,
            '__phpc_error_handler_set_apply',
            $context->context->functionType($voidTy, false, $valPtr, $i8p, $sizeT, $i8p, $i32)
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
        $context->registerFunction('__phpc_error_handler_set_apply', $setApply);

        $restoreApply = self::standaloneAbiFunction(
            $context,
            '__phpc_error_handler_restore_apply',
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
        $context->registerFunction('__phpc_error_handler_restore_apply', $restoreApply);

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
            throw new \LogicException($abiName.' missing after standalone ABI declare (#9472)');
        }

        return $probe;
    }

    private static function implementDispatchBridge(Context $context): void
    {
        $abiName = '__phpc_error_handler_dispatch';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
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

        $entry = $fn->appendBasicBlock('eh_dispatch_entry');
        $context->builder->positionAtEnd($entry);

        $errno = $fn->getParam(0);
        $msg = $fn->getParam(1);
        $msgLen = $fn->getParam(2);
        $line = $fn->getParam(3);
        $zeroI32 = $i32->constInt(0, false);

        $fnAddr = $context->builder->call(
            self::helperFunction($context, self::RESOLVE_HELPER),
            $context->builder->sext($errno, $i64)
        );
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
        $ft = $context->context->functionType($voidTy, false, $valPtr, $i8p, $sizeT, $i8p, $i32);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('eh_set_entry');
        $context->builder->positionAtEnd($entry);

        $out = $fn->getParam(0);
        $name = $fn->getParam(1);
        $nameLen = $fn->getParam(2);
        $fnOpaque = $fn->getParam(3);
        $mask = $fn->getParam(4);

        $fnAddr = $context->builder->ptrToInt($fnOpaque, $i64);
        $handlerName = self::optionalCstrToString($context, $fn, $name, $nameLen);
        $previous = $context->builder->call(
            self::helperFunction($context, self::SET_APPLY_HELPER),
            $fnAddr,
            $context->builder->sext($mask, $i64),
            $handlerName
        );

        self::writeNullableStringToValue($context, $fn, $out, $previous);
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function implementRestoreApplyBridge(Context $context): void
    {
        $abiName = '__phpc_error_handler_restore_apply';
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

        $entry = $fn->appendBasicBlock('eh_restore_entry');
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
        $nullBb = $fn->appendBasicBlock('eh_prev_null');
        $strBb = $fn->appendBasicBlock('eh_prev_str');
        $doneBb = $fn->appendBasicBlock('eh_prev_done');

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

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after ErrorHandlerJitHelper compile (#9472)');
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'ErrorHandlerJitHelper.php');
            if (null === $block) {
                throw new \LogicException('ErrorHandlerJitHelper.php parseAndCompile failed (#9472)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#9472)');
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
