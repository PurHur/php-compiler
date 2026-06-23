<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for memory introspection via MemoryJitHelper PHP (#9377).
 *
 * Replaces RSS/statm LLVM + emalloc globals; SSOT {@see \PHPCompiler\ext\standard\MemoryJitHelper}.
 * php-src: ext/standard/basic_functions.c, ext/standard/php_gc.c
 */
final class MemoryRuntime
{
    public const NOTE_ALLOC = '__phpc_memory_note_alloc';

    public const GC_MEM_CACHES = '__phpc_gc_mem_caches';

    private const GET_USAGE = '__phpc_memory_get_usage';

    private const GET_PEAK_USAGE = '__phpc_memory_get_peak_usage';

    private const RESET_PEAK_USAGE = '__phpc_memory_reset_peak_usage';

    private const HELPER_PATH = '/ext/standard/MemoryJitHelper.php';

    private const GET_USAGE_HELPER = 'PHPCompiler\\ext\\standard\\MemoryJitHelper::getUsage';

    private const GET_PEAK_USAGE_HELPER = 'PHPCompiler\\ext\\standard\\MemoryJitHelper::getPeakUsage';

    private const RESET_PEAK_USAGE_HELPER = 'PHPCompiler\\ext\\standard\\MemoryJitHelper::resetPeakUsage';

    private const NOTE_ALLOC_HELPER = 'PHPCompiler\\ext\\standard\\MemoryJitHelper::noteAlloc';

    private const GC_MEM_CACHES_HELPER = 'PHPCompiler\\ext\\standard\\MemoryJitHelper::gcMemCaches';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::GET_USAGE_HELPER,
        self::GET_PEAK_USAGE_HELPER,
        self::RESET_PEAK_USAGE_HELPER,
        self::NOTE_ALLOC_HELPER,
        self::GC_MEM_CACHES_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function getUsageValue(Context $context, Value $realUsage): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::GET_USAGE),
            $realUsage
        );
    }

    public static function getPeakUsageValue(Context $context, Value $realUsage): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::GET_PEAK_USAGE),
            $realUsage
        );
    }

    public static function resetPeakUsage(Context $context, Value $realUsage): void
    {
        self::ensureLinked($context);
        $context->builder->call(
            $context->lookupFunction(self::RESET_PEAK_USAGE),
            $realUsage
        );
    }

    public static function noteAlloc(Context $context, Value $delta): void
    {
        self::ensureLinked($context);
        $context->builder->call(
            $context->lookupFunction(self::NOTE_ALLOC),
            $delta
        );
    }

    public static function gcMemCaches(Context $context): Value
    {
        self::ensureLinked($context);

        return $context->builder->call($context->lookupFunction(self::GC_MEM_CACHES));
    }

    private static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::GET_USAGE);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureJitHelperCompiled($context);
        self::implementBoolToI64Bridge($context, self::GET_USAGE, self::GET_USAGE_HELPER);
        self::implementBoolToI64Bridge($context, self::GET_PEAK_USAGE, self::GET_PEAK_USAGE_HELPER);
        self::implementBoolVoidBridge($context, self::RESET_PEAK_USAGE, self::RESET_PEAK_USAGE_HELPER);
        self::implementI64VoidBridge($context, self::NOTE_ALLOC, self::NOTE_ALLOC_HELPER);
        self::implementZeroArgI64Bridge($context, self::GC_MEM_CACHES, self::GC_MEM_CACHES_HELPER);
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    private static function implementBoolToI64Bridge(
        Context $context,
        string $abiName,
        string $helperLogical
    ): void {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i1 = $context->getTypeFromString('int1');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($i64, false, $i1);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('memory_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            self::helperFunction($context, $helperLogical),
            $fn->getParam(0)
        );
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
    }

    private static function implementBoolVoidBridge(
        Context $context,
        string $abiName,
        string $helperLogical
    ): void {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i1 = $context->getTypeFromString('int1');
        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false, $i1);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('memory_void_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $context->builder->call(self::helperFunction($context, $helperLogical), $fn->getParam(0));
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function implementI64VoidBridge(
        Context $context,
        string $abiName,
        string $helperLogical
    ): void {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i64 = $context->getTypeFromString('int64');
        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false, $i64);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('memory_note_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $context->builder->call(self::helperFunction($context, $helperLogical), $fn->getParam(0));
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function implementZeroArgI64Bridge(
        Context $context,
        string $abiName,
        string $helperLogical
    ): void {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($i64, false);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('memory_gc_caches_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(self::helperFunction($context, $helperLogical));
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after MemoryJitHelper compile (#9377)');
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'MemoryJitHelper.php');
            if (null === $block) {
                throw new \LogicException('MemoryJitHelper.php parseAndCompile failed (#9377)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#9377)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach ([self::GET_USAGE, self::GET_PEAK_USAGE, self::RESET_PEAK_USAGE, self::NOTE_ALLOC, self::GC_MEM_CACHES] as $abi) {
            $fn = $context->module->getNamedFunction($abi);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($abi.' missing after MemoryRuntime bridge (#9377)');
            }
            $context->registerFunction($abi, $fn);
        }
    }
}
