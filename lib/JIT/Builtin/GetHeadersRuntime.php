<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_get_headers via GetHeadersJitHelper PHP (#9212, #24633).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer StreamFstat #24586 / StringQuotPrint #24620).
 * Call-site {@see ensureLinked} restores the caller insert block after bridge emit
 * (thin AOT: "Current basic block has no parent function", #27317 / peer #27088).
 * SSOT {@see \PHPCompiler\ext\standard\VmHttpFetchNative} / {@see \PHPCompiler\ext\standard\VmHttpHeaders}.
 * php-src: ext/standard/head.c — PHP_FUNCTION(get_headers)
 */
final class GetHeadersRuntime
{
    private const ABI_NAME = '__compiler_get_headers';

    private const HELPER_PATH = '/ext/standard/GetHeadersJitHelper.php';

    private const GET_HEADERS_HELPER = 'PHPCompiler\\ext\\standard\\GetHeadersJitHelper::getHeaders';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::GET_HEADERS_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_NAME);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        // Preserve caller insert block — clearInsertionPosition alone orphans mid-emit (#27317 / #27088).
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        self::ensureJitHelperCompiled($context);
        self::implementGetHeadersBridge($context);
        self::registerLinkedRuntime($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementGetHeadersBridge(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_NAME);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_NAME, $probe);

            return;
        }

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $i1 = $context->getTypeFromString('int1');
        $ft = $context->context->functionType($htPtr, false, $strPtr, $i1);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(self::ABI_NAME, $ft);

        $entry = $fn->appendBasicBlock('get_headers_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $htRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::GET_HEADERS_HELPER),
            [$fn->getParam(0), $fn->getParam(1)]
        );
        $ht = JitNestedHelperCoerce::coerceToHashtablePtr($context, $htRaw);
        $context->builder->returnValue($ht);
        $context->registerFunction(self::ABI_NAME, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#24633');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#24633'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction(self::ABI_NAME);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_NAME.' missing after GetHeadersRuntime bridge (#9212)');
        }
        $context->registerFunction(self::ABI_NAME, $fn);
    }
}
