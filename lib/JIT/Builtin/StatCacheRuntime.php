<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM stat/realpath cache for JIT/AOT (issue #9110).
 *
 * Mirrors {@see \PHPCompiler\ext\standard\VmStatCache} / php-src ext/standard/filestat.c.
 */
final class StatCacheRuntime
{
    private const STAT_BUF_SIZE = 144;

    private const STAT_MODE_OFFSET = 24;

    private const REALPATH_BUF_SIZE = 4096;

    private const GLOBAL_STAT_MODE_CACHE = 'phpc_stat_mode_cache';

    private const GLOBAL_LSTAT_MODE_CACHE = 'phpc_lstat_mode_cache';

    private const GLOBAL_REALPATH_CACHE = 'phpc_realpath_cache';

    public const FN_CLEAR = '__compiler_clearstatcache';

    public const FN_MODE_CACHED = '__phpc_jit_stat_mode_cached';

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        self::FN_CLEAR,
        self::FN_MODE_CACHED,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::FN_CLEAR);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureGlobals($context);
        self::ensureLibc($context);
        self::ensureHashtableHelpers($context);
        self::ensureValueHelpers($context);

        self::implementIfMissing($context, self::FN_MODE_CACHED, self::emitModeCached(...));
        self::implementIfMissing($context, self::FN_CLEAR, self::emitClear(...));

        self::registerLinkedRuntime($context);
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
        $saved = $context->builder;
        $context->builder = $context->context->builderCreate();
        try {
            $emit($context, $fn);
        } finally {
            $context->builder->clearInsertionPosition();
            $context->builder = $saved;
        }
        $context->registerFunction($name, $fn);
    }

    private static function declareFunction(Context $context, string $name): LlvmFunction
    {
        try {
            return $context->lookupFunction($name);
        } catch (\Throwable) {
            // fall through
        }

        $i32 = $context->getTypeFromString('int32');
        $i1 = $context->getTypeFromString('int1');
        $strPtr = $context->getTypeFromString('__string__*');
        $voidTy = $context->getTypeFromString('void');

        $fn = match ($name) {
            self::FN_MODE_CACHED => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $strPtr, $i32)
            ),
            self::FN_CLEAR => $context->module->addFunction(
                $name,
                $context->context->functionType($voidTy, false, $i32, $i1, $strPtr)
            ),
            default => throw new \LogicException('Unknown stat cache JIT function: '.$name),
        };
        $context->registerFunction($name, $fn);

        return $fn;
    }

    private static function ensureGlobals(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $null = $htPtr->constNull();
        foreach ([
            self::GLOBAL_STAT_MODE_CACHE,
            self::GLOBAL_LSTAT_MODE_CACHE,
            self::GLOBAL_REALPATH_CACHE,
        ] as $name) {
            if (null === $context->module->getNamedGlobal($name)) {
                $global = $context->module->addGlobal($htPtr, $name);
                $global->setInitializer($null);
            }
        }
    }

    private static function ensureLibc(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $charPtr = $context->getTypeFromString('char*');
        $strPtr = $context->getTypeFromString('__string__*');
        $voidTy = $context->getTypeFromString('void');

        foreach ([
            ['stat', $i32, [$i8p, $i8p]],
            ['lstat', $i32, [$i8p, $i8p]],
            ['realpath', $charPtr, [$i8p, $charPtr]],
            ['strlen', $i64, [$i8p]],
            ['__mm__free', $voidTy, [$i8p]],
            ['__string__init', $strPtr, [$i64, $i8p]],
        ] as [$name, $ret, $params]) {
            self::ensureExternal($context, $name, $context->context->functionType($ret, false, ...$params));
        }
    }

    private static function ensureHashtableHelpers(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $voidTy = $context->getTypeFromString('void');
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');

        foreach ([
            ['__hashtable__alloc', $htPtr, []],
            ['__hashtable__offsetIsSetStringKey', $i1, [$htPtr, $strPtr]],
            ['__hashtable__setStringKeyLong', $voidTy, [$htPtr, $strPtr, $i64]],
            ['__hashtable__unsetStringKey', $voidTy, [$htPtr, $strPtr]],
        ] as [$name, $ret, $params]) {
            self::ensureExternal($context, $name, $context->context->functionType($ret, false, ...$params));
        }
    }

    private static function ensureValueHelpers(Context $context): void
    {
        $valuePtr = $context->getTypeFromString('__value__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');

        foreach ([
            ['__hashtable__readStringKeyValue', $valuePtr, [$htPtr, $strPtr]],
            ['__value__readLong', $i64, [$valuePtr]],
        ] as [$name, $ret, $params]) {
            self::ensureExternal($context, $name, $context->context->functionType($ret, false, ...$params));
        }
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

    private static function globalHt(Context $context, string $name): Value
    {
        $global = $context->module->getNamedGlobal($name);
        if (null === $global) {
            throw new \LogicException('StatCacheRuntime global missing: '.$name);
        }
        $htPtr = $context->getTypeFromString('__hashtable__*');

        return $context->builder->load($context->builder->pointerCast($global, $htPtr->pointerType(0)));
    }

    private static function storeGlobalHt(Context $context, string $name, Value $ht): void
    {
        $global = $context->module->getNamedGlobal($name);
        if (null === $global) {
            throw new \LogicException('StatCacheRuntime global missing: '.$name);
        }
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $context->builder->store($ht, $context->builder->pointerCast($global, $htPtr->pointerType(0)));
    }

    private static function ensureHtGlobal(Context $context, LlvmFunction $fn, string $globalName): Value
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $ht = self::globalHt($context, $globalName);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $ht, $htPtr->constNull());
        $allocBlock = $fn->appendBasicBlock('stat_cache_alloc_'.$globalName);
        $doneBlock = $fn->appendBasicBlock('stat_cache_done_'.$globalName);
        $beforeBranch = $context->builder->getInsertBlock();
        $context->builder->branchIf($isNull, $allocBlock, $doneBlock);

        $context->builder->positionAtEnd($allocBlock);
        $fresh = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        self::storeGlobalHt($context, $globalName, $fresh);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($htPtr);
        $phi->addIncoming($fresh, $allocBlock);
        $phi->addIncoming($ht, $beforeBranch);

        return $phi;
    }

    private static function stringData(Context $context, Value $strObj): Value
    {
        $map = $context->structFieldMap['__string__'];

        return $context->builder->structGep($strObj, $map['value']);
    }

    private static function stackBytesPtr(Context $context, Value $slot): Value
    {
        return $context->builder->pointerCast($slot, $context->getTypeFromString('int8*'));
    }

    private static function loadModeFromLibc(Context $context, Value $pathPtr, bool $lstat): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $buf = $context->builder->alloca($i8->arrayType(self::STAT_BUF_SIZE), 1, 'stat_cache_buf');
        $bufPtr = self::stackBytesPtr($context, $buf);
        $fn = $lstat ? 'lstat' : 'stat';
        $rc = $context->builder->call($context->lookupFunction($fn), $pathPtr, $bufPtr);
        $zero = $i32->constInt(0, false);
        $failed = $context->builder->icmp(Builder::INT_NE, $rc, $zero);
        $modePtr = $context->builder->pointerCast(
            $context->builder->gep($bufPtr, $i64->constInt(self::STAT_MODE_OFFSET, false)),
            $i32->pointerType(0)
        );
        $mode = $context->builder->load($modePtr);
        $minusOne = $i32->constInt(-1, true);

        return $context->builder->select($failed, $minusOne, $mode);
    }

    private static function storeModeEntry(Context $context, Value $ht, Value $pathStr, Value $modeI32): void
    {
        $i64 = $context->getTypeFromString('int64');
        $modeI64 = $context->builder->sext($modeI32, $i64);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyLong'),
            $ht,
            $pathStr,
            $modeI64
        );
    }

    private static function emitModeCached(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $path = $fn->getParam(0);
        $useLstat = $fn->getParam(1);
        $strPtr = $context->getTypeFromString('__string__*');
        $i32 = $context->getTypeFromString('int32');
        $zero = $i32->constInt(0, false);
        $minusOne = $i32->constInt(-1, true);

        $nullPath = $context->builder->icmp(Builder::INT_EQ, $path, $strPtr->constNull());
        $fail = $fn->appendBasicBlock('mode_cached_fail');
        $run = $fn->appendBasicBlock('mode_cached_run');
        $context->builder->branchIf($nullPath, $fail, $run);

        $context->builder->positionAtEnd($run);
        $isLstat = $context->builder->icmp(Builder::INT_NE, $useLstat, $zero);
        $pickLstat = $fn->appendBasicBlock('mode_cached_pick_lstat');
        $pickStat = $fn->appendBasicBlock('mode_cached_pick_stat');
        $afterPick = $fn->appendBasicBlock('mode_cached_after_pick');
        $context->builder->branchIf($isLstat, $pickLstat, $pickStat);

        $context->builder->positionAtEnd($pickLstat);
        $htL = self::ensureHtGlobal($context, $fn, self::GLOBAL_LSTAT_MODE_CACHE);
        $context->builder->branch($afterPick);
        $pickLstatTail = $context->builder->getInsertBlock();

        $context->builder->positionAtEnd($pickStat);
        $htS = self::ensureHtGlobal($context, $fn, self::GLOBAL_STAT_MODE_CACHE);
        $context->builder->branch($afterPick);
        $pickStatTail = $context->builder->getInsertBlock();

        $context->builder->positionAtEnd($afterPick);
        $htPtrTy = $context->getTypeFromString('__hashtable__*');
        $htPhi = $context->builder->phi($htPtrTy);
        $htPhi->addIncoming($htL, $pickLstatTail);
        $htPhi->addIncoming($htS, $pickStatTail);

        $hit = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSetStringKey'),
            $htPhi,
            $path
        );
        $hitBlock = $fn->appendBasicBlock('mode_cached_hit');
        $missBlock = $fn->appendBasicBlock('mode_cached_miss');
        $context->builder->branchIf($hit, $hitBlock, $missBlock);

        $context->builder->positionAtEnd($hitBlock);
        $val = $context->builder->call(
            $context->lookupFunction('__hashtable__readStringKeyValue'),
            $htPhi,
            $path
        );
        $modeI64 = $context->builder->call($context->lookupFunction('__value__readLong'), $val);
        $cachedMode = $context->builder->truncOrBitCast($modeI64, $i32);
        $context->builder->returnValue($cachedMode);

        $context->builder->positionAtEnd($missBlock);
        $pathCstr = self::stringData($context, $path);
        $doLstat = $fn->appendBasicBlock('mode_cached_do_lstat');
        $doStat = $fn->appendBasicBlock('mode_cached_do_stat');
        $afterLibc = $fn->appendBasicBlock('mode_cached_after_libc');
        $context->builder->branchIf($isLstat, $doLstat, $doStat);

        $context->builder->positionAtEnd($doStat);
        $modeStat = self::loadModeFromLibc($context, $pathCstr, false);
        $context->builder->branch($afterLibc);
        $doStatTail = $context->builder->getInsertBlock();

        $context->builder->positionAtEnd($doLstat);
        $modeLstat = self::loadModeFromLibc($context, $pathCstr, true);
        $context->builder->branch($afterLibc);
        $doLstatTail = $context->builder->getInsertBlock();

        $context->builder->positionAtEnd($afterLibc);
        $modePhi = $context->builder->phi($i32);
        $modePhi->addIncoming($modeStat, $doStatTail);
        $modePhi->addIncoming($modeLstat, $doLstatTail);
        self::storeModeEntry($context, $htPhi, $path, $modePhi);
        $context->builder->returnValue($modePhi);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($minusOne);
    }

    private static function resetGlobalHt(Context $context, string $name): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        self::storeGlobalHt($context, $name, $htPtr->constNull());
    }

    private static int $unsetBlockSerial = 0;

    private static function unsetPathFromHt(Context $context, LlvmFunction $fn, string $globalName, Value $pathStr): void
    {
        $tag = (string) (++self::$unsetBlockSerial);
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $ht = self::globalHt($context, $globalName);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $ht, $htPtr->constNull());
        $skip = $fn->appendBasicBlock('stat_cache_unset_skip_'.$globalName.'_'.$tag);
        $run = $fn->appendBasicBlock('stat_cache_unset_run_'.$globalName.'_'.$tag);
        $done = $fn->appendBasicBlock('stat_cache_unset_done_'.$globalName.'_'.$tag);
        $from = $context->builder->getInsertBlock();
        $context->builder->branchIf($isNull, $skip, $run);

        $context->builder->positionAtEnd($run);
        $context->builder->call($context->lookupFunction('__hashtable__unsetStringKey'), $ht, $pathStr);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($skip);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
    }

    private static function emitClear(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $argc = $fn->getParam(0);
        $clearRealpath = $fn->getParam(1);
        $filename = $fn->getParam(2);
        $i32 = $context->getTypeFromString('int32');
        $strPtr = $context->getTypeFromString('__string__*');
        $two = $i32->constInt(2, false);

        $isTwo = $context->builder->icmp(Builder::INT_EQ, $argc, $two);
        $perPath = $fn->appendBasicBlock('clear_per_path');
        $all = $fn->appendBasicBlock('clear_all');
        $context->builder->branchIf($isTwo, $perPath, $all);

        $context->builder->positionAtEnd($all);
        self::resetGlobalHt($context, self::GLOBAL_STAT_MODE_CACHE);
        self::resetGlobalHt($context, self::GLOBAL_LSTAT_MODE_CACHE);
        $doRealpathAll = $fn->appendBasicBlock('clear_realpath_all');
        $doneAll = $fn->appendBasicBlock('clear_done_all');
        $context->builder->branchIf($clearRealpath, $doRealpathAll, $doneAll);
        $context->builder->positionAtEnd($doRealpathAll);
        self::resetGlobalHt($context, self::GLOBAL_REALPATH_CACHE);
        $context->builder->branch($doneAll);
        $context->builder->positionAtEnd($doneAll);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($perPath);
        $nullFile = $context->builder->icmp(Builder::INT_EQ, $filename, $strPtr->constNull());
        $perPathDone = $fn->appendBasicBlock('clear_per_path_done');
        $perPathRun = $fn->appendBasicBlock('clear_per_path_run');
        $context->builder->branchIf($nullFile, $perPathDone, $perPathRun);

        $context->builder->positionAtEnd($perPathRun);
        self::unsetPathFromHt($context, $fn, self::GLOBAL_STAT_MODE_CACHE, $filename);
        self::unsetPathFromHt($context, $fn, self::GLOBAL_LSTAT_MODE_CACHE, $filename);

        $doRealpathPath = $fn->appendBasicBlock('clear_realpath_path');
        $context->builder->branchIf($clearRealpath, $doRealpathPath, $perPathDone);

        $context->builder->positionAtEnd($doRealpathPath);
        $pathCstr = self::stringData($context, $filename);
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $charPtr = $context->getTypeFromString('char*');
        $resolvedSlot = $context->builder->alloca($i8->arrayType(self::REALPATH_BUF_SIZE), 1, 'clear_realpath_buf');
        $resolvedBuf = self::stackBytesPtr($context, $resolvedSlot);
        $resolved = $context->builder->call(
            $context->lookupFunction('realpath'),
            $pathCstr,
            $resolvedBuf
        );
        $resolvedOk = $context->builder->icmp(Builder::INT_NE, $resolved, $charPtr->constNull());
        $resolveOk = $fn->appendBasicBlock('clear_resolved_ok');
        $afterRealpathPath = $fn->appendBasicBlock('clear_after_realpath_path');
        $context->builder->branchIf($resolvedOk, $resolveOk, $afterRealpathPath);

        $context->builder->positionAtEnd($resolveOk);
        $i64 = $context->getTypeFromString('int64');
        $len = $context->builder->call($context->lookupFunction('strlen'), $resolved);
        $resolvedStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zExt($len, $i64),
            $context->builder->pointerCast($resolved, $i8p)
        );
        self::unsetPathFromHt($context, $fn, self::GLOBAL_STAT_MODE_CACHE, $resolvedStr);
        self::unsetPathFromHt($context, $fn, self::GLOBAL_LSTAT_MODE_CACHE, $resolvedStr);
        $context->builder->branch($afterRealpathPath);

        $context->builder->positionAtEnd($afterRealpathPath);
        self::unsetPathFromHt($context, $fn, self::GLOBAL_REALPATH_CACHE, $filename);
        $context->builder->branch($perPathDone);

        $context->builder->positionAtEnd($perPathDone);
        $context->builder->returnVoid();
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null !== $fn) {
                $context->registerFunction($name, $fn);
            }
        }
    }
}
