<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_gc_collect_cycles via GcCollectCyclesJitHelper PHP (#9183).
 *
 * Standalone AOT uses {@see GcCollectCyclesStandaloneJitHelper} to avoid Superglobals in nested JIT (#18630).
 * php-src: ext/standard/info.c — PHP_FUNCTION(gc_collect_cycles)
 */
final class GcCollectCyclesCollectRuntime
{
    private const EMBED_HELPER_PATH = '/ext/standard/GcCollectCyclesJitHelper.php';

    private const STANDALONE_SCAN_PATH = '/ext/standard/GcCollectCyclesNativeScanJitHelper.php';

    private const STANDALONE_HELPER_PATH = '/ext/standard/GcCollectCyclesStandaloneJitHelper.php';

    public static function ensureCollectHelperCompiled(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function implementCollectBridge(Context $context): void
    {
        $abiName = '__compiler_gc_collect_cycles';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($i64, false);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('gc_collect_bridge_entry');
        $early = $fn->appendBasicBlock('gc_collect_early');
        $work = $fn->appendBasicBlock('gc_collect_work');
        $done = $fn->appendBasicBlock('gc_collect_done');
        $context->builder->positionAtEnd($entry);

        $enabled = $context->builder->call($context->lookupFunction('phpc_gc_is_enabled'));
        $isOff = $context->builder->icmp(Builder::INT_EQ, $enabled, $i32->constInt(0, false));
        $context->builder->branchIf($isOff, $early, $work);

        $context->builder->positionAtEnd($early);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($work);
        $implResult = $context->builder->call($context->lookupFunction('phpc_gc_collect_cycles_impl'));
        self::ensureJitHelperCompiled($context);
        $collected = $context->builder->call(
            self::helperFunction($context, 'recordNativeCollect'),
            $context->builder->sext($implResult, $i64)
        );
        self::syncGlobalsFromHelper($context);
        $resultI64 = $context->builder->sextOrBitCast($collected, $i64);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $zero = $i64->constInt(0, false);
        $retPhi = $context->builder->phi($i64);
        $retPhi->addIncoming($zero, $early);
        $retPhi->addIncoming($resultI64, $work);
        $context->builder->returnValue($retPhi);
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function syncGlobalsFromHelper(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');

        self::storeGlobalInt(
            $context,
            GcStatusRuntime::G_RUNS,
            self::helperFunction($context, 'runs')
        );
        self::storeGlobalInt(
            $context,
            GcStatusRuntime::G_TOTAL_COLLECTED,
            self::helperFunction($context, 'totalCollected')
        );
        self::storeGlobalBool(
            $context,
            GcStatusRuntime::G_RUNNING,
            self::helperFunction($context, 'isRunning'),
            $i32
        );
        self::storeGlobalBool(
            $context,
            GcStatusRuntime::G_PROTECTED,
            self::helperFunction($context, 'isProtected'),
            $i32
        );
    }

    private static function storeGlobalInt(Context $context, string $globalName, LlvmFunction $getter): void
    {
        $i32 = $context->getTypeFromString('int32');
        $value = $context->builder->call($getter);
        $stored = $context->builder->trunc($value, $i32);
        $global = $context->module->getNamedGlobal($globalName);
        if (null === $global) {
            throw new \LogicException('GcCollectCyclesCollectRuntime: '.$globalName.' missing');
        }
        $context->builder->store($stored, $context->builder->pointerCast($global, $i32->pointerType(0)));
    }

    private static function storeGlobalBool(Context $context, string $globalName, LlvmFunction $getter, $i32): void
    {
        $flag = $context->builder->call($getter);
        $stored = $context->builder->zext($flag, $i32);
        $global = $context->module->getNamedGlobal($globalName);
        if (null === $global) {
            throw new \LogicException('GcCollectCyclesCollectRuntime: '.$globalName.' missing');
        }
        $context->builder->store($stored, $context->builder->pointerCast($global, $i32->pointerType(0)));
    }

    private static function helperClass(Context $context): string
    {
        return Builtin::LOAD_TYPE_STANDALONE === $context->loadType
            ? 'PHPCompiler\\ext\\standard\\GcCollectCyclesStandaloneJitHelper'
            : 'PHPCompiler\\ext\\standard\\GcCollectCyclesJitHelper';
    }

    private static function helperFunction(Context $context, string $method): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $logical = self::helperClass($context).'::'.$method;
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after GC collect helper compile (#18630)');
        }

        return $fn;
    }

    /** @return list<string> */
    private static function compiledHelperMethods(Context $context): array
    {
        $methods = ['recordNativeCollect', 'runs', 'totalCollected', 'isRunning', 'isProtected'];
        $methods[] = Builtin::LOAD_TYPE_STANDALONE === $context->loadType
            ? 'collectCyclesStandalone'
            : 'collectCyclesEmbed';

        return $methods;
    }

  /** @return list<array{0: string, 1: string}> path + compile label */
    private static function helperCompileUnits(Context $context): array
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            return [
                [self::STANDALONE_SCAN_PATH, 'GcCollectCyclesNativeScanJitHelper.php'],
                [self::STANDALONE_HELPER_PATH, 'GcCollectCyclesStandaloneJitHelper.php'],
            ];
        }

        return [[self::EMBED_HELPER_PATH, 'GcCollectCyclesJitHelper.php']];
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        $class = self::helperClass($context);
        $missing = false;
        foreach (self::compiledHelperMethods($context) as $method) {
            if (!isset($context->functions[\strtolower($class.'::'.$method)])) {
                $missing = true;
                break;
            }
        }
        if (!$missing) {
            return;
        }

        $runtime = $context->runtime;
        $root = \dirname(__DIR__, 3);
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $root): void {
            foreach (self::helperCompileUnits($context) as [$relPath, $label]) {
                $path = $root.$relPath;
                $block = $runtime->parseAndCompile((string) \file_get_contents($path), $label);
                if (null === $block) {
                    throw new \LogicException($label.' parseAndCompile failed (#18630)');
                }
                $jit = new JIT($context);
                $jit->compile($block);
            }
        });
        foreach (self::compiledHelperMethods($context) as $method) {
            $lc = \strtolower($class.'::'.$method);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#18630)');
            }
        }
    }
}
