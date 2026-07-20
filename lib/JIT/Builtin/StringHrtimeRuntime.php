<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\CompilerVersion;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_hrtime_ns / __compiler_hrtime_pair via HrtimeJitHelper (#9182).
 *
 * Nested helper compile: {@see JitVmHelperLink::ensureCompiled} (HelperRuntimeCache + user-script
 * env clear — no hand-rolled NestedJit putenv). Peer: {@see StringClockGettimeRuntime} (#21270).
 */
final class StringHrtimeRuntime
{
    private const HELPER_PATH = '/ext/standard/HrtimeJitHelper.php';

    private const PAIR_HELPER = 'PHPCompiler\\ext\\standard\\HrtimeJitHelper::pair';

    private const NS_FLOAT_HELPER = 'PHPCompiler\\ext\\standard\\HrtimeJitHelper::nsFloat';

    private const NS_INT_HELPER = 'PHPCompiler\\ext\\standard\\HrtimeJitHelper::nsInt';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::PAIR_HELPER,
        self::NS_FLOAT_HELPER,
        self::NS_INT_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implementNsBridge($context);
        self::implementPairBridge($context);
    }

    private static function implementPairBridge(Context $context): void
    {
        self::implementZeroArgBridge($context, '__compiler_hrtime_pair', self::PAIR_HELPER);
    }

    private static function implementNsBridge(Context $context): void
    {
        $abiName = '__compiler_hrtime_ns';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        self::ensureJitHelperCompiled($context);

        if (CompilerVersion::supportsHrtimeAsNumberFloat()) {
            $doubleTy = $context->getTypeFromString('double');
            $fn = null !== $probe
                ? $probe
                : $context->module->addFunction(
                    $abiName,
                    $context->context->functionType($doubleTy, false)
                );
            $entry = $fn->appendBasicBlock('hrtime_ns_entry');
            $context->builder->positionAtEnd($entry);
            $result = $context->builder->call(self::helperFunction($context, self::NS_FLOAT_HELPER));
        } else {
            $i64 = $context->getTypeFromString('int64');
            $fn = null !== $probe
                ? $probe
                : $context->module->addFunction(
                    $abiName,
                    $context->context->functionType($i64, false)
                );
            $entry = $fn->appendBasicBlock('hrtime_ns_entry');
            $context->builder->positionAtEnd($entry);
            $result = $context->builder->call(self::helperFunction($context, self::NS_INT_HELPER));
        }
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementZeroArgBridge(
        Context $context,
        string $abiName,
        string $helperLogical
    ): void {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        self::ensureJitHelperCompiled($context);

        $fn = null !== $probe
            ? $probe
            : $context->lookupFunction($abiName);

        $entry = $fn->appendBasicBlock('hrtime_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $resultRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, $helperLogical),
            []
        );
        $result = JitNestedHelperCoerce::coerceToHashtablePtr($context, $resultRaw);
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#9182');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#9182'
        );
    }
}
