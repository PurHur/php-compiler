<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\FsGlobVecRuntime;
use PHPCompiler\JIT\Builtin\StringFsGlobVecJit;
use PHPCompiler\JIT\Builtin\StringTriggerErrorJit;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for glob() and scandir() (#5459/#11515/#12909/#27235/#27236/#29986).
 *
 * Embed + thin standalone AOT: NestedJIT {@see FsGlobJitHelper} (`?array` ABI — #20652).
 * NestedJIT leaf (helper `@\glob`/`@\scandir`): libc vec collectors via {@see JitFsGlobKernel}
 * (tempnam #29940 shape — no always-on thin-AOT kernel fork).
 */
final class JitFsGlob
{
    private static int $seq = 0;

    public static function glob(Context $context, Value $patternStr, Value $flagsI32): Value
    {
        StringFsGlobVecJit::implement($context);
        if (NestedJitCompileScope::isActive()) {
            return self::collectList($context, '__phpc_glob_vec', $patternStr, $flagsI32, 'glob');
        }

        return self::collectFromHelper(
            $context,
            FsGlobVecRuntime::GLOB_HELPER,
            $patternStr,
            $flagsI32,
            'glob'
        );
    }

    public static function scandir(Context $context, Value $pathStr, Value $sortI32): Value
    {
        StringTriggerErrorJit::implement($context);
        StringFsGlobVecJit::implement($context);
        if (NestedJitCompileScope::isActive()) {
            return self::collectList($context, '__phpc_scandir_vec', $pathStr, $sortI32, 'scandir');
        }

        return self::collectFromHelper(
            $context,
            FsGlobVecRuntime::SCANDIR_HELPER,
            $pathStr,
            $sortI32,
            'scandir'
        );
    }

    private static function collectFromHelper(
        Context $context,
        string $helperLogical,
        Value $argStr,
        Value $argI32,
        string $id
    ): Value {
        FsGlobVecRuntime::ensureLinked($context);
        $tag = $id.(string) ++self::$seq;
        $htRaw = JitNestedHelperCoerce::callHelper(
            $context,
            FsGlobVecRuntime::helperFunction($context, $helperLogical),
            [$argStr, $argI32]
        );
        $failed = JitNestedHelperCoerce::isHelperResultNull($context, $htRaw);
        $failBlock = BasicBlockHelper::append($context, $tag.'_helper_fail');
        $okBlock = BasicBlockHelper::append($context, $tag.'_helper_ok');
        $doneBlock = BasicBlockHelper::append($context, $tag.'_helper_done');
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        self::emitScandirFailureWarnings($context, $argStr, $id);
        $falseSlot = JitValueBox::alloc($context);
        $falsePtr = JitValueBox::pointer($context, $falseSlot);
        JitValueBox::writeBool($context, $falseSlot, $context->getTypeFromString('int1')->constInt(0, false));
        $failTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $ht = JitNestedHelperCoerce::coerceToHashtablePtr($context, $htRaw);
        $okSlot = JitValueBox::alloc($context);
        $okPtr = JitValueBox::pointer($context, $okSlot);
        $context->builder->call($context->lookupFunction('__value__writeHashtable'), $okPtr, $ht);
        $okTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $valuePtrTy = $context->getTypeFromString('__value__*');
        $result = $context->builder->phi($valuePtrTy);
        $result->addIncoming($falsePtr, $failTail);
        $result->addIncoming($okPtr, $okTail);

        return $result;
    }

    private static function collectList(Context $context, string $collectFn, Value $argStr, Value $argI32, string $id): Value
    {
        $tag = $id.(string) ++self::$seq;
        $i8pp = $context->getTypeFromString('int8**');
        $itemsSlot = BasicBlockHelper::entryAlloca($context, $i8pp);
        $context->builder->store($i8pp->constNull(), $itemsSlot);
        $count = $context->builder->call($context->lookupFunction($collectFn), $argStr, $argI32, $itemsSlot);
        $i32 = $context->getTypeFromString('int32');
        $failed = $context->builder->icmp(Builder::INT_SLT, $count, $i32->constInt(0, false));
        $failBlock = BasicBlockHelper::append($context, $tag.'_fail');
        $buildBlock = BasicBlockHelper::append($context, $tag.'_build');
        $doneBlock = BasicBlockHelper::append($context, $tag.'_done');
        $context->builder->branchIf($failed, $failBlock, $buildBlock);
        $context->builder->positionAtEnd($failBlock);
        self::emitScandirFailureWarnings($context, $argStr, $id);
        $falseSlot = JitValueBox::alloc($context);
        $falsePtr = JitValueBox::pointer($context, $falseSlot);
        JitValueBox::writeBool($context, $falseSlot, $context->getTypeFromString('int1')->constInt(0, false));
        $failTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);
        $context->builder->positionAtEnd($buildBlock);
        $ht = self::buildHashtableFromItems($context, $itemsSlot, $count, $tag);
        $context->builder->call($context->lookupFunction('__phpc_strvec_free'), $context->builder->load($itemsSlot), $count);
        $okSlot = JitValueBox::alloc($context);
        $okPtr = JitValueBox::pointer($context, $okSlot);
        $context->builder->call($context->lookupFunction('__value__writeHashtable'), $okPtr, $ht);
        $okTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);
        $context->builder->positionAtEnd($doneBlock);
        $valuePtrTy = $context->getTypeFromString('__value__*');
        $result = $context->builder->phi($valuePtrTy);
        $result->addIncoming($falsePtr, $failTail);
        $result->addIncoming($okPtr, $okTail);

        return $result;
    }

    private static function buildHashtableFromItems(Context $context, Value $itemsSlot, Value $count, string $tag): Value
    {
        $ht = HashTableHelper::alloc($context);
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $iSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($sizeT->constInt(0, false), $iSlot);
        $setString = $context->lookupFunction('__hashtable__setStringAt');
        $stringInit = $context->lookupFunction('__string__init');
        $strlenFn = $context->lookupFunction('strlen');
        $loopHead = BasicBlockHelper::append($context, $tag.'_head');
        $loopBody = BasicBlockHelper::append($context, $tag.'_body');
        $loopDone = BasicBlockHelper::append($context, $tag.'_ht_done');
        $context->builder->branch($loopHead);
        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $countSized = $context->builder->zExt($count, $sizeT);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i, $countSized);
        $context->builder->branchIf($atEnd, $loopDone, $loopBody);
        $context->builder->positionAtEnd($loopBody);
        $items = $context->builder->load($itemsSlot);
        $cstr = $context->builder->load($context->builder->inBoundsGep($items, $i));
        $len = $context->builder->call($strlenFn, $cstr);
        $lenI64 = $len->typeOf() === $i64 ? $len : $context->builder->zExt($len, $i64);
        $cstrCast = $context->builder->pointerCast($cstr, $i8p);
        $str = $context->builder->call($stringInit, $lenI64, $cstrCast);
        $context->builder->call($setString, $ht, $i, $str);
        $context->builder->store($context->builder->addNoSignedWrap($i, $sizeT->constInt(1, false)), $iSlot);
        $context->builder->branch($loopHead);
        $context->builder->positionAtEnd($loopDone);
        BasicBlockHelper::branchToFreshContinue($context, $tag.'_continue');

        return $ht;
    }

    private static function emitScandirFailureWarnings(Context $context, Value $pathStr, string $id): void
    {
        if ('scandir' !== $id) {
            return;
        }
        JitBuiltinWarning::emitPathOpenFailed($context, $pathStr, 'scandir');
        JitBuiltinWarning::emit($context, 'scandir(): (errno 2): No such file or directory');
    }
}
