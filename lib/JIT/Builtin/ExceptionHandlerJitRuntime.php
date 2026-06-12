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
 * LLVM exception-handler stack for set_exception_handler() / restore_exception_handler() (#4311, #3146).
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(set_exception_handler)
 */
final class ExceptionHandlerJitRuntime
{
    private const MAX = 32;

    private const GLOBAL_DEPTH = 'phpc_exception_handler_depth';

    private const GLOBAL_FN = 'phpc_exception_handler_fn';

    private const GLOBAL_NAME = 'phpc_exception_handler_name';

    /** @var Value|null */
    private static $depthGlobal = null;

    /** @var Value|null */
    private static $fnGlobal = null;

    /** @var Value|null */
    private static $nameGlobal = null;

    /** @var list<string> */
    private const RUNTIME_FNS = [
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

        $restoreBlock = self::captureInsertBlock($context);
        self::ensureGlobals($context);
        self::ensureLibc($context);
        self::ensureValueWriters($context);

        $i32 = $context->getTypeFromString('int32');
        $objPtr = $context->getTypeFromString('__object__*');
        $valPtr = $context->getTypeFromString('__value__*');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $voidTy = $context->getTypeFromString('void');
        $cbFnTy = $context->context->functionType($i32, false, $objPtr);
        $cbPtrTy = $cbFnTy->pointerType(0);

        $dispatchProbe = $context->module->getNamedFunction('__phpc_exception_handler_dispatch');
        $ftDispatch = $context->context->functionType($i32, false, $objPtr);
        $fnDispatch = null !== $dispatchProbe
            ? $dispatchProbe
            : $context->module->addFunction('__phpc_exception_handler_dispatch', $ftDispatch);
        self::implementDispatch($context, $fnDispatch, $i32, $objPtr, $cbFnTy, $cbPtrTy);

        $setProbe = $context->module->getNamedFunction('__phpc_exception_handler_set_apply');
        $ftSet = $context->context->functionType($voidTy, false, $valPtr, $i8p, $sizeT, $i8p);
        $fnSet = null !== $setProbe
            ? $setProbe
            : $context->module->addFunction('__phpc_exception_handler_set_apply', $ftSet);
        self::implementSetApply($context, $fnSet, $i32, $i8p, $sizeT);

        $restoreProbe = $context->module->getNamedFunction('__phpc_exception_handler_restore_apply');
        $ftRestore = $context->context->functionType($voidTy, false, $valPtr);
        $fnRestore = null !== $restoreProbe
            ? $restoreProbe
            : $context->module->addFunction('__phpc_exception_handler_restore_apply', $ftRestore);
        self::implementRestoreApply($context, $fnRestore, $i32, $i8p);

        self::registerLinkedRuntime($context);
        self::restoreInsertBlock($context, $restoreBlock);
    }

    private static function implementDispatch(
        Context $context,
        LlvmFunction $fn,
        $i32,
        $objPtr,
        $cbFnTy,
        $cbPtrTy
    ): void {
        $entry = $fn->appendBasicBlock('xh_dispatch_entry');
        $context->builder->positionAtEnd($entry);

        $zeroI32 = $i32->constInt(0, false);
        $oneI32 = $i32->constInt(1, false);
        $exception = $fn->getParam(0);

        $depth = $context->builder->load(self::$depthGlobal);
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
        $handlerFn = $context->builder->load(self::fnSlot($context, $i32, $idx));
        $noFnBb = $fn->appendBasicBlock('xh_dispatch_no_fn');
        $callBb = $fn->appendBasicBlock('xh_dispatch_call');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $handlerFn, $context->getTypeFromString('int8*')->constNull()),
            $noFnBb,
            $callBb
        );

        $context->builder->positionAtEnd($noFnBb);
        $context->builder->branch($loopIncBb);

        $context->builder->positionAtEnd($callBb);
        $cb = $context->builder->pointerCast($handlerFn, $cbPtrTy);
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
        $context->builder->clearInsertionPosition();
    }

    private static function implementSetApply(
        Context $context,
        LlvmFunction $fn,
        $i32,
        $i8p,
        $sizeT
    ): void {
        $entry = $fn->appendBasicBlock('xh_set_entry');
        $context->builder->positionAtEnd($entry);

        $zeroI32 = $i32->constInt(0, false);
        $oneI32 = $i32->constInt(1, false);
        $out = $fn->getParam(0);
        $name = $fn->getParam(1);
        $nameLen = $fn->getParam(2);
        $fnOpaque = $fn->getParam(3);

        $popBb = $fn->appendBasicBlock('xh_set_pop');
        $pushBb = $fn->appendBasicBlock('xh_set_push');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $fnOpaque, $i8p->constNull()),
            $popBb,
            $pushBb
        );

        $context->builder->positionAtEnd($popBb);
        $depth = $context->builder->load(self::$depthGlobal);
        $emptyPopBb = $fn->appendBasicBlock('xh_set_pop_empty');
        $doPopBb = $fn->appendBasicBlock('xh_set_pop_do');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLE, $depth, $zeroI32),
            $emptyPopBb,
            $doPopBb
        );

        $context->builder->positionAtEnd($emptyPopBb);
        self::writeValueNull($context, $out);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($doPopBb);
        $removedIdx = $context->builder->sub($depth, $oneI32);
        self::writeHandlerNameAtToOut($context, $fn, $out, $i32, $i8p, $removedIdx);
        self::freeNameAt($context, $fn, $i32, $i8p, $removedIdx);
        $context->builder->store($i8p->constNull(), self::fnSlot($context, $i32, $removedIdx));
        $context->builder->store($i8p->constNull(), self::nameSlot($context, $i32, $removedIdx));
        $context->builder->store($removedIdx, self::$depthGlobal);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($pushBb);
        self::writePreviousHandlerToOut($context, $fn, $out, $i32, $i8p);
        $depth = $context->builder->load(self::$depthGlobal);
        $fullBb = $fn->appendBasicBlock('xh_set_full');
        $doPushBb = $fn->appendBasicBlock('xh_set_do_push');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SGE, $depth, $i32->constInt(self::MAX, false)),
            $fullBb,
            $doPushBb
        );

        $context->builder->positionAtEnd($fullBb);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($doPushBb);
        $context->builder->store($fnOpaque, self::fnSlot($context, $i32, $depth));
        self::storeCopiedName($context, $fn, $i32, $i8p, $sizeT, $depth, $name, $nameLen);
        $context->builder->store($context->builder->add($depth, $oneI32), self::$depthGlobal);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function implementRestoreApply(
        Context $context,
        LlvmFunction $fn,
        $i32,
        $i8p
    ): void {
        $entry = $fn->appendBasicBlock('xh_restore_entry');
        $context->builder->positionAtEnd($entry);

        $zeroI32 = $i32->constInt(0, false);
        $oneI32 = $i32->constInt(1, false);
        $out = $fn->getParam(0);

        $depth = $context->builder->load(self::$depthGlobal);
        $emptyBb = $fn->appendBasicBlock('xh_restore_empty');
        $popBb = $fn->appendBasicBlock('xh_restore_pop');
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
        self::freeNameAt($context, $fn, $i32, $i8p, $newDepth);
        $context->builder->store($i8p->constNull(), self::fnSlot($context, $i32, $newDepth));
        $context->builder->store($i8p->constNull(), self::nameSlot($context, $i32, $newDepth));
        $context->builder->store($newDepth, self::$depthGlobal);
        self::writeValueBool($context, $out, $oneI32);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function writePreviousHandlerToOut(
        Context $context,
        LlvmFunction $fn,
        Value $out,
        $i32,
        $i8p
    ): void {
        $zeroI32 = $i32->constInt(0, false);
        $oneI32 = $i32->constInt(1, false);
        $depth = $context->builder->load(self::$depthGlobal);
        $hasPrevBb = $fn->appendBasicBlock('xh_set_has_prev');
        $noPrevBb = $fn->appendBasicBlock('xh_set_no_prev');
        $prevDoneBb = $fn->appendBasicBlock('xh_set_prev_done');
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
        self::writeHandlerNameAtToOut($context, $fn, $out, $i32, $i8p, $prevIdx);
        $context->builder->branch($prevDoneBb);

        $context->builder->positionAtEnd($prevDoneBb);
    }

    private static function writeHandlerNameAtToOut(
        Context $context,
        LlvmFunction $fn,
        Value $out,
        $i32,
        $i8p,
        Value $index
    ): void {
        $handlerName = $context->builder->load(self::nameSlot($context, $i32, $index));
        $nameNullBb = $fn->appendBasicBlock('xh_name_null');
        $nameStrBb = $fn->appendBasicBlock('xh_name_str');
        $nameDoneBb = $fn->appendBasicBlock('xh_name_done');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $handlerName, $i8p->constNull()),
            $nameNullBb,
            $nameStrBb
        );

        $context->builder->positionAtEnd($nameNullBb);
        self::writeValueNull($context, $out);
        $context->builder->branch($nameDoneBb);

        $context->builder->positionAtEnd($nameStrBb);
        self::writeValueStringFromCstr($context, $out, $handlerName);
        $context->builder->branch($nameDoneBb);

        $context->builder->positionAtEnd($nameDoneBb);
    }

    private static function storeCopiedName(
        Context $context,
        LlvmFunction $fn,
        $i32,
        $i8p,
        $sizeT,
        Value $depth,
        Value $name,
        Value $nameLen
    ): void {
        $hasNameBb = $fn->appendBasicBlock('xh_set_has_name');
        $noNameBb = $fn->appendBasicBlock('xh_set_no_name');
        $nameDoneBb = $fn->appendBasicBlock('xh_set_name_done');
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
        $context->builder->store($i8p->constNull(), self::nameSlot($context, $i32, $depth));
        $context->builder->branch($nameDoneBb);

        $context->builder->positionAtEnd($nameDoneBb);
    }

    private static function freeNameAt(Context $context, LlvmFunction $fn, $i32, $i8p, Value $index): void
    {
        $storedName = $context->builder->load(self::nameSlot($context, $i32, $index));
        $freeBb = $fn->appendBasicBlock('xh_free_name');
        $noFreeBb = $fn->appendBasicBlock('xh_no_free');
        $doneBb = $fn->appendBasicBlock('xh_free_done');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_NE, $storedName, $i8p->constNull()),
            $freeBb,
            $noFreeBb
        );

        $context->builder->positionAtEnd($freeBb);
        $context->builder->call($context->lookupFunction('free'), $storedName);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($noFreeBb);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
    }

    private static function fnSlot(Context $context, $i32, Value $index): Value
    {
        return $context->builder->inBoundsGEP(
            self::$fnGlobal,
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
        $objPtr = $context->getTypeFromString('__object__*');
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
            '__value__writeObject',
            $context->context->functionType($voidTy, false, $valPtr, $objPtr)
        );
        self::ensureExternal(
            $context,
            '__value__readLong',
            $context->context->functionType($i64, false, $valPtr)
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
                throw new \LogicException($name.' missing after ExceptionHandlerJitRuntime LLVM implement');
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
