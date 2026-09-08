<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for phpc_htmlspecialchars_r1 / _ex_r1 (#9445, #20487, #27290, #36388).
 *
 * Thin AOT uses native {@see HtmlspecialcharsRuntime} (no NestedJIT
 * HtmlspecialcharsJitHelper) so FUNCCALL results free on unset — peer
 * {@see StringChunkSplit} / chunk_split_r1.
 * {@see Context::ensureFullStandaloneBodies} must not NestedJIT this during init.
 * SSOT behaviour: {@see \PHPCompiler\ext\standard\VmString::htmlspecialchars()}.
 * php-src: ext/standard/html.c — PHP_FUNCTION(htmlspecialchars)
 */
final class StringHtmlspecialchars
{
    private const ABI = HtmlspecialcharsRuntime::ABI;

    private const ABI_EX = HtmlspecialcharsRuntime::ABI_EX;

    private const BRIDGE_ENTRY = HtmlspecialcharsRuntime::BRIDGE_ENTRY;

    private const BRIDGE_ENTRY_EX = HtmlspecialcharsRuntime::BRIDGE_ENTRY_EX;

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $strPtr, Value $flags): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI),
            $strPtr,
            $flags
        );
    }

    public static function invokeEx(
        Context $context,
        Value $strPtr,
        Value $flags,
        Value $doubleEncode
    ): Value {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_EX),
            $strPtr,
            $flags,
            $doubleEncode
        );
    }

    /** @deprecated use {@see invoke} — kept for Type/String_ decls of legacy ABI names */
    public static function implement(Context $context): void
    {
        // Native emit does not NestedJIT HtmlspecialcharsJitHelper — safe under NestedJIT.
        self::implementOne(
            $context,
            self::ABI,
            self::BRIDGE_ENTRY,
            false
        );
        self::implementOne(
            $context,
            self::ABI_EX,
            self::BRIDGE_ENTRY_EX,
            true
        );
    }

    private static function implementOne(
        Context $context,
        string $abi,
        string $bridgeEntry,
        bool $withDoubleEncode
    ): void {
        $probe = $context->module->getNamedFunction($abi);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, $bridgeEntry)) {
            $context->registerFunction($abi, $probe);

            return;
        }
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abi, $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $ft = $withDoubleEncode
            ? $context->context->functionType($strPtr, false, $strPtr, $i64, $i64)
            : $context->context->functionType($strPtr, false, $strPtr, $i64);
        $fn = null !== $probe ? $probe : $context->module->addFunction($abi, $ft);
        $entry = $fn->appendBasicBlock($bridgeEntry);
        $context->builder->positionAtEnd($entry);
        HtmlspecialcharsRuntime::emitBridgeBody($context, $fn, $withDoubleEncode);
        $context->registerFunction($abi, $fn);
        BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
    }
}
