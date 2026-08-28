<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT `@` silence + error_reporting via ErrorSilenceJitHelper PHP (#9197, #12809, #22751, #32779).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer MathModf #22519).
 * JIT embed and AOT standalone compile {@see \PHPCompiler\ext\standard\ErrorSilenceJitHelper}; thin LLVM bridges
 * forward the ABI. Thin standalone AOT keeps error_reporting in module globals with a baked E_ALL_LEGACY
 * initializer — NestedJIT PHP statics are BSS-zero and writes do not persist (#35563).
 * Owns begin/end silence + error_reporting ABI module-locally (getNamedFunction first)
 * after Type always-on shells dropped (#32779 / #32122 name.1 class).
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

    private const G_ERROR_REPORTING = 'phpc_aot_error_reporting';

    private const G_SAVED_ERROR_REPORTING = 'phpc_aot_saved_error_reporting';

    private const G_SILENCE_DEPTH = 'phpc_aot_silence_depth';

    private const ISEL_BRIDGE_ENTRY = 'isel_global_bridge_entry';

    private const BEGIN_BRIDGE_ENTRY = 'silence_begin_global_entry';

    private const END_BRIDGE_ENTRY = 'silence_end_global_entry';

    private const IER_BRIDGE_ENTRY = 'ier_global_bridge_entry';

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
        self::ensureGlobals($context);
        $probe = $context->module->getNamedFunction('__compiler_begin_silence');
        if (null !== $probe && JitVmHelperLink::hasNamedBridgeEntry($probe, self::BEGIN_BRIDGE_ENTRY)) {
            // Prior NestedJIT ensureLinked may have built silence bridges while
            // StreamLifecycle no-op'd under NestedJitCompileScope (#35392 / #33248 O=0).
            StreamLifecycleRuntime::ensureLinked($context);
            self::ensureJitHelperCompiled($context);
            self::implementErrorLevelEnabledBridge($context);
            self::implementErrorReportingBridge($context);
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureValueWriters($context);
        StreamLifecycleRuntime::ensureLinked($context);
        self::ensureJitHelperCompiled($context);
        self::implementBeginSilenceBridge($context);
        self::implementEndSilenceBridge($context);
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
        self::ensureGlobals($context);
        self::restoreInsertBlock($context, $restoreBlock);
        $i64 = $context->getTypeFromString('int64');
        $context->builder->store(
            $context->builder->sext($level, $i64),
            self::globalPtr($context, self::G_ERROR_REPORTING, $i64)
        );
    }

    public static function emitIniRestoreErrorReporting(Context $context): void
    {
        $restoreBlock = self::captureInsertBlock($context);
        self::ensureGlobals($context);
        self::restoreInsertBlock($context, $restoreBlock);
        $i64 = $context->getTypeFromString('int64');
        $context->builder->store(
            $i64->constInt(ErrorReporter::E_ALL_LEGACY, false),
            self::globalPtr($context, self::G_ERROR_REPORTING, $i64)
        );
    }

    private static function implementErrorLevelEnabledBridge(Context $context): void
    {
        $abiName = '__compiler_phpc_error_level_enabled';
        $probe = $context->module->getNamedFunction($abiName);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::ISEL_BRIDGE_ENTRY)) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($i32, false, $i32);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, self::ISEL_BRIDGE_ENTRY);
        $context->builder->positionAtEnd($entry);
        self::ensureGlobals($context);
        $levelI64 = $context->builder->sext($fn->getParam(0), $i64);
        $mask = $context->builder->load(self::globalPtr($context, self::G_ERROR_REPORTING, $i64));
        $masked = $context->builder->and($mask, $levelI64);
        $enabled = $context->builder->icmp(
            Builder::INT_NE,
            $masked,
            $i64->constInt(0, false)
        );
        $context->builder->returnValue($context->builder->zext($enabled, $i32));
        $context->registerFunction($abiName, $fn);
    }

    private static function implementErrorReportingBridge(Context $context): void
    {
        $abiName = '__compiler_error_reporting';
        $probe = $context->module->getNamedFunction($abiName);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::IER_BRIDGE_ENTRY)) {
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

        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, self::IER_BRIDGE_ENTRY);
        $context->builder->positionAtEnd($entry);
        self::ensureGlobals($context);
        $hasNew = $fn->getParam(0);
        $newLevel = $fn->getParam(1);
        $out = $fn->getParam(2);
        $erPtr = self::globalPtr($context, self::G_ERROR_REPORTING, $i64);
        $old = $context->builder->load($erPtr);
        $hasNewBool = $context->builder->icmp(Builder::INT_NE, $hasNew, $i32->constInt(0, false));
        $skipBb = $fn->appendBasicBlock('ier_global_skip');
        $storeBb = $fn->appendBasicBlock('ier_global_store');
        $retBb = $fn->appendBasicBlock('ier_global_ret');
        $context->builder->branchIf($hasNewBool, $storeBb, $skipBb);
        $context->builder->positionAtEnd($storeBb);
        $context->builder->store(
            $context->builder->sext($context->builder->trunc($newLevel, $i32), $i64),
            $erPtr
        );
        $context->builder->branch($retBb);
        $context->builder->positionAtEnd($skipBb);
        $context->builder->branch($retBb);
        $context->builder->positionAtEnd($retBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $out,
            $context->builder->sext($old, $i64)
        );
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function implementBeginSilenceBridge(Context $context): void
    {
        $abiName = '__compiler_begin_silence';
        $probe = $context->module->getNamedFunction($abiName);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BEGIN_BRIDGE_ENTRY)) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $zeroI32 = $i32->constInt(0, false);
        $oneI32 = $i32->constInt(1, false);
        $zeroI64 = $i64->constInt(0, false);

        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, self::BEGIN_BRIDGE_ENTRY);
        $context->builder->positionAtEnd($entry);
        self::ensureGlobals($context);
        $depthPtr = self::globalPtr($context, self::G_SILENCE_DEPTH, $i32);
        $depth = $context->builder->load($depthPtr);
        $isZero = $context->builder->icmp(Builder::INT_EQ, $depth, $zeroI32);
        $incBb = $fn->appendBasicBlock('silence_begin_inc');
        $saveBb = $fn->appendBasicBlock('silence_begin_save');
        $retBb = $fn->appendBasicBlock('silence_begin_ret');
        $context->builder->branchIf($isZero, $saveBb, $incBb);
        $context->builder->positionAtEnd($saveBb);
        $erPtr = self::globalPtr($context, self::G_ERROR_REPORTING, $i64);
        $context->builder->store(
            $context->builder->load($erPtr),
            self::globalPtr($context, self::G_SAVED_ERROR_REPORTING, $i64)
        );
        $context->builder->store($zeroI64, $erPtr);
        $context->builder->branch($incBb);
        $context->builder->positionAtEnd($incBb);
        $context->builder->store(
            $context->builder->add($depth, $oneI32),
            $depthPtr
        );
        $context->builder->branch($retBb);
        $context->builder->positionAtEnd($retBb);
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function implementEndSilenceBridge(Context $context): void
    {
        $abiName = '__compiler_end_silence';
        $probe = $context->module->getNamedFunction($abiName);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::END_BRIDGE_ENTRY)) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $zeroI32 = $i32->constInt(0, false);
        $oneI32 = $i32->constInt(1, false);

        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, self::END_BRIDGE_ENTRY);
        $context->builder->positionAtEnd($entry);
        self::ensureGlobals($context);
        $depthPtr = self::globalPtr($context, self::G_SILENCE_DEPTH, $i32);
        $depth = $context->builder->load($depthPtr);
        $isZero = $context->builder->icmp(Builder::INT_EQ, $depth, $zeroI32);
        $retBb = $fn->appendBasicBlock('silence_end_ret');
        $decBb = $fn->appendBasicBlock('silence_end_dec');
        $context->builder->branchIf($isZero, $retBb, $decBb);
        $context->builder->positionAtEnd($decBb);
        $newDepth = $context->builder->sub($depth, $oneI32);
        $context->builder->store($newDepth, $depthPtr);
        $restoreBb = $fn->appendBasicBlock('silence_end_restore');
        $doneBb = $fn->appendBasicBlock('silence_end_done');
        $isStillZero = $context->builder->icmp(Builder::INT_EQ, $newDepth, $zeroI32);
        $context->builder->branchIf($isStillZero, $restoreBb, $doneBb);
        $context->builder->positionAtEnd($restoreBb);
        $context->builder->store(
            $context->builder->load(self::globalPtr($context, self::G_SAVED_ERROR_REPORTING, $i64)),
            self::globalPtr($context, self::G_ERROR_REPORTING, $i64)
        );
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($doneBb);
        $context->builder->branch($retBb);
        $context->builder->positionAtEnd($retBb);
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function ensureGlobals(Context $context): void
    {
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');

        if (null === $context->module->getNamedGlobal(self::G_ERROR_REPORTING)) {
            $g = $context->module->addGlobal($i64, self::G_ERROR_REPORTING);
            $g->setInitializer($i64->constInt(ErrorReporter::E_ALL_LEGACY, false));
        }
        if (null === $context->module->getNamedGlobal(self::G_SAVED_ERROR_REPORTING)) {
            $g = $context->module->addGlobal($i64, self::G_SAVED_ERROR_REPORTING);
            $g->setInitializer($i64->constInt(0, false));
        }
        if (null === $context->module->getNamedGlobal(self::G_SILENCE_DEPTH)) {
            $g = $context->module->addGlobal($i32, self::G_SILENCE_DEPTH);
            $g->setInitializer($i32->constInt(0, false));
        }
    }

    private static function globalPtr(Context $context, string $name, $llvmType): Value
    {
        $global = $context->module->getNamedGlobal($name);
        if (null === $global) {
            throw new \LogicException('SilenceRuntime global missing: '.$name);
        }

        return $context->builder->pointerCast($global, $llvmType->pointerType(0));
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#22751');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#22751'
        );
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
        \PHPCompiler\JIT\LibcExtern::ensureExternalDecl($context, $name, $ft);
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
