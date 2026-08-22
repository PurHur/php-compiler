<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableNestedExportLlvm;
use PHPCompiler\JIT\HttpBuildQueryArrayLlvm;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\NestedVmVariableMethodLlvm;

/**
 * JIT/AOT link for __compiler_http_build_query (#9443, #24887, #26869, #33208, #33711).
 *
 * Runtime arrays use {@see HttpBuildQueryArrayLlvm} — NestedJIT HttpBuildQueryJitHelper SEGVs
 * on HashTable receivers (#33711). Helper compile kept for any residual NestedJIT refs.
 * Thin LLVM bridge; call-site {@see ensureLinked} for thin standalone AOT (#26869).
 * Type always-on empty decl removed (#33208) so leftover Type shells cannot mint http_build_query.1
 * (#31894 / #32122).
 * php-src: ext/standard/http.c — http_build_query
 */
final class StringHttpBuildQuery
{
    private const HELPER_PATH = '/ext/standard/HttpBuildQueryJitHelper.php';

    private const BUILD_HELPER = 'PHPCompiler\\ext\\standard\\HttpBuildQueryJitHelper::build';

    private const ABI = '__compiler_http_build_query_llvm';

    private const BRIDGE_ENTRY = 'http_build_query_llvm_bridge_entry';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::BUILD_HELPER,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        self::ABI,
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

        $probe = $context->module->getNamedFunction(self::ABI);
        // Only reuse when this LLVM bridge is present (#33711).
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
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
        $abiName = self::ABI;
        $probe = $context->module->getNamedFunction($abiName);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
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
        // Register before body emit so nested ABI self-calls resolve (#33711).
        $context->registerFunction($abiName, $fn);

        BasicBlockHelper::scopeLoweringToFunction($context, $fn, $abiName, static function () use ($context, $fn): void {
            $entry = JitVmHelperLink::bridgeEntryForEmit($fn, self::BRIDGE_ENTRY);
            $context->builder->positionAtEnd($entry);
            // Pure LLVM walk — NestedJIT helper SEGVs on runtime HT receivers (#33711).
            $context->builder->returnValue(
                HttpBuildQueryArrayLlvm::build(
                    $context,
                    $fn->getParam(0),
                    $fn->getParam(1),
                    $fn->getParam(2),
                    $fn->getParam(3)
                )
            );
        });
    }

    /** @internal Used by {@see HttpBuildQueryArrayLlvm} to ensure export ABIs (#33711). */
    public static function ensureJitHelperCompiled(Context $context): void
    {
        // LLVM path only — do not NestedJIT-compile HttpBuildQueryJitHelper (SEGV / slow).
        HashTableNestedExportLlvm::ensureLinked($context);
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
