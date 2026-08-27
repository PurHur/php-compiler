<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StreamGlobalsJit;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * Pure-LLVM stream-filter name registry + apply for thin user-script AOT (#35426).
 *
 * NestedJIT {@see StreamFilterJitHelper::applyReadFilters} touches PHP statics unsafely
 * under thin AOT (SIGSEGV). Identity wipe in {@see JitStreamIoKernel} made filters no-ops.
 *
 * Dual-write: NestedJIT append still returns the filter resource; this registry records
 * filter names per stream handle for libc fread/stream_get_contents apply hooks.
 *
 * Built-ins implemented here: string.toupper / string.tolower / string.rot13.
 * Other filters pass through until a NestedJIT-safe path exists.
 *
 * php-src: ext/standard/streamsfuncs.c / streams.c — filter apply on read/write
 */
final class JitStreamFilterApplyKernel
{
    private const MAX_HANDLES = StreamGlobalsJit::MAX_HANDLES;

    private const MAX_FILTERS = 8;

    private const GLOBAL_READ_COUNT = 'phpc_stream_read_filter_count';

    private const GLOBAL_READ_FILTERS = 'phpc_stream_read_filters';

    private const GLOBAL_WRITE_COUNT = 'phpc_stream_write_filter_count';

    private const GLOBAL_WRITE_FILTERS = 'phpc_stream_write_filters';

    public const REGISTRY_APPEND = '__phpc_stream_filter_registry_append';

    public static function ensureRegistryAppend(Context $context): void
    {
        self::ensureGlobals($context);
        self::ensureExternals($context);
        self::implementRegistryAppend($context);
    }

    /**
     * Replace NestedJIT / identity apply bodies with pure-LLVM string-filter apply (#35426).
     */
    public static function implementPureLlvmApply(Context $context): void
    {
        $savedBlock = BasicBlockHelper::tryGetInsertBlock($context);
        $context->builder->clearInsertionPosition();

        self::ensureRegistryAppend($context);
        self::forceApply($context, '__compiler_stream_filter_apply_read', self::GLOBAL_READ_COUNT, self::GLOBAL_READ_FILTERS);
        self::forceApply($context, '__compiler_stream_filter_apply_write', self::GLOBAL_WRITE_COUNT, self::GLOBAL_WRITE_FILTERS);

        if (null !== $savedBlock) {
            BasicBlockHelper::restoreInsertBlock($context, $savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function ensureGlobals(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $strPtr = $context->getTypeFromString('__string__*');
        $countTy = $i32->arrayType(self::MAX_HANDLES);
        $filtersTy = $strPtr->arrayType(self::MAX_FILTERS)->arrayType(self::MAX_HANDLES);

        foreach ([
            self::GLOBAL_READ_COUNT => $countTy,
            self::GLOBAL_WRITE_COUNT => $countTy,
            self::GLOBAL_READ_FILTERS => $filtersTy,
            self::GLOBAL_WRITE_FILTERS => $filtersTy,
        ] as $name => $ty) {
            $global = $context->module->getNamedGlobal($name);
            if (null === $global) {
                $global = $context->module->addGlobal($ty, $name);
            }
            $global->setInitializer($ty->constNull());
        }
    }

    private static function ensureExternals(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');

        LibcExtern::ensureExternalDecl(
            $context,
            'strcmp',
            $context->context->functionType($i32, false, $i8p, $i8p)
        );
        LibcExtern::ensureExternalDecl(
            $context,
            '__string__separate',
            $context->context->functionType($strPtr, false, $strPtr)
        );
    }

    private static function implementRegistryAppend(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::REGISTRY_APPEND);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::REGISTRY_APPEND, $probe);

            return;
        }

        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $void = $context->getTypeFromString('void');
        $ft = $context->context->functionType($void, false, $i64, $strPtr, $i64);
        $fn = null !== $probe ? $probe : $context->module->addFunction(self::REGISTRY_APPEND, $ft);
        $context->registerFunction(self::REGISTRY_APPEND, $fn);

        $entry = $fn->appendBasicBlock('sf_reg_append_entry');
        $context->builder->positionAtEnd($entry);

        $handle = $fn->getParam(0);
        $name = $fn->getParam(1);
        $readWrite = $fn->getParam(2);

        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $name);

        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $two = $i64->constInt(2, false);
        $max = $i64->constInt(self::MAX_HANDLES, false);

        $done = $fn->appendBasicBlock('sf_reg_append_done');
        $inRangeBb = $fn->appendBasicBlock('sf_reg_append_in_range');
        $handleOk = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $handle, $zero),
            $context->builder->icmp(Builder::INT_SLT, $handle, $max)
        );
        $context->builder->branchIf($handleOk, $inRangeBb, $done);

        $context->builder->positionAtEnd($inRangeBb);
        // readWrite==0 (STREAM_FILTER_ALL) or READ bit → read chain; same for WRITE.
        $readBit = $context->builder->and($readWrite, $one);
        $writeBit = $context->builder->and($readWrite, $two);
        $isAll = $context->builder->icmp(Builder::INT_EQ, $readWrite, $zero);
        $doRead = $context->builder->or($isAll, $context->builder->icmp(Builder::INT_NE, $readBit, $zero));
        $doWrite = $context->builder->or($isAll, $context->builder->icmp(Builder::INT_NE, $writeBit, $zero));

        $afterRead = $fn->appendBasicBlock('sf_reg_append_after_read');
        $readBb = $fn->appendBasicBlock('sf_reg_append_read');
        $context->builder->branchIf($doRead, $readBb, $afterRead);

        $context->builder->positionAtEnd($readBb);
        self::emitPushFilter($context, $fn, $handle, $owned, self::GLOBAL_READ_COUNT, self::GLOBAL_READ_FILTERS);
        $context->builder->branch($afterRead);

        $context->builder->positionAtEnd($afterRead);
        $afterWrite = $fn->appendBasicBlock('sf_reg_append_after_write');
        $writeBb = $fn->appendBasicBlock('sf_reg_append_write');
        $context->builder->branchIf($doWrite, $writeBb, $afterWrite);

        $context->builder->positionAtEnd($writeBb);
        self::emitPushFilter($context, $fn, $handle, $owned, self::GLOBAL_WRITE_COUNT, self::GLOBAL_WRITE_FILTERS);
        $context->builder->branch($afterWrite);

        $context->builder->positionAtEnd($afterWrite);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function emitPushFilter(
        Context $context,
        LlvmFunction $fn,
        Value $handle,
        Value $ownedName,
        string $countGlobal,
        string $filtersGlobal
    ): void {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $zero = $i64->constInt(0, false);
        $oneI32 = $i32->constInt(1, false);
        $maxFilters = $i32->constInt(self::MAX_FILTERS, false);

        $countG = $context->module->getNamedGlobal($countGlobal);
        $filtersG = $context->module->getNamedGlobal($filtersGlobal);
        if (null === $countG || null === $filtersG) {
            throw new \LogicException('JitStreamFilterApplyKernel: filter globals missing (#35426)');
        }

        $countSlot = $context->builder->bitcast(
            $context->builder->gep($countG, $zero, $handle),
            $i32->pointerType(0)
        );
        $count = $context->builder->load($countSlot);
        $fullBb = $fn->appendBasicBlock('sf_reg_push_full');
        $storeBb = $fn->appendBasicBlock('sf_reg_push_store');
        $isFull = $context->builder->icmp(Builder::INT_SGE, $count, $maxFilters);
        $context->builder->branchIf($isFull, $fullBb, $storeBb);

        $context->builder->positionAtEnd($storeBb);
        $idx = $context->builder->zExt($count, $i64);
        $slot = $context->builder->bitcast(
            $context->builder->gep($filtersG, $zero, $handle, $idx),
            $strPtr->pointerType(0)
        );
        $context->builder->store($ownedName, $slot);
        $context->builder->store($context->builder->add($count, $oneI32), $countSlot);
        $context->builder->branch($fullBb);

        $context->builder->positionAtEnd($fullBb);
    }

    private static function forceApply(
        Context $context,
        string $abiName,
        string $countGlobal,
        string $filtersGlobal
    ): void {
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType($strPtr, false, $i64, $strPtr);
        $fn = $context->module->getNamedFunction($abiName);
        if (null === $fn) {
            $fn = $context->module->addFunction($abiName, $ft);
        }
        if ($fn->countBasicBlocks() > 0) {
            foreach (array_reverse($fn->getBasicBlocks()) as $block) {
                $block->delete();
            }
        }

        // BasicBlockHelper::append prefers loweringLlvmFunction (app main) — scope
        // so lcfirst::transformAllAscii / rot13 blocks land in this ABI (#35426 / peer #27211).
        BasicBlockHelper::scopeLoweringToFunction($context, $fn, $abiName, static function () use (
            $context,
            $fn,
            $abiName,
            $countGlobal,
            $filtersGlobal
        ): void {
            self::emitForceApplyBody($context, $fn, $abiName, $countGlobal, $filtersGlobal);
        });
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function emitForceApplyBody(
        Context $context,
        LlvmFunction $fn,
        string $abiName,
        string $countGlobal,
        string $filtersGlobal
    ): void {
        $strPtr = $context->getTypeFromString('__string__*');
        $entry = $fn->appendBasicBlock($abiName.'_pure_entry');
        $context->builder->positionAtEnd($entry);

        $handle = $fn->getParam(0);
        $dataIn = $fn->getParam(1);
        $dataSlot = $context->builder->alloca($strPtr, 1, 'sf_apply_data');
        $context->builder->store($dataIn, $dataSlot);

        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $max = $i64->constInt(self::MAX_HANDLES, false);
        $retBb = $fn->appendBasicBlock($abiName.'_pure_ret');
        $loopSetup = $fn->appendBasicBlock($abiName.'_pure_setup');

        $handleOk = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $handle, $zero),
            $context->builder->icmp(Builder::INT_SLT, $handle, $max)
        );
        $context->builder->branchIf($handleOk, $loopSetup, $retBb);

        $context->builder->positionAtEnd($loopSetup);
        $i32 = $context->getTypeFromString('int32');
        $countG = $context->module->getNamedGlobal($countGlobal);
        $filtersG = $context->module->getNamedGlobal($filtersGlobal);
        if (null === $countG || null === $filtersG) {
            throw new \LogicException('JitStreamFilterApplyKernel: apply globals missing (#35426)');
        }
        $count = $context->builder->load(
            $context->builder->bitcast(
                $context->builder->gep($countG, $zero, $handle),
                $i32->pointerType(0)
            )
        );
        $iSlot = $context->builder->alloca($i64, 1, 'sf_apply_i');
        $context->builder->store($zero, $iSlot);

        $loopHead = $fn->appendBasicBlock($abiName.'_pure_head');
        $loopBody = $fn->appendBasicBlock($abiName.'_pure_body');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $i32i = $context->builder->trunc($i, $i32);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i32i, $count);
        $context->builder->branchIf($atEnd, $retBb, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $namePtr = $context->builder->load(
            $context->builder->bitcast(
                $context->builder->gep($filtersG, $zero, $handle, $i),
                $strPtr->pointerType(0)
            )
        );
        $nullName = $strPtr->constNull();
        $nameNullBb = $fn->appendBasicBlock($abiName.'_pure_name_null');
        $nameOkBb = $fn->appendBasicBlock($abiName.'_pure_name_ok');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $namePtr, $nullName);
        $context->builder->branchIf($isNull, $nameNullBb, $nameOkBb);

        $context->builder->positionAtEnd($nameNullBb);
        $context->builder->store($context->builder->add($i, $one), $iSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($nameOkBb);
        $cur = $context->builder->load($dataSlot);
        $transformed = self::emitApplyNamedFilter($context, $fn, $namePtr, $cur, $abiName);
        $context->builder->store($transformed, $dataSlot);
        $context->builder->store($context->builder->add($i, $one), $iSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($retBb);
        $context->builder->returnValue($context->builder->load($dataSlot));
    }

    private static function emitApplyNamedFilter(
        Context $context,
        LlvmFunction $fn,
        Value $namePtr,
        Value $data,
        string $prefix
    ): Value {
        $i32 = $context->getTypeFromString('int32');
        $strPtr = $context->getTypeFromString('__string__*');
        $zero = $i32->constInt(0, false);
        $nameCstr = self::stringData($context, $namePtr);

        $resultSlot = $context->builder->alloca($strPtr, 1, $prefix.'_xf_result');
        $context->builder->store($data, $resultSlot);

        $after = $fn->appendBasicBlock($prefix.'_xf_after');
        $toupperBb = $fn->appendBasicBlock($prefix.'_xf_toupper');
        $tolowerBb = $fn->appendBasicBlock($prefix.'_xf_tolower');
        $rot13Bb = $fn->appendBasicBlock($prefix.'_xf_rot13');
        $checkLower = $fn->appendBasicBlock($prefix.'_xf_check_lower');
        $checkRot = $fn->appendBasicBlock($prefix.'_xf_check_rot');

        $cmpUpper = $context->builder->call(
            $context->lookupFunction('strcmp'),
            $nameCstr,
            self::literalCstr($context, 'string.toupper')
        );
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $cmpUpper, $zero),
            $toupperBb,
            $checkLower
        );

        $context->builder->positionAtEnd($toupperBb);
        $up = $context->builder->call($context->lookupFunction('__string__separate'), $data);
        lcfirst::transformAllAscii($context, $up, \ord('a'), \ord('z'), -32);
        $context->builder->store($up, $resultSlot);
        $context->builder->branch($after);

        $context->builder->positionAtEnd($checkLower);
        $cmpLower = $context->builder->call(
            $context->lookupFunction('strcmp'),
            $nameCstr,
            self::literalCstr($context, 'string.tolower')
        );
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $cmpLower, $zero),
            $tolowerBb,
            $checkRot
        );

        $context->builder->positionAtEnd($tolowerBb);
        $lo = $context->builder->call($context->lookupFunction('__string__separate'), $data);
        lcfirst::transformAllAscii($context, $lo, \ord('A'), \ord('Z'), 32);
        $context->builder->store($lo, $resultSlot);
        $context->builder->branch($after);

        $context->builder->positionAtEnd($checkRot);
        $cmpRot = $context->builder->call(
            $context->lookupFunction('strcmp'),
            $nameCstr,
            self::literalCstr($context, 'string.rot13')
        );
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $cmpRot, $zero),
            $rot13Bb,
            $after
        );

        $context->builder->positionAtEnd($rot13Bb);
        $rot = $context->builder->call($context->lookupFunction('__string__separate'), $data);
        self::transformRot13($context, $fn, $rot);
        $context->builder->store($rot, $resultSlot);
        $context->builder->branch($after);

        $context->builder->positionAtEnd($after);

        return $context->builder->load($resultSlot);
    }

    /** ASCII rot13 in place — peer {@see VmString::strRot13} (no NestedJIT StrRot13). */
    private static function transformRot13(Context $context, LlvmFunction $fn, Value $strPtr): void
    {
        $structName = $strPtr->typeOf()->getElementType()->getName();
        $map = $context->structFieldMap[$structName];
        $len = $context->builder->load($context->builder->structGep($strPtr, $map['length']));
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $charPtr = $context->builder->structGep($strPtr, $map['value']);
        $iSlot = $context->builder->alloca($i64, 1, 'rot13_i');
        $context->builder->store($zero, $iSlot);

        $done = $fn->appendBasicBlock('rot13_done');
        $loopHead = $fn->appendBasicBlock('rot13_head');
        $loopBody = $fn->appendBasicBlock('rot13_body');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i, $len);
        $context->builder->branchIf($atEnd, $done, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $atChar = $context->builder->gep($charPtr, $i);
        $ch = $context->builder->load($atChar);
        $chI32 = $context->builder->zExt($ch, $i32);

        $cA = $i32->constInt(65, false);
        $cM = $i32->constInt(77, false);
        $cN = $i32->constInt(78, false);
        $cZ = $i32->constInt(90, false);
        $ca = $i32->constInt(97, false);
        $cm = $i32->constInt(109, false);
        $cn = $i32->constInt(110, false);
        $cz = $i32->constInt(122, false);
        $thirteen = $i32->constInt(13, false);

        $inAM = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $chI32, $cA),
            $context->builder->icmp(Builder::INT_SLE, $chI32, $cM)
        );
        $inam = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $chI32, $ca),
            $context->builder->icmp(Builder::INT_SLE, $chI32, $cm)
        );
        $plus13 = $context->builder->or($inAM, $inam);

        $inNZ = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $chI32, $cN),
            $context->builder->icmp(Builder::INT_SLE, $chI32, $cZ)
        );
        $innz = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $chI32, $cn),
            $context->builder->icmp(Builder::INT_SLE, $chI32, $cz)
        );
        $minus13 = $context->builder->or($inNZ, $innz);

        $add13 = $context->builder->add($chI32, $thirteen);
        $sub13 = $context->builder->sub($chI32, $thirteen);
        $afterPlus = $context->builder->select($plus13, $add13, $chI32);
        $afterMinus = $context->builder->select($minus13, $sub13, $afterPlus);
        $newCh = $context->builder->truncOrBitCast($afterMinus, $ch->typeOf());
        $context->builder->store($newCh, $atChar);
        $context->builder->store($context->builder->add($i, $one), $iSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($done);
    }

    private static function stringData(Context $context, Value $str): Value
    {
        $map = $context->structFieldMap['__string__'];

        return $context->builder->pointerCast(
            $context->builder->structGep($str, $map['value']),
            $context->getTypeFromString('int8*')
        );
    }

    private static function literalCstr(Context $context, string $text): Value
    {
        $i8p = $context->getTypeFromString('int8*');

        return $context->builder->pointerCast($context->constantFromString($text), $i8p);
    }
}
