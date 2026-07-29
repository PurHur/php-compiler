<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for quoted_printable_encode/decode via QuotPrintJitHelper PHP (#5225, #9910, #24620).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer StreamFstat #24586).
 * Replaces former ~514-line LLVM in StringQuotPrintJit with thin bridges into {@see VmString} SSOT.
 * php-src: ext/standard/quot_print.c
 */
final class StringQuotPrint
{
    private const HELPER_PATH = '/ext/standard/QuotPrintJitHelper.php';

    private const ENCODE_HELPER = 'PHPCompiler\\ext\\standard\\QuotPrintJitHelper::encode';

    private const DECODE_HELPER = 'PHPCompiler\\ext\\standard\\QuotPrintJitHelper::decode';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::ENCODE_HELPER,
        self::DECODE_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        // Restore caller insert block after bridge emit (#19283) — clearInsertionPosition
        // left the user-script builder detached ("Current basic block has no parent function").
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        self::implementBridge($context, '__compiler_quoted_printable_encode', self::ENCODE_HELPER);
        self::implementBridge($context, '__compiler_quoted_printable_decode', self::DECODE_HELPER);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementBridge(Context $context, string $abiName, string $helperLogical): void
    {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType($strPtr, false, $strPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        self::ensureJitHelperCompiled($context);

        $entry = $fn->appendBasicBlock('quot_print_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            self::helperFunction($context, $helperLogical),
            $fn->getParam(0)
        );
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#24620');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#24620'
        );
    }
}
