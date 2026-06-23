<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for stat cache via StatCacheJitHelper PHP (#9110, #9244).
 *
 * Replaces LLVM hashtable stat/realpath cache with compiled {@see VmStatCache} helpers.
 * php-src: ext/standard/filestat.c — php_clear_stat_cache(), php_stat()
 */
final class StatCacheRuntime
{
    private const HELPER_PATH = '/ext/standard/StatCacheJitHelper.php';

    private const MODE_CACHED_HELPER = 'PHPCompiler\\ext\\standard\\StatCacheJitHelper::modeCached';

    private const CLEAR_ALL_HELPER = 'PHPCompiler\\ext\\standard\\StatCacheJitHelper::clearAll';

    private const CLEAR_FLAG_HELPER = 'PHPCompiler\\ext\\standard\\StatCacheJitHelper::clearWithFlag';

    private const CLEAR_PATH_HELPER = 'PHPCompiler\\ext\\standard\\StatCacheJitHelper::clearPath';

    public const FN_CLEAR = '__compiler_clearstatcache';

    public const FN_MODE_CACHED = '__phpc_jit_stat_mode_cached';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::MODE_CACHED_HELPER,
        self::CLEAR_ALL_HELPER,
        self::CLEAR_FLAG_HELPER,
        self::CLEAR_PATH_HELPER,
    ];

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

        self::ensureJitHelperCompiled($context);
        self::implementModeCachedBridge($context);
        self::implementClearBridge($context);
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    private static function implementModeCachedBridge(Context $context): void
    {
        $abiName = self::FN_MODE_CACHED;
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($i32, false, $strPtr, $i32);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('stat_mode_cached_bridge_entry');
        $fail = $fn->appendBasicBlock('stat_mode_cached_bridge_fail');
        $run = $fn->appendBasicBlock('stat_mode_cached_bridge_run');
        $context->builder->positionAtEnd($entry);
        $path = $fn->getParam(0);
        $nullPath = $context->builder->icmp(Builder::INT_EQ, $path, $strPtr->constNull());
        $context->builder->branchIf($nullPath, $fail, $run);

        $context->builder->positionAtEnd($run);
        $useLstat = $fn->getParam(1);
        $i64 = $context->getTypeFromString('int64');
        $useLstatI64 = $useLstat->typeOf() === $i64
            ? $useLstat
            : $context->builder->sext($useLstat, $i64);
        $modeI64 = $context->builder->call(
            self::helperFunction($context, self::MODE_CACHED_HELPER),
            $path,
            $useLstatI64
        );
        $mode = $modeI64->typeOf() === $i32
            ? $modeI64
            : $context->builder->truncOrBitCast($modeI64, $i32);
        $context->builder->returnValue($mode);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($i32->constInt(-1, true));
        $context->registerFunction($abiName, $fn);
    }

    private static function implementClearBridge(Context $context): void
    {
        $abiName = self::FN_CLEAR;
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i32 = $context->getTypeFromString('int32');
        $i1 = $context->getTypeFromString('int1');
        $strPtr = $context->getTypeFromString('__string__*');
        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false, $i32, $i1, $strPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('clearstatcache_bridge_entry');
        $perPath = $fn->appendBasicBlock('clearstatcache_bridge_per_path');
        $flagOnly = $fn->appendBasicBlock('clearstatcache_bridge_flag_only');
        $all = $fn->appendBasicBlock('clearstatcache_bridge_all');
        $context->builder->positionAtEnd($entry);

        $argc = $fn->getParam(0);
        $two = $i32->constInt(2, false);
        $one = $i32->constInt(1, false);
        $isTwo = $context->builder->icmp(Builder::INT_EQ, $argc, $two);
        $isOne = $context->builder->icmp(Builder::INT_EQ, $argc, $one);
        $afterOne = $fn->appendBasicBlock('clearstatcache_bridge_after_one');
        $context->builder->branchIf($isTwo, $perPath, $afterOne);
        $context->builder->positionAtEnd($afterOne);
        $context->builder->branchIf($isOne, $flagOnly, $all);

        $i64 = $context->getTypeFromString('int64');
        $clearRealpathI64 = $context->builder->zext($fn->getParam(1), $i64);

        $context->builder->positionAtEnd($all);
        $context->builder->call(self::helperFunction($context, self::CLEAR_ALL_HELPER));
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($flagOnly);
        $context->builder->call(self::helperFunction($context, self::CLEAR_FLAG_HELPER), $clearRealpathI64);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($perPath);
        $filename = $fn->getParam(2);
        $nullFile = $context->builder->icmp(Builder::INT_EQ, $filename, $strPtr->constNull());
        $perPathDone = $fn->appendBasicBlock('clearstatcache_bridge_per_path_done');
        $perPathRun = $fn->appendBasicBlock('clearstatcache_bridge_per_path_run');
        $context->builder->branchIf($nullFile, $perPathDone, $perPathRun);

        $context->builder->positionAtEnd($perPathRun);
        $context->builder->call(
            self::helperFunction($context, self::CLEAR_PATH_HELPER),
            $clearRealpathI64,
            $filename
        );
        $context->builder->branch($perPathDone);

        $context->builder->positionAtEnd($perPathDone);
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after StatCacheJitHelper compile (#9244)');
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'StatCacheJitHelper.php');
            if (null === $block) {
                throw new \LogicException('StatCacheJitHelper.php parseAndCompile failed (#9244)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#9244)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StatCacheRuntime bridge (#9244)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
