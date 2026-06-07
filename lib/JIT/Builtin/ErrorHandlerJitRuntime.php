<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\LLVMAbstract\Builder as LLVMBuilderImpl;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;
use llvm\LLVMValueRef_ptr;

/**
 * LLVM error-handler stack for set_error_handler() / restore_error_handler() (#5316, #1379).
 *
 * Replaces {@see lib/AOT/runtime/phpc_error_handler.c}. Semantics mirror
 * {@see \PHPCompiler\ext\standard\VmErrorHandler} / php-src basic_functions.c.
 */
final class ErrorHandlerJitRuntime
{
    private const MAX = 32;

    private const GLOBAL_DEPTH = 'phpc_error_handler_depth';

    private const GLOBAL_FN = 'phpc_error_handler_fn';

    private const GLOBAL_MASK = 'phpc_error_handler_mask';

    private const GLOBAL_NAME = 'phpc_error_handler_name';

    /** @var Value|null */
    private static $depthGlobal = null;

    /** @var Value|null */
    private static $fnGlobal = null;

    /** @var Value|null */
    private static $maskGlobal = null;

    /** @var Value|null */
    private static $nameGlobal = null;

    /** @var list<string> */
    private const RUNTIME_FNS = [
        '__phpc_error_handler_dispatch',
        '__phpc_error_handler_set_apply',
        '__phpc_error_handler_restore_apply',
    ];

    public static function ensureLinked(Context $context): void
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

        $restoreBlock = self::captureInsertBlock($context);
        self::ensureGlobals($context);
        self::ensureLibc($context);
        self::ensureValueWriters($context);

        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $valPtr = $context->getTypeFromString('__value__*');
        $i8p = $context->getTypeFromString('int8*');
        $voidTy = $context->getTypeFromString('void');
        $cbFnTy = $context->context->functionType($i32, false, $i32, $i8p, $sizeT, $i32);
        $cbPtrTy = $cbFnTy->pointerType(0);

        $dispatchProbe = $context->module->getNamedFunction('__phpc_error_handler_dispatch');
        $ftDispatch = $context->context->functionType($i32, false, $i32, $i8p, $sizeT, $i32);
        $fnDispatch = null !== $dispatchProbe
            ? $dispatchProbe
            : $context->module->addFunction('__phpc_error_handler_dispatch', $ftDispatch);
        self::implementDispatch($context, $fnDispatch, $i32, $i8p, $sizeT, $cbFnTy, $cbPtrTy);

        $setProbe = $context->module->getNamedFunction('__phpc_error_handler_set_apply');
        $ftSet = $context->context->functionType($voidTy, false, $valPtr, $i8p, $sizeT, $i8p, $i32);
        $fnSet = null !== $setProbe
            ? $setProbe
            : $context->module->addFunction('__phpc_error_handler_set_apply', $ftSet);
        self::implementSetApply($context, $fnSet, $i32, $i8p, $sizeT);

        $restoreProbe = $context->module->getNamedFunction('__phpc_error_handler_restore_apply');
        $ftRestore = $context->context->functionType($voidTy, false, $valPtr);
        $fnRestore = null !== $restoreProbe
            ? $restoreProbe
            : $context->module->addFunction('__phpc_error_handler_restore_apply', $ftRestore);
        self::implementRestoreApply($context, $fnRestore, $i32, $i8p);

        self::registerLinkedRuntime($context);
        self::restoreInsertBlock($context, $restoreBlock);
    }

    private static function implementDispatch(
        Context $context,
        LlvmFunction $fn,
        $i32,
        $i8p,
        $sizeT,
        $cbFnTy,
        $cbPtrTy
    ): void {
        $entry = $fn->appendBasicBlock('eh_dispatch_entry');
        $context->builder->positionAtEnd($entry);

        $zeroI32 = $i32->constInt(0, false);
        $errno = $fn->getParam(0);
        $msg = $fn->getParam(1);
        $msgLen = $fn->getParam(2);
        $line = $fn->getParam(3);

        $depth = $context->builder->load(self::$depthGlobal);
        $emptyBb = $fn->appendBasicBlock('eh_dispatch_empty');
        $workBb = $fn->appendBasicBlock('eh_dispatch_work');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLE, $depth, $zeroI32),
            $emptyBb,
            $workBb
        );

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->returnValue($zeroI32);

        $context->builder->positionAtEnd($workBb);
        $topIdx = $context->builder->sub($depth, $i32->constInt(1, false));
        $handlerFn = $context->builder->load(self::fnSlot($context, $i32, $topIdx));
        $noFnBb = $fn->appendBasicBlock('eh_dispatch_no_fn');
        $maskBb = $fn->appendBasicBlock('eh_dispatch_mask');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $handlerFn, $i8p->constNull()),
            $noFnBb,
            $maskBb
        );

        $context->builder->positionAtEnd($noFnBb);
        $context->builder->returnValue($zeroI32);

        $context->builder->positionAtEnd($maskBb);
        $mask = $context->builder->load(self::maskSlot($context, $i32, $topIdx));
        $masked = $context->builder->and($mask, $errno);
        $unmaskedBb = $fn->appendBasicBlock('eh_dispatch_unmasked');
        $callBb = $fn->appendBasicBlock('eh_dispatch_call');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $masked, $zeroI32),
            $unmaskedBb,
            $callBb
        );

        $context->builder->positionAtEnd($unmaskedBb);
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

        $cb = $context->builder->pointerCast($handlerFn, $cbPtrTy);
        $handled = self::emitIndirectCall($context, $cbFnTy, $cb, $errno, $msgPhi, $lenPhi, $line);
        $truthy = $context->builder->icmp(Builder::INT_NE, $handled, $zeroI32);
        $context->builder->returnValue($context->builder->zext($truthy, $i32));
    }

    private static function implementSetApply(
        Context $context,
        LlvmFunction $fn,
        $i32,
        $i8p,
        $sizeT
    ): void {
        $entry = $fn->appendBasicBlock('eh_set_entry');
        $context->builder->positionAtEnd($entry);

        $voidPtr = $context->getTypeFromString('void*');
        $zeroI32 = $i32->constInt(0, false);
        $oneI32 = $i32->constInt(1, false);

        $out = $fn->getParam(0);
        $name = $fn->getParam(1);
        $nameLen = $fn->getParam(2);
        $fnOpaque = $fn->getParam(3);
        $mask = $fn->getParam(4);

        $depth = $context->builder->load(self::$depthGlobal);
        $hasPrevBb = $fn->appendBasicBlock('eh_set_has_prev');
        $noPrevBb = $fn->appendBasicBlock('eh_set_no_prev');
        $prevDoneBb = $fn->appendBasicBlock('eh_set_prev_done');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SGT, $depth, $zeroI32),
            $hasPrevBb,
            $noPrevBb
        );

        $context->builder->positionAtEnd($noPrevBb);
        self::writeValueNull($context, $out);
        $context->builder->branch($prevDoneBb);

        $context->builder->positionAtEnd($hasPrevBb);
        $prevIdx = $context->builder->sub($depth, $oneI32);
        $prevName = $context->builder->load(self::nameSlot($context, $i32, $prevIdx));
        $prevNullBb = $fn->appendBasicBlock('eh_set_prev_null');
        $prevStrBb = $fn->appendBasicBlock('eh_set_prev_str');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $prevName, $i8p->constNull()),
            $prevNullBb,
            $prevStrBb
        );

        $context->builder->positionAtEnd($prevNullBb);
        self::writeValueNull($context, $out);
        $context->builder->branch($prevDoneBb);

        $context->builder->positionAtEnd($prevStrBb);
        self::writeValueStringFromCstr($context, $out, $prevName);
        $context->builder->branch($prevDoneBb);

        $context->builder->positionAtEnd($prevDoneBb);
        $fullBb = $fn->appendBasicBlock('eh_set_full');
        $pushBb = $fn->appendBasicBlock('eh_set_push');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SGE, $depth, $i32->constInt(self::MAX, false)),
            $fullBb,
            $pushBb
        );

        $context->builder->positionAtEnd($fullBb);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($pushBb);
        $context->builder->store($fnOpaque, self::fnSlot($context, $i32, $depth));
        $context->builder->store($mask, self::maskSlot($context, $i32, $depth));
        $context->builder->store($i8p->constNull(), self::nameSlot($context, $i32, $depth));

        $hasNameBb = $fn->appendBasicBlock('eh_set_has_name');
        $noNameBb = $fn->appendBasicBlock('eh_set_no_name');
        $nameDoneBb = $fn->appendBasicBlock('eh_set_name_done');
        $context->builder->branchIf(
            $context->builder->and(
                $context->builder->icmp(Builder::INT_NE, $name, $i8p->constNull()),
                $context->builder->icmp(Builder::INT_UGT, $nameLen, $sizeT->constInt(0, false))
            ),
            $hasNameBb,
            $noNameBb
        );

        $context->builder->positionAtEnd($hasNameBb);
        $allocSize = $context->builder->add($nameLen, $sizeT->constInt(1, false));
        $copy = $context->builder->call($context->lookupFunction('malloc'), $allocSize);
        $copyPtr = $context->builder->pointerCast($copy, $i8p);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $context->bytePtr($copyPtr),
            $context->bytePtr($name),
            $nameLen
        );
        $term = $context->builder->gep($copyPtr, $nameLen);
        $context->builder->store($context->getTypeFromString('int8')->constInt(0, false), $term);
        $context->builder->store($copyPtr, self::nameSlot($context, $i32, $depth));
        $context->builder->branch($nameDoneBb);

        $context->builder->positionAtEnd($noNameBb);
        $context->builder->branch($nameDoneBb);

        $context->builder->positionAtEnd($nameDoneBb);
        $context->builder->store($context->builder->add($depth, $oneI32), self::$depthGlobal);
        $context->builder->returnVoid();
    }

    private static function implementRestoreApply(
        Context $context,
        LlvmFunction $fn,
        $i32,
        $i8p
    ): void {
        $entry = $fn->appendBasicBlock('eh_restore_entry');
        $context->builder->positionAtEnd($entry);

        $zeroI32 = $i32->constInt(0, false);
        $oneI32 = $i32->constInt(1, false);
        $out = $fn->getParam(0);

        $depth = $context->builder->load(self::$depthGlobal);
        $emptyBb = $fn->appendBasicBlock('eh_restore_empty');
        $popBb = $fn->appendBasicBlock('eh_restore_pop');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLE, $depth, $zeroI32),
            $emptyBb,
            $popBb
        );

        $context->builder->positionAtEnd($emptyBb);
        self::writeValueBool($context, $out, $zeroI32);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($popBb);
        $newDepth = $context->builder->sub($depth, $oneI32);
        $context->builder->store($newDepth, self::$depthGlobal);

        $nameSlot = self::nameSlot($context, $i32, $newDepth);
        $storedName = $context->builder->load($nameSlot);
        $freeBb = $fn->appendBasicBlock('eh_restore_free');
        $noFreeBb = $fn->appendBasicBlock('eh_restore_no_free');
        $doneFreeBb = $fn->appendBasicBlock('eh_restore_free_done');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_NE, $storedName, $i8p->constNull()),
            $freeBb,
            $noFreeBb
        );

        $context->builder->positionAtEnd($freeBb);
        $context->builder->call($context->lookupFunction('free'), $storedName);
        $context->builder->branch($doneFreeBb);

        $context->builder->positionAtEnd($noFreeBb);
        $context->builder->branch($doneFreeBb);

        $context->builder->positionAtEnd($doneFreeBb);
        $context->builder->store($i8p->constNull(), self::fnSlot($context, $i32, $newDepth));
        $context->builder->store($zeroI32, self::maskSlot($context, $i32, $newDepth));
        $context->builder->store($i8p->constNull(), $nameSlot);
        self::writeValueBool($context, $out, $oneI32);
        $context->builder->returnVoid();
    }

    private static function fnSlot(Context $context, $i32, Value $index): Value
    {
        return $context->builder->inBoundsGEP(
            self::$fnGlobal,
            $i32->constInt(0, false),
            $index
        );
    }

    private static function maskSlot(Context $context, $i32, Value $index): Value
    {
        return $context->builder->inBoundsGEP(
            self::$maskGlobal,
            $i32->constInt(0, false),
            $index
        );
    }

    private static function nameSlot(Context $context, $i32, Value $index): Value
    {
        return $context->builder->inBoundsGEP(
            self::$nameGlobal,
            $i32->constInt(0, false),
            $index
        );
    }

    private static function ensureGlobals(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');

        if (null === $context->module->getNamedGlobal(self::GLOBAL_DEPTH)) {
            self::$depthGlobal = $context->module->addGlobal($i32, self::GLOBAL_DEPTH);
            self::$depthGlobal->setInitializer($i32->constInt(0, false));
        } else {
            self::$depthGlobal = $context->module->getNamedGlobal(self::GLOBAL_DEPTH);
        }
        if (null === $context->module->getNamedGlobal(self::GLOBAL_FN)) {
            $arr = $i8p->arrayType(self::MAX);
            self::$fnGlobal = $context->module->addGlobal($arr, self::GLOBAL_FN);
            self::$fnGlobal->setInitializer($arr->constNull());
        } else {
            self::$fnGlobal = $context->module->getNamedGlobal(self::GLOBAL_FN);
        }
        if (null === $context->module->getNamedGlobal(self::GLOBAL_MASK)) {
            $arr = $i32->arrayType(self::MAX);
            self::$maskGlobal = $context->module->addGlobal($arr, self::GLOBAL_MASK);
            self::$maskGlobal->setInitializer($arr->constNull());
        } else {
            self::$maskGlobal = $context->module->getNamedGlobal(self::GLOBAL_MASK);
        }
        if (null === $context->module->getNamedGlobal(self::GLOBAL_NAME)) {
            $arr = $i8p->arrayType(self::MAX);
            self::$nameGlobal = $context->module->addGlobal($arr, self::GLOBAL_NAME);
            self::$nameGlobal->setInitializer($arr->constNull());
        } else {
            self::$nameGlobal = $context->module->getNamedGlobal(self::GLOBAL_NAME);
        }
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

    private static function writeValueNull(Context $context, Value $out): void
    {
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            $out
        );
    }

    private static function writeValueBool(Context $context, Value $out, Value $value): void
    {
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            $out,
            $value
        );
    }

    private static function writeValueStringFromCstr(Context $context, Value $out, Value $cstr): void
    {
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $len = $context->builder->call($context->lookupFunction('strlen'), $cstr);
        $str = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->sext($len, $i64),
            $cstr
        );
        $context->builder->call($context->lookupFunction('__value__writeString'), $out, $str);
    }

    private static function ensureLibc(Context $context): void
    {
        $voidPtr = $context->getTypeFromString('void*');
        $voidTy = $context->getTypeFromString('void');
        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');

        self::ensureExternal($context, 'malloc', $context->context->functionType($voidPtr, false, $sizeT));
        self::ensureExternal($context, 'free', $context->context->functionType($voidTy, false, $i8p));
        self::ensureExternal(
            $context,
            'memcpy',
            $context->context->functionType($voidPtr, false, $voidPtr, $voidPtr, $sizeT)
        );
        self::ensureExternal($context, 'strlen', $context->context->functionType($sizeT, false, $i8p));
    }

    private static function ensureValueWriters(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $valPtr = $context->getTypeFromString('__value__*');
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $voidTy = $context->getTypeFromString('void');

        self::ensureExternal(
            $context,
            '__string__init',
            $context->context->functionType($strPtr, false, $i64, $i8p)
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
        foreach (self::RUNTIME_FNS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after ErrorHandlerJitRuntime LLVM implement');
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
