<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for phpc_substr_count via SubstrCountJitHelper PHP (#14691, #21773).
 *
 * Replaces ~186-line LLVM search loop in ext/standard/JitSubstrCount.php.
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer StringChunkSplit #21399).
 * SSOT: {@see \PHPCompiler\ext\standard\VmString}.
 * php-src: ext/standard/string.c — PHP_FUNCTION(substr_count)
 */
final class StringSubstrCount
{
    private const HELPER_PATH = '/ext/standard/SubstrCountJitHelper.php';

    private const COUNT_HELPER = 'PHPCompiler\\ext\\standard\\SubstrCountJitHelper::countArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::COUNT_HELPER,
    ];

    private const BRIDGE_ENTRY = 'substr_count_bridge_entry';

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
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction('phpc_substr_count');
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction('phpc_substr_count', $probe);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        JitVmHelperLink::ensureCompiled($context, self::HELPER_PATH, self::COMPILED_HELPERS, '#21773');
        self::implementBridge($context);
        $context->registerFunction(
            'phpc_substr_count',
            $context->module->getNamedFunction('phpc_substr_count')
                ?? throw new \LogicException('phpc_substr_count missing after StringSubstrCount bridge (#14691)')
        );

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementBridge(Context $context): void
    {
        $abiName = 'phpc_substr_count';
        $probe = $context->module->getNamedFunction($abiName);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType(
            $i64,
            false,
            $strPtr,
            $strPtr,
            $i64,
            $i64,
            $i32
        );
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, self::BRIDGE_ENTRY);
        $context->builder->positionAtEnd($entry);

        // Z_PARAM_STR null → "" before helper (php-src ext/standard/string.c, #18265).
        $empty = $context->builder->load($context->constantStringFromString(''));
        $hayParam = $fn->getParam(0);
        $needleParam = $fn->getParam(1);
        $hayNull = $context->builder->icmp(Builder::INT_EQ, $hayParam, $strPtr->constNull());
        $needleNull = $context->builder->icmp(Builder::INT_EQ, $needleParam, $strPtr->constNull());
        $hay = $context->builder->select($hayNull, $empty, $hayParam);
        $needle = $context->builder->select($needleNull, $empty, $needleParam);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::COUNT_HELPER),
            [
                $hay,
                $needle,
                $fn->getParam(2),
                $fn->getParam(3),
                $fn->getParam(4),
            ]
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $i64)
        );
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        return JitVmHelperLink::lookupCompiled($context, $logical, '#21773');
    }
}
