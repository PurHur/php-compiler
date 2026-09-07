<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for phpc_str_replace_r1 / phpc_str_ireplace_r1 (#14779, #23912, #35160, #36388).
 *
 * Thin AOT uses native {@see StrReplaceRuntime} (no NestedJIT StrReplaceJitHelper) so
 * FUNCCALL results free on unset — peer {@see StringFormat} number_format_r1.
 * {@see Context::ensureFullStandaloneBodies} must not NestedJIT this during init (#35160).
 * SSOT behaviour: {@see \PHPCompiler\ext\standard\VmString}.
 * php-src: ext/standard/string.c — php_str_replace
 */
final class StringStrReplace
{
    private const ABI_REPLACE = StrReplaceRuntime::ABI_REPLACE;

    private const ABI_IREPLACE = StrReplaceRuntime::ABI_IREPLACE;

    private const ABI_TAKE_COUNT = StrReplaceRuntime::ABI_TAKE_COUNT;

    public static function ensureLinked(Context $context): void
    {
        self::implementReplace($context);
        self::implementIreplace($context);
        self::implementTakeCount($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(
        Context $context,
        Value $search,
        Value $replace,
        Value $subject,
        bool $caseInsensitive = false,
        ?Value $countSlot = null
    ): Value {
        self::ensureLinked($context);
        $abi = $caseInsensitive ? self::ABI_IREPLACE : self::ABI_REPLACE;
        $result = $context->builder->call(
            $context->lookupFunction($abi),
            $search,
            $replace,
            $subject
        );
        if (null !== $countSlot) {
            $count = $context->builder->call($context->lookupFunction(self::ABI_TAKE_COUNT));
            $context->builder->store($count, $countSlot);
        }

        return $result;
    }

    private static function implementReplace(Context $context): void
    {
        self::implementNativeBridge(
            $context,
            self::ABI_REPLACE,
            StrReplaceRuntime::BRIDGE_REPLACE,
            false
        );
    }

    private static function implementIreplace(Context $context): void
    {
        self::implementNativeBridge(
            $context,
            self::ABI_IREPLACE,
            StrReplaceRuntime::BRIDGE_IREPLACE,
            true
        );
    }

    private static function implementNativeBridge(
        Context $context,
        string $abiName,
        string $entryName,
        bool $caseInsensitive
    ): void {
        // Native emit does not NestedJIT StrReplaceJitHelper — safe under NestedJIT scope.
        $probe = $context->module->getNamedFunction($abiName);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, $entryName)) {
            $context->registerFunction($abiName, $probe);

            return;
        }
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        $strPtr = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType($strPtr, false, $strPtr, $strPtr, $strPtr);
        $fn = null !== $probe ? $probe : $context->module->addFunction($abiName, $ft);
        $entry = $fn->appendBasicBlock($entryName);
        $context->builder->positionAtEnd($entry);
        StrReplaceRuntime::emitBridgeBody($context, $fn, $caseInsensitive);
        $context->registerFunction($abiName, $fn);
        BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
    }

    private static function implementTakeCount(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_TAKE_COUNT);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, StrReplaceRuntime::BRIDGE_TAKE_COUNT)) {
            $context->registerFunction(self::ABI_TAKE_COUNT, $probe);

            return;
        }
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_TAKE_COUNT, $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($i64, false);
        $fn = null !== $probe ? $probe : $context->module->addFunction(self::ABI_TAKE_COUNT, $ft);
        $entry = $fn->appendBasicBlock(StrReplaceRuntime::BRIDGE_TAKE_COUNT);
        $context->builder->positionAtEnd($entry);
        StrReplaceRuntime::emitTakeCountBody($context, $fn);
        $context->registerFunction(self::ABI_TAKE_COUNT, $fn);
        BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
    }
}
