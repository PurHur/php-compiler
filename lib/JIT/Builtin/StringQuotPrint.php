<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for quoted_printable_encode/decode via QuotPrintJitHelper PHP (#5225, #9910).
 *
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
        self::implementBridge($context, '__compiler_quoted_printable_encode', self::ENCODE_HELPER);
        self::implementBridge($context, '__compiler_quoted_printable_decode', self::DECODE_HELPER);
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
        $context->builder->clearInsertionPosition();
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after QuotPrintJitHelper compile (#9910)');
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
        $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'QuotPrintJitHelper.php');
        if (null === $block) {
            throw new \LogicException('QuotPrintJitHelper.php parseAndCompile failed (#9910)');
        }
        $jit = new JIT($context);
        $jit->compile($block);
        foreach (self::COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                throw new \LogicException($logical.' was not compiled for JIT (#9910)');
            }
        }
    }
}
