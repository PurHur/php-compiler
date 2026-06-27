<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\CompilerVersion;
use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\VM\CycleCollector;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for gc_status() via GcStatusJitHelper PHP (#9150).
 *
 * VM SSOT: {@see \PHPCompiler\ext\standard\VmGcStatus}
 * php-src: ext/standard/php_gc.c — PHP_FUNCTION(gc_status)
 */
final class GcStatusRuntime
{
    public const G_RUNS = 'phpc_gc_runs';

    public const G_TOTAL_COLLECTED = 'phpc_gc_total_collected';

    public const G_ROOT_COUNT = 'phpc_gc_count';

    public const G_RUNNING = 'phpc_gc_running';

    public const G_PROTECTED = 'phpc_gc_protected';

    public const G_FULL = 'phpc_gc_full';

    public const G_BUFFER_SIZE = 'phpc_gc_buffer_size';

    private const HELPER_PATH = '/ext/standard/GcStatusJitHelper.php';

    private const BUILD_TABLE = 'PHPCompiler\\ext\\standard\\GcStatusJitHelper::buildTable';

    private const BUILD_LEGACY_TABLE = 'PHPCompiler\\ext\\standard\\GcStatusJitHelper::buildLegacyTable';

    private const FN_STATUS = '__phpc_gc_status_ht';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::BUILD_TABLE,
        self::BUILD_LEGACY_TABLE,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::FN_STATUS);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        GcCollectCyclesRuntime::ensureLinked($context);
        self::ensureJitHelperCompiled($context);
        self::registerDeclarations($context);
        self::implementStatusBridge($context);
        self::registerLinkedRuntime($context);
    }

    private static function registerDeclarations(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        self::declareIfMissing(
            $context,
            self::FN_STATUS,
            $context->context->functionType($htPtr, false)
        );
    }

    private static function declareIfMissing(Context $context, string $name, $ft): void
    {
        if (null !== $context->module->getNamedFunction($name)) {
            return;
        }
        $fn = $context->module->addFunction($name, $ft);
        $context->registerFunction($name, $fn);
    }

    private static function implementStatusBridge(Context $context): void
    {
        $abiName = self::FN_STATUS;
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($htPtr, false)
            );

        $entry = $fn->appendBasicBlock('gc_status_bridge_entry');
        $context->builder->positionAtEnd($entry);

        if (CompilerVersion::supportsGcStatusPhp84Schema()) {
            self::emitPhp84StatusBridge($context, $fn);
        } else {
            self::emitLegacyStatusBridge($context, $fn);
        }

        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function emitPhp84StatusBridge(Context $context, LlvmFunction $fn): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');

        $running = self::loadGlobalBool($context, self::G_RUNNING, $i32, $i1);
        $protected = self::loadGlobalBool($context, self::G_PROTECTED, $i32, $i1);
        $full = self::loadGlobalBool($context, self::G_FULL, $i32, $i1);
        $bufferSize = self::loadGlobalInt($context, self::G_BUFFER_SIZE, $i32, $i64);

        $ht = $context->builder->call(
            self::helperFunction($context, self::BUILD_TABLE),
            $running,
            $protected,
            $full,
            $bufferSize
        );
        $context->builder->returnValue($ht);
    }

    private static function emitLegacyStatusBridge(Context $context, LlvmFunction $fn): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');

        $runs = self::loadGlobalInt($context, self::G_RUNS, $i32, $i64);
        $collected = self::loadGlobalInt($context, self::G_TOTAL_COLLECTED, $i32, $i64);
        $roots = self::loadGlobalInt($context, self::G_ROOT_COUNT, $i32, $i64);
        $threshold = $i64->constInt(CycleCollector::ROOT_THRESHOLD, false);

        $ht = $context->builder->call(
            self::helperFunction($context, self::BUILD_LEGACY_TABLE),
            $runs,
            $collected,
            $threshold,
            $roots
        );
        $context->builder->returnValue($ht);
    }

    private static function loadGlobalInt(Context $context, string $globalName, $i32, $i64): Value
    {
        $global = $context->module->getNamedGlobal($globalName);
        if (null === $global) {
            throw new \LogicException('GcStatusRuntime: '.$globalName.' missing');
        }
        $loaded = $context->builder->load($context->builder->pointerCast($global, $i32->pointerType(0)));

        return $context->builder->sext($loaded, $i64);
    }

    private static function loadGlobalBool(Context $context, string $globalName, $i32, $i1): Value
    {
        $global = $context->module->getNamedGlobal($globalName);
        if (null === $global) {
            throw new \LogicException('GcStatusRuntime: '.$globalName.' missing');
        }
        $loaded = $context->builder->load($context->builder->pointerCast($global, $i32->pointerType(0)));

        return $context->builder->icmp(
            \PHPLLVM\Builder::INT_NE,
            $loaded,
            $i32->constInt(0, false)
        );
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after GcStatusJitHelper compile (#9150)');
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
        $prevSelfHostAot = \getenv('PHP_COMPILER_SELFHOST_AOT');
        if (\function_exists('putenv')) {
            \putenv('PHP_COMPILER_SELFHOST_AOT=0');
        }
        try {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'GcStatusJitHelper.php');
            if (null === $block) {
                throw new \LogicException('GcStatusJitHelper.php parseAndCompile failed (#9150)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        } finally {
            if (\function_exists('putenv')) {
                if (false === $prevSelfHostAot || null === $prevSelfHostAot) {
                    \putenv('PHP_COMPILER_SELFHOST_AOT=');
                } else {
                    \putenv('PHP_COMPILER_SELFHOST_AOT='.$prevSelfHostAot);
                }
            }
        }
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#9150)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction(self::FN_STATUS);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::FN_STATUS.' missing after GcStatusRuntime bridge (#9150)');
        }
        $context->registerFunction(self::FN_STATUS, $fn);
    }
}
