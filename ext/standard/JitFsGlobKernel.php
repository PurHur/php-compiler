<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM NestedJIT / iterator-thin leaf for glob()/scandir() — libc glob(3)/scandir(3)
 * (#27235, #27236, #5459, #29986).
 *
 * Used while NestedJIT compiles {@see FsGlobJitHelper} `@\glob`/`@\scandir` via
 * {@see JitFsGlob} — no always-on thin-AOT ABI fork for user-facing builtins
 * (peer {@see JitTempnamKernel} #29940). GlobIterator/DirectoryIterator thin bridges
 * still call {@see implement} directly for `__phpc_*_vec`.
 * php-src: ext/standard/dir.c — PHP_FUNCTION(glob), PHP_FUNCTION(scandir)
 */
final class JitFsGlobKernel
{
    private const PHP_GLOB_ONLYDIR = 8192;

    private const GLOB_NOMATCH = 3;

    private const SCANDIR_SORT_DESCENDING = 1;

    private const SCANDIR_SORT_NONE = 2;

    /** Linux glibc x86_64 struct dirent::d_name offset. */
    private const DIRENT_D_NAME_OFFSET = 19;

    private const S_IFMT = 0xF000;

    private const S_IFDIR = 0x4000;

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__phpc_strvec_free',
        '__phpc_glob_vec',
        '__phpc_scandir_vec',
    ];

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__phpc_glob_vec');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        self::ensureLibc($context);
        self::ensureScandirDescCmp($context);

        self::implementIfMissing($context, '__phpc_strvec_free', self::emitStrvecFree(...));
        self::implementIfMissing($context, '__phpc_glob_vec', self::emitGlobVec(...));
        self::implementIfMissing($context, '__phpc_scandir_vec', self::emitScandirVec(...));
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
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
        $i8p = $context->getTypeFromString('int8*');
        $i8pp = $context->getTypeFromString('int8**');
        $i8ppp = $i8pp->pointerType(0);
        $strPtr = $context->getTypeFromString('__string__*');
        $voidTy = $context->getTypeFromString('void');

        $fn = match ($name) {
            '__phpc_strvec_free' => $context->module->addFunction(
                $name,
                $context->context->functionType($voidTy, false, $i8pp, $i32)
            ),
            '__phpc_glob_vec', '__phpc_scandir_vec' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $strPtr, $i32, $i8ppp)
            ),
            default => throw new \LogicException('Unknown fs glob vec helper: '.$name),
        };
        $context->registerFunction($name, $fn);

        return $fn;
    }

    private static function ensureLibc(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $i8pp = $context->getTypeFromString('int8**');
        $i8ppp = $i8pp->pointerType(0);
        $voidTy = $context->getTypeFromString('void');

        // glob/scandir + module-local stat(2) after LibcExtern/Module always-on drop (#31403).
        // strdup(3) after always-on LibcExtern drop (#31534) — still required by emitGlobVec /
        // emitScandirVec (#31721 AOT FilesystemIterator/GlobIterator).
        // memset(3) after always-on LibcExtern drop (#31863); malloc/free still come
        // from LibcExtern (i8*). strcmp(3) after always-on drop (#31971).
        LibcExtern::ensureMemsetDecl($context);
        LibcExtern::ensureStrcmpDecl($context);
        foreach ([
            ['glob', $i32, [$i8p, $i32, $i8p, $i8p]],
            ['globfree', $voidTy, [$i8p]],
            ['scandir', $i32, [$i8p, $i8ppp, $i8p, $i8p]],
            ['alphasort', $i32, [$i8pp, $i8pp]],
            ['stat', $i32, [$i8p, $i8p]],
            ['strdup', $i8p, [$i8p]],
        ] as [$name, $ret, $params]) {
            self::ensureExternal($context, $name, $context->context->functionType($ret, false, ...$params));
        }
    }

    private static function ensureScandirDescCmp(Context $context): void
    {
        $name = '__phpc_scandir_desc_cmp';
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }

        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $i8pp = $context->getTypeFromString('int8**');
        $fn = $context->module->addFunction(
            $name,
            $context->context->functionType($i32, false, $i8pp, $i8pp)
        );
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);
        $a = $context->builder->load($fn->getParam(0));
        $b = $context->builder->load($fn->getParam(1));
        $nameA = $context->builder->gep(
            $context->builder->pointerCast($a, $i8p),
            $context->getTypeFromString('int8')->constInt(self::DIRENT_D_NAME_OFFSET, false)
        );
        $nameB = $context->builder->gep(
            $context->builder->pointerCast($b, $i8p),
            $context->getTypeFromString('int8')->constInt(self::DIRENT_D_NAME_OFFSET, false)
        );
        // strcmp(3) via LibcExtern::ensureStrcmpDecl after always-on drop (#31971).
        LibcExtern::ensureStrcmpDecl($context);
        $cmpB = $context->builder->call($context->lookupFunction('strcmp'), $nameB, $nameA);
        $context->builder->returnValue($cmpB);
        $context->builder->clearInsertionPosition();
        $context->registerFunction($name, $fn);
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

    private static function stringData(Context $context, Value $str): Value
    {
        $map = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->inBoundsGEP(
            $context->builder->structGep($str, $map['value']),
            $i64->constInt(0, false)
        );
    }

    private static function stackArrayBase(Context $context, Value $slot): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->inBoundsGEP($slot, $i32->constInt(0, false), $i64->constInt(0, false));
    }

    private static function pathIsDir(Context $context, Value $statSlot, Value $pathCstr): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $voidPtr = $context->getTypeFromString('void*');
        $statSize = $i64->constInt(144, false);
        $statBase = self::stackArrayBase($context, $statSlot);
        $statPtr = $context->builder->pointerCast($statBase, $context->getTypeFromString('int8*'));
        $context->builder->call(
            $context->lookupFunction('memset'),
            $statPtr,
            $i32->constInt(0, false),
            $statSize
        );
        $rc = $context->builder->call(
            $context->lookupFunction('stat'),
            $pathCstr,
            $statPtr
        );
        $ok = $context->builder->icmp(Builder::INT_EQ, $rc, $i32->constInt(0, false));
        $modeOff = $i64->constInt(24, false);
        $modePtr = $context->builder->inBoundsGEP($statBase, $modeOff);
        $mode = $context->builder->load($context->builder->pointerCast($modePtr, $i32->pointerType(0)));
        $masked = $context->builder->and($mode, $i32->constInt(self::S_IFMT, false));
        $isDir = $context->builder->icmp(Builder::INT_EQ, $masked, $i32->constInt(self::S_IFDIR, false));

        return $context->builder->and($ok, $isDir);
    }

    private static function emitStrvecFree(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $i8pp = $context->getTypeFromString('int8**');
        $sizeT = $context->getTypeFromString('size_t');
        $voidPtr = $context->getTypeFromString('void*');
        $items = $fn->getParam(0);
        $count = $fn->getParam(1);
        $zero = $i32->constInt(0, false);

        $nullBlock = $fn->appendBasicBlock('null_items');
        $bodyBlock = $fn->appendBasicBlock('body');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $items, $i8pp->constNull());
        $context->builder->branchIf($isNull, $nullBlock, $bodyBlock);

        $context->builder->positionAtEnd($nullBlock);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($bodyBlock);
        $iSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $context->builder->store($zero, $iSlot);
        $loopHead = $fn->appendBasicBlock('loop_head');
        $loopBody = $fn->appendBasicBlock('loop_body');
        $freeArr = $fn->appendBasicBlock('free_arr');
        $done = $fn->appendBasicBlock('done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $context->builder->branchIf($context->builder->icmp(Builder::INT_SGE, $i, $count), $freeArr, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $cstr = $context->builder->load($context->builder->inBoundsGEP($items, $context->builder->zExt($i, $sizeT)));
        $context->builder->call($context->lookupFunction('free'), $cstr);
        $context->builder->store($context->builder->addNoSignedWrap($i, $i32->constInt(1, false)), $iSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($freeArr);
        $context->builder->call($context->lookupFunction('free'), $context->bytePtr($items));
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $context->builder->returnVoid();
    }

    private static function emitGlobVec(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $i8pp = $context->getTypeFromString('int8**');
        $i8ppp = $i8pp->pointerType(0);
        $sizeT = $context->getTypeFromString('size_t');
        $voidPtr = $context->getTypeFromString('void*');
        $strPtr = $context->getTypeFromString('__string__*');
        $negOne = $i32->constInt(-1, false);
        $zero32 = $i32->constInt(0, false);
        $globSize = $i64->constInt(64, false);
        $ptrSize = $sizeT->constInt(8, false);

        $outItems = $fn->getParam(2);
        $pattern = $fn->getParam(0);
        $flags = $fn->getParam(1);

        $failBlock = $fn->appendBasicBlock('glob_fail');
        $nullOut = $context->builder->icmp(Builder::INT_EQ, $outItems, $i8ppp->constNull());
        $nullPat = $context->builder->icmp(Builder::INT_EQ, $pattern, $strPtr->constNull());
        $badArgs = $context->builder->or($nullOut, $nullPat);
        $initBlock = $fn->appendBasicBlock('glob_init');
        $context->builder->branchIf($badArgs, $failBlock, $initBlock);

        $context->builder->positionAtEnd($initBlock);
        $context->builder->store($i8pp->constNull(), $outItems);
        $onlyDir = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->and($flags, $i32->constInt(self::PHP_GLOB_ONLYDIR, false)),
            $zero32
        );
        $i8 = $context->getTypeFromString('int8');
        $globSlot = BasicBlockHelper::entryAlloca($context, $i8->arrayType(64));
        $statSlot = BasicBlockHelper::entryAlloca($context, $i8->arrayType(144));
        $globBase = self::stackArrayBase($context, $globSlot);
        $context->builder->call(
            $context->lookupFunction('memset'),
            $context->bytePtr($globBase),
            $zero32,
            $globSize
        );
        $pat = self::stringData($context, $pattern);
        $rc = $context->builder->call(
            $context->lookupFunction('glob'),
            $pat,
            $flags,
            $i8p->constNull(),
            $context->bytePtr($globBase)
        );
        $nomatch = $context->builder->icmp(Builder::INT_EQ, $rc, $i32->constInt(self::GLOB_NOMATCH, false));
        $globErr = $context->builder->icmp(Builder::INT_NE, $rc, $zero32);
        $emptyOk = $fn->appendBasicBlock('glob_empty');
        $afterGlob = $fn->appendBasicBlock('glob_after');
        $collectBlock = $fn->appendBasicBlock('glob_collect');
        $context->builder->branchIf($nomatch, $emptyOk, $afterGlob);

        $context->builder->positionAtEnd($afterGlob);
        $context->builder->branchIf($globErr, $failBlock, $collectBlock);

        $context->builder->positionAtEnd($collectBlock);
        $countPtr = $context->builder->inBoundsGEP($globBase, $i64->constInt(0, false));
        $count = $context->builder->load($context->builder->pointerCast($countPtr, $sizeT->pointerType(0)));
        $zeroCount = $context->builder->icmp(Builder::INT_EQ, $count, $sizeT->constInt(0, false));
        $zeroFreeBlock = $fn->appendBasicBlock('glob_zero_free');
        $allocBlock = $fn->appendBasicBlock('glob_alloc');
        $context->builder->branchIf($zeroCount, $zeroFreeBlock, $allocBlock);

        $context->builder->positionAtEnd($zeroFreeBlock);
        $context->builder->call($context->lookupFunction('globfree'), $context->bytePtr($globBase));
        $context->builder->branch($emptyOk);
        $context->builder->positionAtEnd($allocBlock);
        $items = $context->builder->pointerCast(
            $context->builder->call($context->lookupFunction('malloc'), $context->builder->mul($count, $ptrSize)),
            $i8pp
        );
        $mallocFail = $context->builder->icmp(Builder::INT_EQ, $items, $i8pp->constNull());
        $freeGlobFail = $fn->appendBasicBlock('glob_malloc_fail');
        $fillBlock = $fn->appendBasicBlock('glob_fill');
        $context->builder->branchIf($mallocFail, $freeGlobFail, $fillBlock);

        $context->builder->positionAtEnd($freeGlobFail);
        $context->builder->call($context->lookupFunction('globfree'), $context->bytePtr($globBase));
        $context->builder->branch($failBlock);

        $context->builder->positionAtEnd($fillBlock);
        $pathvPtr = $context->builder->load(
            $context->builder->pointerCast(
                $context->builder->inBoundsGEP($globBase, $i64->constInt(8, false)),
                $i8ppp
            )
        );
        $keptSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $iSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($sizeT->constInt(0, false), $keptSlot);
        $context->builder->store($sizeT->constInt(0, false), $iSlot);
        $loopHead = $fn->appendBasicBlock('glob_loop_head');
        $loopBody = $fn->appendBasicBlock('glob_loop_body');
        $loopDone = $fn->appendBasicBlock('glob_loop_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $context->builder->branchIf($context->builder->icmp(Builder::INT_SGE, $i, $count), $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $pathCstr = $context->builder->load($context->builder->inBoundsGEP($pathvPtr, $i));
        $skipDirBlock = $fn->appendBasicBlock('glob_skip_dir');
        $dupBlock = $fn->appendBasicBlock('glob_dup');
        $context->builder->branchIf($onlyDir, $skipDirBlock, $dupBlock);

        $nextBlock = $fn->appendBasicBlock('glob_next');
        $context->builder->positionAtEnd($skipDirBlock);
        $isDir = self::pathIsDir($context, $statSlot, $pathCstr);
        $context->builder->branchIf($isDir, $dupBlock, $nextBlock);

        $context->builder->positionAtEnd($nextBlock);
        $context->builder->store($context->builder->addNoSignedWrap($i, $sizeT->constInt(1, false)), $iSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($dupBlock);
        $kept = $context->builder->load($keptSlot);
        $dup = $context->builder->call($context->lookupFunction('strdup'), $pathCstr);
        $dupFail = $context->builder->icmp(Builder::INT_EQ, $dup, $i8p->constNull());
        $dupFailBlock = $fn->appendBasicBlock('glob_dup_fail');
        $storeBlock = $fn->appendBasicBlock('glob_store');
        $context->builder->branchIf($dupFail, $dupFailBlock, $storeBlock);

        $context->builder->positionAtEnd($dupFailBlock);
        $context->builder->call($context->lookupFunction('__phpc_strvec_free'), $items, $context->builder->truncOrBitCast($kept, $i32));
        $context->builder->store($i8pp->constNull(), $outItems);
        $context->builder->call($context->lookupFunction('globfree'), $context->bytePtr($globBase));
        $context->builder->branch($failBlock);

        $context->builder->positionAtEnd($storeBlock);
        $context->builder->store($dup, $context->builder->inBoundsGEP($items, $kept));
        $context->builder->store($context->builder->addNoSignedWrap($kept, $sizeT->constInt(1, false)), $keptSlot);
        $context->builder->branch($nextBlock);

        $context->builder->positionAtEnd($loopDone);
        $context->builder->call($context->lookupFunction('globfree'), $context->bytePtr($globBase));
        $kept = $context->builder->load($keptSlot);
        $keptZero = $context->builder->icmp(Builder::INT_EQ, $kept, $sizeT->constInt(0, false));
        $keptEmptyBlock = $fn->appendBasicBlock('glob_kept_empty');
        $shrinkBlock = $fn->appendBasicBlock('glob_shrink');
        $context->builder->branchIf($keptZero, $keptEmptyBlock, $shrinkBlock);

        $context->builder->positionAtEnd($keptEmptyBlock);
        $context->builder->call($context->lookupFunction('free'), $context->bytePtr($items));
        $context->builder->store($i8pp->constNull(), $outItems);
        $context->builder->branch($emptyOk);

        $context->builder->positionAtEnd($emptyOk);
        $context->builder->returnValue($zero32);

        $context->builder->positionAtEnd($shrinkBlock);
        $ltCount = $context->builder->icmp(Builder::INT_SLT, $kept, $count);
        $maybeRealloc = $fn->appendBasicBlock('glob_maybe_realloc');
        $setOut = $fn->appendBasicBlock('glob_set_out');
        $context->builder->branchIf($ltCount, $maybeRealloc, $setOut);

        $context->builder->positionAtEnd($maybeRealloc);
        $shrunk = $context->builder->pointerCast(
            $context->builder->call(
                $context->lookupFunction('realloc'),
                $context->bytePtr($items),
                $context->builder->mul($kept, $ptrSize)
            ),
            $i8pp
        );
        $useShrunk = $context->builder->icmp(Builder::INT_NE, $shrunk, $i8pp->constNull());
        $reallocItems = $context->builder->select($useShrunk, $shrunk, $items);
        $reallocTail = $context->builder->getInsertBlock();
        $context->builder->branch($setOut);

        $context->builder->positionAtEnd($setOut);
        $itemsPhi = $context->builder->phi($i8pp);
        $itemsPhi->addIncoming($items, $shrinkBlock);
        $itemsPhi->addIncoming($reallocItems, $reallocTail);
        $context->builder->store($itemsPhi, $outItems);
        $context->builder->returnValue($context->builder->truncOrBitCast($kept, $i32));

        $context->builder->positionAtEnd($failBlock);
        $context->builder->returnValue($negOne);
    }

    private static function emitScandirVec(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $i8pp = $context->getTypeFromString('int8**');
        $i8ppp = $i8pp->pointerType(0);
        $sizeT = $context->getTypeFromString('size_t');
        $voidPtr = $context->getTypeFromString('void*');
        $strPtr = $context->getTypeFromString('__string__*');
        $negOne = $i32->constInt(-1, false);
        $zero32 = $i32->constInt(0, false);
        $ptrSize = $sizeT->constInt(8, false);

        $outItems = $fn->getParam(2);
        $path = $fn->getParam(0);
        $sortOrder = $fn->getParam(1);

        $failBlock = $fn->appendBasicBlock('scan_fail');
        $badArgs = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $outItems, $i8ppp->constNull()),
            $context->builder->icmp(Builder::INT_EQ, $path, $strPtr->constNull())
        );
        $initBlock = $fn->appendBasicBlock('scan_init');
        $context->builder->branchIf($badArgs, $failBlock, $initBlock);

        $context->builder->positionAtEnd($initBlock);
        $context->builder->store($i8pp->constNull(), $outItems);
        $namelistSlot = BasicBlockHelper::entryAlloca($context, $i8pp);
        $context->builder->store($i8pp->constNull(), $namelistSlot);
        $dir = self::stringData($context, $path);

        $isDesc = $context->builder->icmp(Builder::INT_EQ, $sortOrder, $i32->constInt(self::SCANDIR_SORT_DESCENDING, false));
        $isNone = $context->builder->icmp(Builder::INT_EQ, $sortOrder, $i32->constInt(self::SCANDIR_SORT_NONE, false));
        $cmpSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $pickCmp = $fn->appendBasicBlock('scan_pick_cmp');
        $afterCmp = $fn->appendBasicBlock('scan_after_cmp');
        $context->builder->branch($pickCmp);

        $context->builder->positionAtEnd($pickCmp);
        $descCmp = $context->lookupFunction('__phpc_scandir_desc_cmp');
        $alphaCmp = $context->lookupFunction('alphasort');
        $nullCmp = $i8p->constNull();
        $cmp = $context->builder->select(
            $isDesc,
            $context->bytePtr($descCmp),
            $context->builder->select(
                $isNone,
                $nullCmp,
                $context->bytePtr($alphaCmp)
            )
        );
        $context->builder->store($cmp, $cmpSlot);
        $context->builder->branch($afterCmp);

        $context->builder->positionAtEnd($afterCmp);
        $n = $context->builder->call(
            $context->lookupFunction('scandir'),
            $dir,
            $namelistSlot,
            $nullCmp,
            $context->builder->load($cmpSlot)
        );
        $scanFail = $context->builder->icmp(Builder::INT_SLT, $n, $zero32);
        $emptyOk = $fn->appendBasicBlock('scan_empty');
        $allocBlock = $fn->appendBasicBlock('scan_alloc');
        $checkN = $fn->appendBasicBlock('scan_check_n');
        $context->builder->branchIf($scanFail, $failBlock, $checkN);
        $context->builder->positionAtEnd($checkN);
        $context->builder->branchIf($context->builder->icmp(Builder::INT_EQ, $n, $zero32), $emptyOk, $allocBlock);

        $context->builder->positionAtEnd($allocBlock);
        $nSized = $context->builder->zExt($n, $sizeT);
        $items = $context->builder->pointerCast(
            $context->builder->call($context->lookupFunction('malloc'), $context->builder->mul($nSized, $ptrSize)),
            $i8pp
        );
        $mallocFail = $context->builder->icmp(Builder::INT_EQ, $items, $i8pp->constNull());
        $freeListFail = $fn->appendBasicBlock('scan_malloc_fail');
        $fillBlock = $fn->appendBasicBlock('scan_fill');
        $context->builder->branchIf($mallocFail, $freeListFail, $fillBlock);

        $context->builder->positionAtEnd($freeListFail);
        self::freeNamelist($context, $fn, $namelistSlot, $n);
        $context->builder->branch($failBlock);

        $context->builder->positionAtEnd($fillBlock);
        $iSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $context->builder->store($zero32, $iSlot);
        $loopHead = $fn->appendBasicBlock('scan_loop_head');
        $loopBody = $fn->appendBasicBlock('scan_loop_body');
        $loopDone = $fn->appendBasicBlock('scan_loop_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $context->builder->branchIf($context->builder->icmp(Builder::INT_SGE, $i, $n), $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $namelist = $context->builder->load($namelistSlot);
        $dirent = $context->builder->load($context->builder->inBoundsGEP($namelist, $context->builder->zExt($i, $sizeT)));
        $nameCstr = $context->builder->gep(
            $context->builder->pointerCast($dirent, $i8p),
            $context->getTypeFromString('int8')->constInt(self::DIRENT_D_NAME_OFFSET, false)
        );
        $dup = $context->builder->call($context->lookupFunction('strdup'), $nameCstr);
        $dupFail = $context->builder->icmp(Builder::INT_EQ, $dup, $i8p->constNull());
        $dupFailBlock = $fn->appendBasicBlock('scan_dup_fail');
        $storeBlock = $fn->appendBasicBlock('scan_store');
        $context->builder->branchIf($dupFail, $dupFailBlock, $storeBlock);

        $context->builder->positionAtEnd($dupFailBlock);
        $context->builder->call($context->lookupFunction('__phpc_strvec_free'), $items, $i);
        $context->builder->store($i8pp->constNull(), $outItems);
        self::freeNamelistFrom($context, $fn, $namelistSlot, $i, $n);
        $context->builder->branch($failBlock);

        $context->builder->positionAtEnd($storeBlock);
        $context->builder->store($dup, $context->builder->inBoundsGEP($items, $context->builder->zExt($i, $sizeT)));
        $context->builder->call($context->lookupFunction('free'), $dirent);
        $context->builder->store($context->builder->addNoSignedWrap($i, $i32->constInt(1, false)), $iSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);
        $context->builder->call(
            $context->lookupFunction('free'),
            $context->bytePtr($context->builder->load($namelistSlot))
        );
        $context->builder->store($items, $outItems);
        $context->builder->returnValue($n);

        $context->builder->positionAtEnd($emptyOk);
        $namelist = $context->builder->load($namelistSlot);
        $hasList = $context->builder->icmp(Builder::INT_NE, $namelist, $i8pp->constNull());
        $freeEmpty = $fn->appendBasicBlock('scan_free_empty');
        $retEmpty = $fn->appendBasicBlock('scan_ret_empty');
        $context->builder->branchIf($hasList, $freeEmpty, $retEmpty);
        $context->builder->positionAtEnd($freeEmpty);
        $context->builder->call($context->lookupFunction('free'), $context->bytePtr($namelist));
        $context->builder->branch($retEmpty);
        $context->builder->positionAtEnd($retEmpty);
        $context->builder->returnValue($zero32);

        $context->builder->positionAtEnd($failBlock);
        $context->builder->returnValue($negOne);
    }

    private static function freeNamelist(Context $context, LlvmFunction $fn, Value $namelistSlot, Value $count): void
    {
        self::freeNamelistFrom($context, $fn, $namelistSlot, $context->getTypeFromString('int32')->constInt(0, false), $count);
    }

    private static function freeNamelistFrom(Context $context, LlvmFunction $fn, Value $namelistSlot, Value $from, Value $count): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i8pp = $context->getTypeFromString('int8**');
        $sizeT = $context->getTypeFromString('size_t');
        $voidPtr = $context->getTypeFromString('void*');
        $namelist = $context->builder->load($namelistSlot);
        $hasList = $context->builder->icmp(Builder::INT_NE, $namelist, $i8pp->constNull());
        $skip = $fn->appendBasicBlock('nlist_skip');
        $init = $fn->appendBasicBlock('nlist_init');
        $context->builder->branchIf($hasList, $init, $skip);

        $context->builder->positionAtEnd($init);
        $jSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $context->builder->store($from, $jSlot);
        $head = $fn->appendBasicBlock('nlist_head');
        $body = $fn->appendBasicBlock('nlist_body');
        $freeArr = $fn->appendBasicBlock('nlist_free_arr');
        $done = $fn->appendBasicBlock('nlist_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $j = $context->builder->load($jSlot);
        $context->builder->branchIf($context->builder->icmp(Builder::INT_SGE, $j, $count), $freeArr, $body);

        $context->builder->positionAtEnd($body);
        $dirent = $context->builder->load($context->builder->inBoundsGEP($namelist, $context->builder->zExt($j, $sizeT)));
        $context->builder->call($context->lookupFunction('free'), $dirent);
        $context->builder->store($context->builder->addNoSignedWrap($j, $i32->constInt(1, false)), $jSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($freeArr);
        $context->builder->call($context->lookupFunction('free'), $context->bytePtr($namelist));
        $context->builder->branch($done);

        $context->builder->positionAtEnd($skip);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after FsGlobVecStandaloneLlvm implement (#11515)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
