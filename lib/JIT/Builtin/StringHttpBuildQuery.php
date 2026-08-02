<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableNestedExportLlvm;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\NestedVmVariableMethodLlvm;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_http_build_query via HttpBuildQueryJitHelper PHP (#9443, #24887, #26869).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer StringStrtr #21844).
 * Thin LLVM bridge forwards the ABI; call-site {@see ensureLinked} for thin standalone AOT (#26869).
 * php-src: ext/standard/http.c — http_build_query
 */
final class StringHttpBuildQuery
{
    private const HELPER_PATH = '/ext/standard/HttpBuildQueryJitHelper.php';

    private const BUILD_HELPER = 'PHPCompiler\\ext\\standard\\HttpBuildQueryJitHelper::build';

    private const BRIDGE_ENTRY = 'http_build_query_bridge_entry';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::BUILD_HELPER,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_http_build_query',
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
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction('__compiler_http_build_query');
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)
            || (null !== $probe && $probe->countBasicBlocks() > 0)
        ) {
            self::registerLinkedRuntime($context);

            return;
        }

        // Preserve caller insert block — clearInsertionPosition alone orphans mid-emit (#26869).
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        self::ensureJitHelperCompiled($context);
        self::implementBuildBridge($context);
        self::registerLinkedRuntime($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementBuildBridge(Context $context): void
    {
        $abiName = '__compiler_http_build_query';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($strPtr, false, $htPtr, $strPtr, $strPtr, $i64);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock(self::BRIDGE_ENTRY);
        $context->builder->positionAtEnd($entry);
        $resultRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::BUILD_HELPER),
            [$fn->getParam(0), $fn->getParam(1), $fn->getParam(2), $fn->getParam(3)]
        );
        $result = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $resultRaw);
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#26869');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        HashTableNestedExportLlvm::ensureLinked($context);
        NestedVmVariableMethodLlvm::ensureMethod($context, 'resolveindirect');
        NestedVmVariableMethodLlvm::ensureMethod($context, 'tostring');
        NestedVmVariableMethodLlvm::ensureMethod($context, 'toint');
        NestedVmVariableMethodLlvm::ensureMethod($context, 'tofloat');
        NestedVmVariableMethodLlvm::ensureMethod($context, 'tobool');
        NestedVmVariableMethodLlvm::ensureMethod($context, 'toarray');
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#26869'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringHttpBuildQuery bridge (#9443/#26869)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
