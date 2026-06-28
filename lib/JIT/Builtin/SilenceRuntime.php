<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT `@` silence + error_reporting via ErrorSilenceJitHelper PHP (#9197, #12809).
 *
 * JIT embed and AOT standalone compile {@see \PHPCompiler\ext\standard\ErrorSilenceJitHelper}; thin LLVM bridges
 * forward the ABI. Replaces LLVM globals phpc_ini_silence_* and phpc_ini_error_reporting for silence paths.
 * php-src: Zend/zend_execute.c — ZEND_SILENCE
 */
final class SilenceRuntime
{
    private const HELPER_PATH = '/ext/standard/ErrorSilenceJitHelper.php';

    private const BEGIN_HELPER = 'PHPCompiler\\ext\\standard\\ErrorSilenceJitHelper::beginSilence';

    private const END_HELPER = 'PHPCompiler\\ext\\standard\\ErrorSilenceJitHelper::endSilence';

    private const IS_LEVEL_ENABLED_HELPER = 'PHPCompiler\\ext\\standard\\ErrorSilenceJitHelper::isErrorLevelEnabled';

    private const EXCHANGE_ER_HELPER = 'PHPCompiler\\ext\\standard\\ErrorSilenceJitHelper::errorReportingExchange';

    private const INI_GET_ER_HELPER = 'PHPCompiler\\ext\\standard\\ErrorSilenceJitHelper::iniGetErrorReporting';

    private const SET_ER_HELPER = 'PHPCompiler\\ext\\standard\\ErrorSilenceJitHelper::setErrorReporting';

    private const INI_RESTORE_ER_HELPER = 'PHPCompiler\\ext\\standard\\ErrorSilenceJitHelper::iniRestoreErrorReporting';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::BEGIN_HELPER,
        self::END_HELPER,
        self::IS_LEVEL_ENABLED_HELPER,
        self::EXCHANGE_ER_HELPER,
        self::INI_GET_ER_HELPER,
        self::SET_ER_HELPER,
        self::INI_RESTORE_ER_HELPER,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_begin_silence',
        '__compiler_end_silence',
        '__compiler_phpc_error_level_enabled',
        '__compiler_error_reporting',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_begin_silence');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureValueWriters($context);
        StreamLifecycleRuntime::ensureDeferredStubsForInventoryEmit($context);
        self::ensureJitHelperCompiled($context);
        self::implementVoidBridge($context, '__compiler_begin_silence', self::BEGIN_HELPER);
        self::implementVoidBridge($context, '__compiler_end_silence', self::END_HELPER);
        self::implementErrorLevelEnabledBridge($context);
        self::implementErrorReportingBridge($context);
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    public static function emitIniGetErrorReporting(Context $context, Value $out): void
    {
        $restoreBlock = self::captureInsertBlock($context);
        self::ensureJitHelperCompiled($context);
        self::restoreInsertBlock($context, $restoreBlock);
        self::ensureValueWriters($context);
        $erStr = $context->builder->call(self::helperFunction($context, self::INI_GET_ER_HELPER));
        $context->builder->call($context->lookupFunction('__value__writeString'), $out, $erStr);
    }

    public static function emitSetErrorReporting(Context $context, Value $level): void
    {
        $restoreBlock = self::captureInsertBlock($context);
        self::ensureJitHelperCompiled($context);
        self::restoreInsertBlock($context, $restoreBlock);
        $i64 = $context->getTypeFromString('int64');
        $context->builder->call(
            self::helperFunction($context, self::SET_ER_HELPER),
            $context->builder->sext($level, $i64)
        );
    }

    public static function emitIniRestoreErrorReporting(Context $context): void
    {
        $restoreBlock = self::captureInsertBlock($context);
        self::ensureJitHelperCompiled($context);
        self::restoreInsertBlock($context, $restoreBlock);
        $context->builder->call(self::helperFunction($context, self::INI_RESTORE_ER_HELPER));
    }

    private static function implementErrorLevelEnabledBridge(Context $context): void
    {
        $abiName = '__compiler_phpc_error_level_enabled';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($i32, false, $i32);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('isel_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $enabled = $context->builder->call(
            self::helperFunction($context, self::IS_LEVEL_ENABLED_HELPER),
            $context->builder->sext($fn->getParam(0), $i64)
        );
        $context->builder->returnValue($context->builder->zext($enabled, $i32));
        $context->registerFunction($abiName, $fn);
    }

    private static function implementErrorReportingBridge(Context $context): void
    {
        $abiName = '__compiler_error_reporting';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $valPtr = $context->getTypeFromString('__value__*');
        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false, $i32, $i64, $valPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('ier_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $hasNew = $fn->getParam(0);
        $newLevel = $fn->getParam(1);
        $out = $fn->getParam(2);
        $hasNewBool = $context->builder->icmp(Builder::INT_NE, $hasNew, $i32->constInt(0, false));
        $old = $context->builder->call(
            self::helperFunction($context, self::EXCHANGE_ER_HELPER),
            $hasNewBool,
            $context->builder->sext($context->builder->trunc($newLevel, $i32), $i64)
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $out,
            $context->builder->sext($old, $i64)
        );
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function implementVoidBridge(Context $context, string $abiName, string $helperLogical): void
    {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('silence_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $context->builder->call(self::helperFunction($context, $helperLogical));
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after ErrorSilenceJitHelper compile (#9197)');
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
        $savedBuilder = $context->builder;
        $savedActive = $context->activeFunction;
        $restoreBlock = self::captureInsertBlock($context);
        $prevSelfHostAot = \getenv('PHP_COMPILER_SELFHOST_AOT');
        if (\function_exists('putenv')) {
            \putenv('PHP_COMPILER_SELFHOST_AOT=0');
        }
        try {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'ErrorSilenceJitHelper.php');
            if (null === $block) {
                throw new \LogicException('ErrorSilenceJitHelper.php parseAndCompile failed (#9197)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        } finally {
            $context->builder = $savedBuilder;
            self::restoreInsertBlock($context, $restoreBlock);
            $context->activeFunction = $savedActive;
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
                throw new \LogicException($lc.' was not compiled for JIT (#9197)');
            }
        }
    }

    /** Standalone AOT + embed ini emit paths need value writers declared (#9197). */
    public static function ensureValueWriters(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $valPtr = $context->getTypeFromString('__value__*');
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $voidTy = $context->getTypeFromString('void');
        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');

        self::ensureExternal(
            $context,
            '__string__init',
            $context->context->functionType($strPtr, false, $i64, $i8p)
        );
        self::ensureExternal(
            $context,
            '__value__writeString',
            $context->context->functionType($voidTy, false, $valPtr, $strPtr)
        );
        self::ensureExternal(
            $context,
            '__value__writeLong',
            $context->context->functionType($voidTy, false, $valPtr, $i64)
        );
        self::ensureExternal(
            $context,
            '__value__writeBool',
            $context->context->functionType($voidTy, false, $valPtr, $i32)
        );
        self::ensureExternal($context, 'strlen', $context->context->functionType($sizeT, false, $i8p));
        self::ensureExternal($context, 'free', $context->context->functionType($voidTy, false, $i8p));
    }

    private static function ensureExternal(Context $context, string $name, $ft): void
    {
        try {
            $context->lookupFunction($name);
        } catch (\Throwable $e) {
            $fn = $context->module->addFunction($name, $ft);
            $context->registerFunction($name, $fn);
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after SilenceRuntime bridge (#9197)');
            }
            $context->registerFunction($name, $fn);
        }
    }

    private static function captureInsertBlock(Context $context): ?BasicBlock
    {
        try {
            return $context->builder->getInsertBlock();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function restoreInsertBlock(Context $context, ?BasicBlock $block): void
    {
        if (null !== $block) {
            $context->builder->positionAtEnd($block);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
