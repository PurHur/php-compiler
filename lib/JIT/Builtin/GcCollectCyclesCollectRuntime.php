<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_gc_collect_cycles via GcCollectCyclesJitHelper PHP (#9183).
 *
 * Native cycle scan remains in {@see GcCollectCyclesRuntime}; stats bookkeeping lives in PHP.
 * php-src: ext/standard/info.c — PHP_FUNCTION(gc_collect_cycles)
 */
final class GcCollectCyclesCollectRuntime
{
    private const HELPER_PATH = '/ext/standard/GcCollectCyclesJitHelper.php';

    private const RECORD_COLLECT = 'PHPCompiler\\ext\\standard\\GcCollectCyclesJitHelper::recordNativeCollect';

    private const RUNS = 'PHPCompiler\\ext\\standard\\GcCollectCyclesJitHelper::runs';

    private const TOTAL_COLLECTED = 'PHPCompiler\\ext\\standard\\GcCollectCyclesJitHelper::totalCollected';

    private const IS_RUNNING = 'PHPCompiler\\ext\\standard\\GcCollectCyclesJitHelper::isRunning';

    private const IS_PROTECTED = 'PHPCompiler\\ext\\standard\\GcCollectCyclesJitHelper::isProtected';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::RECORD_COLLECT,
        self::RUNS,
        self::TOTAL_COLLECTED,
        self::IS_RUNNING,
        self::IS_PROTECTED,
    ];

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

        self::ensureJitHelperCompiled($context);

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
        $collected = $context->builder->call(
            self::helperFunction($context, self::RECORD_COLLECT),
            $implResult
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
            self::helperFunction($context, self::RUNS)
        );
        self::storeGlobalInt(
            $context,
            GcStatusRuntime::G_TOTAL_COLLECTED,
            self::helperFunction($context, self::TOTAL_COLLECTED)
        );
        self::storeGlobalBool(
            $context,
            GcStatusRuntime::G_RUNNING,
            self::helperFunction($context, self::IS_RUNNING),
            $i32
        );
        self::storeGlobalBool(
            $context,
            GcStatusRuntime::G_PROTECTED,
            self::helperFunction($context, self::IS_PROTECTED),
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

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after GcCollectCyclesJitHelper compile (#9183)');
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'GcCollectCyclesJitHelper.php');
            if (null === $block) {
                throw new \LogicException('GcCollectCyclesJitHelper.php parseAndCompile failed (#9183)');
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
                throw new \LogicException($lc.' was not compiled for JIT (#9183)');
            }
        }
    }
}
