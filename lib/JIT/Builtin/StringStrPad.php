<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for phpc_str_pad_r1 (#14863, #23911, #36388).
 *
 * Thin AOT uses native {@see StrPadRuntime} (no NestedJIT StrPadJitHelper) so
 * FUNCCALL results free on unset — peer {@see StringStrReplace} / number_format_r1.
 * {@see Context::ensureFullStandaloneBodies} must not NestedJIT this during init.
 * SSOT behaviour: {@see \PHPCompiler\ext\standard\VmString}.
 * php-src: ext/standard/string.c — PHP_FUNCTION(str_pad)
 */
final class StringStrPad
{
    private const ABI = StrPadRuntime::ABI;

    private const BRIDGE_ENTRY = StrPadRuntime::BRIDGE_ENTRY;

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(
        Context $context,
        Value $input,
        Value $padLength,
        Value $padString,
        Value $padType
    ): Value {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI),
            $input,
            $padLength,
            $padString,
            $padType
        );
    }

    private static function implement(Context $context): void
    {
        // Native emit does not NestedJIT StrPadJitHelper — safe under NestedJIT scope.
        $probe = $context->module->getNamedFunction(self::ABI);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($strPtr, false, $strPtr, $i64, $strPtr, $i64);
        $fn = null !== $probe ? $probe : $context->module->addFunction(self::ABI, $ft);
        $entry = $fn->appendBasicBlock(self::BRIDGE_ENTRY);
        $context->builder->positionAtEnd($entry);
        StrPadRuntime::emitBridgeBody($context, $fn);
        $context->registerFunction(self::ABI, $fn);
        BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
    }
}
