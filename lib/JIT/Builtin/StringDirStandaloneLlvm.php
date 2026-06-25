<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM directory handle runtime for standalone AOT (#5494, #11811).
 *
 * Embed JIT uses {@see StringDirRuntime} + {@see DirHandleJitHelper} PHP instead.
 * php-src: ext/standard/dir.c — opendir/readdir/closedir/rewinddir
 */
final class StringDirStandaloneLlvm
{
    private const MAX_DIR_HANDLES = 256;

    private const DIR_HANDLE_BASE = 0x10000000;

    /** Linux glibc x86_64 struct dirent::d_name offset. */
    private const DIRENT_D_NAME_OFFSET = 19;

    private const G_ENTRIES = 'phpc_dir_entries';

    private const G_COUNT = 'phpc_dir_count';

    private const G_POS = 'phpc_dir_pos';

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_is_dir_resource',
        '__compiler_opendir',
        '__compiler_readdir',
        '__compiler_closedir',
        '__compiler_rewinddir',
    ];

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_opendir');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureGlobals($context);
        self::ensureLibc($context);
        self::ensureStringInit($context);

        self::implementIfMissing($context, '__phpc_dir_free_slot', self::emitFreeSlot(...));
        self::implementIfMissing($context, '__compiler_is_dir_resource', self::emitIsDirResource(...));
        self::implementIfMissing($context, '__compiler_opendir', self::emitOpendir(...));
        self::implementIfMissing($context, '__compiler_readdir', self::emitReaddir(...));
        self::implementIfMissing($context, '__compiler_closedir', self::emitClosedir(...));
        self::implementIfMissing($context, '__compiler_rewinddir', self::emitRewinddir(...));
    }

    /**
     * @param callable(Context, LlvmFunction): void $emit
     */
    private static function implementIfMissing(Context $context, string $name, callable $emit): void
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }

        $fn = self::declareFunction($context, $name);
        $emit($context, $fn);
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function declareFunction(Context $context, string $name): LlvmFunction
    {
        try {
            return $context->lookupFunction($name);
        } catch (\Throwable) {
            // fall through
        }

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $voidTy = $context->getTypeFromString('void');

        $fn = match ($name) {
            '__phpc_dir_free_slot' => $context->module->addFunction(
                $name,
                $context->context->functionType($voidTy, false, $i64)
            ),
            '__compiler_is_dir_resource' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $i64)
            ),
            '__compiler_opendir' => $context->module->addFunction(
                $name,
                $context->context->functionType($i64, false, $strPtr)
            ),
            '__compiler_readdir' => $context->module->addFunction(
                $name,
                $context->context->functionType($strPtr, false, $i64)
            ),
            '__compiler_closedir', '__compiler_rewinddir' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $i64)
            ),
            default => throw new \LogicException('Unknown dir JIT helper: '.$name),
        };
        $context->registerFunction($name, $fn);

        return $fn;
    }

    private static function ensureGlobals(Context $context): void
    {
        $strPtrPtr = $context->getTypeFromString('__string__**');
        $i32 = $context->getTypeFromString('int32');
        $entriesArray = $strPtrPtr->arrayType(self::MAX_DIR_HANDLES);
        $i32Array = $i32->arrayType(self::MAX_DIR_HANDLES);
        $zero = $i32->constInt(0, false);

        if (null === $context->module->getNamedGlobal(self::G_ENTRIES)) {
            $g = $context->module->addGlobal($entriesArray, self::G_ENTRIES);
            $g->setInitializer($entriesArray->constNull());
        }
        if (null === $context->module->getNamedGlobal(self::G_COUNT)) {
            $g = $context->module->addGlobal($i32Array, self::G_COUNT);
            $g->setInitializer($i32Array->constNull());
        }
        if (null === $context->module->getNamedGlobal(self::G_POS)) {
            $g = $context->module->addGlobal($i32Array, self::G_POS);
            $g->setInitializer($i32Array->constNull());
        }

        // Touch zero for initializer path when globals already exist.
        $zero->typeOf();
    }

    private static function ensureLibc(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $i8pp = $context->getTypeFromString('int8**');
        $i8ppp = $i8pp->pointerType(0);

        // scandir/alphasort only — free/calloc/strlen come from LibcExtern (i8*).
        foreach ([
            ['scandir', $i32, [$i8p, $i8ppp, $i8p, $i8p]],
            ['alphasort', $i32, [$i8pp, $i8pp]],
        ] as [$name, $ret, $params]) {
            self::ensureExternal($context, $name, $context->context->functionType($ret, false, ...$params));
        }
    }

    private static function ensureStringInit(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        self::ensureExternal(
            $context,
            '__string__init',
            $context->context->functionType($strPtr, false, $i64, $i8p)
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

    private static function entriesGlobal(Context $context): Value
    {
        $g = $context->module->getNamedGlobal(self::G_ENTRIES);
        if (null === $g) {
            throw new \LogicException(self::G_ENTRIES.' global missing');
        }

        return $g;
    }

    private static function countGlobal(Context $context): Value
    {
        $g = $context->module->getNamedGlobal(self::G_COUNT);
        if (null === $g) {
            throw new \LogicException(self::G_COUNT.' global missing');
        }

        return $g;
    }

    private static function posGlobal(Context $context): Value
    {
        $g = $context->module->getNamedGlobal(self::G_POS);
        if (null === $g) {
            throw new \LogicException(self::G_POS.' global missing');
        }

        return $g;
    }

    private static function slotFromHandle(Context $context, Value $handle): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $base = $i64->constInt(self::DIR_HANDLE_BASE, false);
        $negOne = $i64->constInt(-1, false);

        return $context->builder->select(
            $context->builder->icmp(Builder::INT_SLT, $handle, $base),
            $negOne,
            $context->builder->subNoSignedWrap($handle, $base)
        );
    }

    private static function loadEntriesAtSlot(Context $context, Value $slot): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $slotIdx = $context->builder->truncOrBitCast($slot, $i32);

        return $context->builder->load(
            $context->builder->inBoundsGEP(
                self::entriesGlobal($context),
                $i32->constInt(0, false),
                $slotIdx
            )
        );
    }

    private static function stringData(Context $context, Value $str): Value
    {
        $map = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->inBoundsGEP(
            $context->builder->structGep($str, $map['value']),
            $i64->constInt(0, false)
        );
    }

    private static function emitFreeSlot(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $strPtr = $context->getTypeFromString('__string__*');
        $strPtrPtr = $context->getTypeFromString('__string__**');
        $voidPtr = $context->getTypeFromString('void*');
        $voidTy = $context->getTypeFromString('void');
        $max = $i64->constInt(self::MAX_DIR_HANDLES, false);
        $zero = $i32->constInt(0, false);
        $slotIn = $fn->getParam(0);

        $badBlock = $fn->appendBasicBlock('bad_slot');
        $bodyBlock = $fn->appendBasicBlock('body');
        $doneBlock = $fn->appendBasicBlock('done');

        $badSlot = $context->builder->or(
            $context->builder->icmp(Builder::INT_SLE, $slotIn, $i64->constInt(0, false)),
            $context->builder->icmp(Builder::INT_SGE, $slotIn, $max)
        );
        $context->builder->branchIf($badSlot, $badBlock, $bodyBlock);

        $context->builder->positionAtEnd($badBlock);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($bodyBlock);
        $slotIdx = $context->builder->truncOrBitCast($slotIn, $i32);
        $entriesPtr = $context->builder->load(
            $context->builder->inBoundsGEP(
                self::entriesGlobal($context),
                $zero,
                $slotIdx
            )
        );
        $nullEntries = $context->builder->icmp(Builder::INT_EQ, $entriesPtr, $strPtrPtr->constNull());
        $skipBlock = $fn->appendBasicBlock('skip');
        $freeBlock = $fn->appendBasicBlock('free_loop');
        $context->builder->branchIf($nullEntries, $skipBlock, $freeBlock);

        $context->builder->positionAtEnd($freeBlock);
        $count = $context->builder->load(
            $context->builder->inBoundsGEP(self::countGlobal($context), $zero, $slotIdx)
        );
        $iSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $context->builder->store($zero, $iSlot);
        $loopHead = $fn->appendBasicBlock('free_head');
        $loopBody = $fn->appendBasicBlock('free_body');
        $context->builder->branch($loopHead);
        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i, $count);
        $context->builder->branchIf($atEnd, $skipBlock, $loopBody);
        $context->builder->positionAtEnd($loopBody);
        $str = $context->builder->load(
            $context->builder->inBoundsGEP($entriesPtr, $context->builder->zExt($i, $sizeT))
        );
        $context->builder->call(
            $context->lookupFunction('free'),
            $context->bytePtr($str)
        );
        $context->builder->store($context->builder->addNoSignedWrap($i, $i32->constInt(1, false)), $iSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($skipBlock);
        $context->builder->call(
            $context->lookupFunction('free'),
            $context->bytePtr($entriesPtr)
        );
        $context->builder->store($strPtrPtr->constNull(), $context->builder->inBoundsGEP(self::entriesGlobal($context), $zero, $slotIdx));
        $context->builder->store($zero, $context->builder->inBoundsGEP(self::countGlobal($context), $zero, $slotIdx));
        $context->builder->store($zero, $context->builder->inBoundsGEP(self::posGlobal($context), $zero, $slotIdx));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $context->builder->returnVoid();
    }

    private static function emitIsDirResource(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $strPtrPtr = $context->getTypeFromString('__string__**');
        $max = $i64->constInt(self::MAX_DIR_HANDLES, false);
        $zero = $i32->constInt(0, false);
        $one = $i32->constInt(1, false);
        $handle = $fn->getParam(0);
        $slot = self::slotFromHandle($context, $handle);

        $badBlock = $fn->appendBasicBlock('bad');
        $checkBlock = $fn->appendBasicBlock('check');
        $badSlot = $context->builder->or(
            $context->builder->icmp(Builder::INT_SLE, $slot, $i64->constInt(0, false)),
            $context->builder->icmp(Builder::INT_SGE, $slot, $max)
        );
        $context->builder->branchIf($badSlot, $badBlock, $checkBlock);

        $context->builder->positionAtEnd($badBlock);
        $context->builder->returnValue($zero);

        $context->builder->positionAtEnd($checkBlock);
        $entries = self::loadEntriesAtSlot($context, $slot);
        $valid = $context->builder->select(
            $context->builder->icmp(Builder::INT_EQ, $entries, $strPtrPtr->constNull()),
            $zero,
            $one
        );
        $context->builder->returnValue($valid);
    }

    private static function emitOpendir(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i8pp = $context->getTypeFromString('int8**');
        $i8ppp = $i8pp->pointerType(0);
        $sizeT = $context->getTypeFromString('size_t');
        $strPtr = $context->getTypeFromString('__string__*');
        $strPtrPtr = $context->getTypeFromString('__string__**');
        $voidPtr = $context->getTypeFromString('void*');
        $negOne = $i64->constInt(-1, false);
        $zero32 = $i32->constInt(0, false);
        $zero64 = $i64->constInt(0, false);
        $one64 = $i64->constInt(1, false);
        $max = $i64->constInt(self::MAX_DIR_HANDLES, false);
        $base = $i64->constInt(self::DIR_HANDLE_BASE, false);
        $path = $fn->getParam(0);

        $nullPathBlock = $fn->appendBasicBlock('null_path');
        $nullPath = $context->builder->icmp(Builder::INT_EQ, $path, $strPtr->constNull());
        $afterPathBlock = $fn->appendBasicBlock('after_path');
        $context->builder->branchIf($nullPath, $nullPathBlock, $afterPathBlock);

        $context->builder->positionAtEnd($nullPathBlock);
        $context->builder->returnValue($negOne);

        $context->builder->positionAtEnd($afterPathBlock);
        $namelistSlot = BasicBlockHelper::entryAlloca($context, $i8pp);
        $context->builder->store($i8pp->constNull(), $namelistSlot);
        $pathData = self::stringData($context, $path);
        $alphasort = $context->lookupFunction('alphasort');
        $n = $context->builder->call(
            $context->lookupFunction('scandir'),
            $pathData,
            $namelistSlot,
            $i8p->constNull(),
            $context->bytePtr($alphasort)
        );
        $scanFailBlock = $fn->appendBasicBlock('scan_fail');
        $scanFail = $context->builder->icmp(Builder::INT_SLT, $n, $zero32);
        $afterScanBlock = $fn->appendBasicBlock('after_scan');
        $context->builder->branchIf($scanFail, $scanFailBlock, $afterScanBlock);

        $context->builder->positionAtEnd($scanFailBlock);
        $context->builder->returnValue($negOne);

        $context->builder->positionAtEnd($afterScanBlock);
        $idSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($one64, $idSlot);
        $slotLoopHead = $fn->appendBasicBlock('slot_head');
        $context->builder->branch($slotLoopHead);
        $context->builder->positionAtEnd($slotLoopHead);
        $id = $context->builder->load($idSlot);
        $slotExhaustedBlock = $fn->appendBasicBlock('slot_exhausted');
        $idDone = $context->builder->icmp(Builder::INT_SGE, $id, $max);
        $slotTryBlock = $fn->appendBasicBlock('slot_try');
        $context->builder->branchIf($idDone, $slotExhaustedBlock, $slotTryBlock);

        $context->builder->positionAtEnd($slotTryBlock);
        $slotIdx = $context->builder->truncOrBitCast($id, $i32);
        $entriesGep = $context->builder->inBoundsGEP(self::entriesGlobal($context), $zero32, $slotIdx);
        $entriesPtr = $context->builder->load($entriesGep);
        $slotTaken = $context->builder->icmp(Builder::INT_NE, $entriesPtr, $strPtrPtr->constNull());
        $slotNextBlock = $fn->appendBasicBlock('slot_next');
        $fillBlock = $fn->appendBasicBlock('fill');
        $context->builder->branchIf($slotTaken, $slotNextBlock, $fillBlock);

        $context->builder->positionAtEnd($slotNextBlock);
        $context->builder->store($context->builder->addNoSignedWrap($id, $one64), $idSlot);
        $context->builder->branch($slotLoopHead);

        $context->builder->positionAtEnd($fillBlock);
        $nSized = $context->builder->zExt($n, $sizeT);
        $ptrSize = $sizeT->constInt(8, false);
        $entriesArr = $context->builder->pointerCast(
            $context->builder->call($context->lookupFunction('calloc'), $nSized, $ptrSize),
            $strPtrPtr
        );
        $callocFailBlock = $fn->appendBasicBlock('calloc_fail');
        $callocFail = $context->builder->icmp(Builder::INT_EQ, $entriesArr, $strPtrPtr->constNull());
        $fillLoopBlock = $fn->appendBasicBlock('fill_loop');
        $context->builder->branchIf($callocFail, $callocFailBlock, $fillLoopBlock);

        $context->builder->positionAtEnd($callocFailBlock);
        self::emitFreeNamelist($context, $fn, $namelistSlot, $n);
        $context->builder->returnValue($negOne);

        $context->builder->positionAtEnd($fillLoopBlock);
        $context->builder->store($entriesArr, $entriesGep);
        $iSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $context->builder->store($zero32, $iSlot);
        $fillHead = $fn->appendBasicBlock('fill_head');
        $fillBody = $fn->appendBasicBlock('fill_body');
        $fillDone = $fn->appendBasicBlock('fill_done');
        $context->builder->branch($fillHead);
        $context->builder->positionAtEnd($fillHead);
        $i = $context->builder->load($iSlot);
        $context->builder->branchIf($context->builder->icmp(Builder::INT_SGE, $i, $n), $fillDone, $fillBody);
        $context->builder->positionAtEnd($fillBody);
        $namelist = $context->builder->load($namelistSlot);
        $dirent = $context->builder->load($context->builder->inBoundsGEP($namelist, $context->builder->zExt($i, $sizeT)));
        $nameCstr = $context->builder->gep(
            $context->builder->pointerCast($dirent, $i8p),
            $i8->constInt(self::DIRENT_D_NAME_OFFSET, false)
        );
        $len = $context->builder->call($context->lookupFunction('strlen'), $nameCstr);
        $lenI64 = $context->builder->zExt($len, $i64);
        $str = $context->builder->call($context->lookupFunction('__string__init'), $lenI64, $nameCstr);
        $strNull = $context->builder->icmp(Builder::INT_EQ, $str, $strPtr->constNull());
        $fillFailBlock = $fn->appendBasicBlock('fill_fail');
        $fillContBlock = $fn->appendBasicBlock('fill_cont');
        $context->builder->branchIf($strNull, $fillFailBlock, $fillContBlock);
        $context->builder->positionAtEnd($fillContBlock);
        $context->builder->store($str, $context->builder->inBoundsGEP($entriesArr, $context->builder->zExt($i, $sizeT)));
        $context->builder->store($context->builder->addNoSignedWrap($i, $i32->constInt(1, false)), $iSlot);
        $context->builder->branch($fillHead);

        $context->builder->positionAtEnd($fillFailBlock);
        $context->builder->call($context->lookupFunction('__phpc_dir_free_slot'), $id);
        self::emitFreeNamelist($context, $fn, $namelistSlot, $n);
        $context->builder->returnValue($negOne);

        $context->builder->positionAtEnd($fillDone);
        self::emitFreeNamelist($context, $fn, $namelistSlot, $n);
        $context->builder->store($n, $context->builder->inBoundsGEP(self::countGlobal($context), $zero32, $slotIdx));
        $context->builder->store($zero32, $context->builder->inBoundsGEP(self::posGlobal($context), $zero32, $slotIdx));
        $context->builder->returnValue($context->builder->addNoSignedWrap($base, $id));

        $context->builder->positionAtEnd($slotExhaustedBlock);
        self::emitFreeNamelist($context, $fn, $namelistSlot, $n);
        $context->builder->returnValue($negOne);
    }

    private static function emitFreeNamelist(Context $context, LlvmFunction $fn, Value $namelistSlot, Value $count): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i8pp = $context->getTypeFromString('int8**');
        $sizeT = $context->getTypeFromString('size_t');
        $voidPtr = $context->getTypeFromString('void*');
        $zero32 = $i32->constInt(0, false);
        $namelist = $context->builder->load($namelistSlot);
        $hasList = $context->builder->icmp(Builder::INT_NE, $namelist, $i8pp->constNull());
        $skipBlock = $fn->appendBasicBlock('free_nlist_skip');
        $initBlock = $fn->appendBasicBlock('free_nlist_init');
        $loopHead = $fn->appendBasicBlock('free_nlist_head');
        $loopBody = $fn->appendBasicBlock('free_nlist_body');
        $freeArrBlock = $fn->appendBasicBlock('free_nlist_arr');
        $doneBlock = $fn->appendBasicBlock('free_nlist_done');
        $context->builder->branchIf($hasList, $initBlock, $skipBlock);

        $context->builder->positionAtEnd($initBlock);
        $jSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $context->builder->store($zero32, $jSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $j = $context->builder->load($jSlot);
        $context->builder->branchIf($context->builder->icmp(Builder::INT_SGE, $j, $count), $freeArrBlock, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $dirent = $context->builder->load($context->builder->inBoundsGEP($namelist, $context->builder->zExt($j, $sizeT)));
        $context->builder->call($context->lookupFunction('free'), $dirent);
        $context->builder->store($context->builder->addNoSignedWrap($j, $i32->constInt(1, false)), $jSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($freeArrBlock);
        $context->builder->call($context->lookupFunction('free'), $context->bytePtr($namelist));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($skipBlock);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
    }

    private static function emitReaddir(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $max = $i64->constInt(self::MAX_DIR_HANDLES, false);
        $zero32 = $i32->constInt(0, false);
        $handle = $fn->getParam(0);
        $slot = self::slotFromHandle($context, $handle);

        $failBlock = $fn->appendBasicBlock('fail');
        $okBlock = $fn->appendBasicBlock('ok');
        $badSlot = $context->builder->or(
            $context->builder->icmp(Builder::INT_SLE, $slot, $i64->constInt(0, false)),
            $context->builder->icmp(Builder::INT_SGE, $slot, $max)
        );
        $context->builder->branchIf($badSlot, $failBlock, $okBlock);

        $context->builder->positionAtEnd($okBlock);
        $slotIdx = $context->builder->truncOrBitCast($slot, $i32);
        $entries = $context->builder->load(
            $context->builder->inBoundsGEP(self::entriesGlobal($context), $zero32, $slotIdx)
        );
        $noEntries = $context->builder->icmp(Builder::INT_EQ, $entries, $context->getTypeFromString('__string__**')->constNull());
        $readBlock = $fn->appendBasicBlock('read');
        $context->builder->branchIf($noEntries, $failBlock, $readBlock);

        $context->builder->positionAtEnd($readBlock);
        $posGep = $context->builder->inBoundsGEP(self::posGlobal($context), $zero32, $slotIdx);
        $pos = $context->builder->load($posGep);
        $count = $context->builder->load(
            $context->builder->inBoundsGEP(self::countGlobal($context), $zero32, $slotIdx)
        );
        $eof = $context->builder->icmp(Builder::INT_SGE, $pos, $count);
        $retBlock = $fn->appendBasicBlock('ret_entry');
        $context->builder->branchIf($eof, $failBlock, $retBlock);

        $context->builder->positionAtEnd($retBlock);
        $sizeT = $context->getTypeFromString('size_t');
        $result = $context->builder->load(
            $context->builder->inBoundsGEP($entries, $context->builder->zExt($pos, $sizeT))
        );
        $context->builder->store($context->builder->addNoSignedWrap($pos, $i32->constInt(1, false)), $posGep);
        $context->builder->returnValue($result);

        $context->builder->positionAtEnd($failBlock);
        $context->builder->returnValue($strPtr->constNull());
    }

    private static function emitClosedir(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $max = $i64->constInt(self::MAX_DIR_HANDLES, false);
        $zero = $i32->constInt(0, false);
        $one = $i32->constInt(1, false);
        $handle = $fn->getParam(0);
        $slot = self::slotFromHandle($context, $handle);

        $failBlock = $fn->appendBasicBlock('fail');
        $okBlock = $fn->appendBasicBlock('ok');
        $badSlot = $context->builder->or(
            $context->builder->icmp(Builder::INT_SLE, $slot, $i64->constInt(0, false)),
            $context->builder->icmp(Builder::INT_SGE, $slot, $max)
        );
        $context->builder->branchIf($badSlot, $failBlock, $okBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->call($context->lookupFunction('__phpc_dir_free_slot'), $slot);
        $context->builder->returnValue($one);

        $context->builder->positionAtEnd($failBlock);
        $context->builder->returnValue($zero);
    }

    private static function emitRewinddir(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $strPtrPtr = $context->getTypeFromString('__string__**');
        $max = $i64->constInt(self::MAX_DIR_HANDLES, false);
        $zero32 = $i32->constInt(0, false);
        $one = $i32->constInt(1, false);
        $handle = $fn->getParam(0);
        $slot = self::slotFromHandle($context, $handle);

        $failBlock = $fn->appendBasicBlock('fail');
        $okBlock = $fn->appendBasicBlock('ok');
        $badSlot = $context->builder->or(
            $context->builder->icmp(Builder::INT_SLE, $slot, $i64->constInt(0, false)),
            $context->builder->icmp(Builder::INT_SGE, $slot, $max)
        );
        $context->builder->branchIf($badSlot, $failBlock, $okBlock);

        $context->builder->positionAtEnd($okBlock);
        $slotIdx = $context->builder->truncOrBitCast($slot, $i32);
        $entries = $context->builder->load(
            $context->builder->inBoundsGEP(self::entriesGlobal($context), $zero32, $slotIdx)
        );
        $noEntries = $context->builder->icmp(Builder::INT_EQ, $entries, $strPtrPtr->constNull());
        $rewindBlock = $fn->appendBasicBlock('rewind');
        $context->builder->branchIf($noEntries, $failBlock, $rewindBlock);

        $context->builder->positionAtEnd($rewindBlock);
        $context->builder->store($zero32, $context->builder->inBoundsGEP(self::posGlobal($context), $zero32, $slotIdx));
        $context->builder->returnValue($one);

        $context->builder->positionAtEnd($failBlock);
        $context->builder->returnValue($zero32);
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringDirStandaloneLlvm implement');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
