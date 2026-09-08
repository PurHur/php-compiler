<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

require_once __DIR__.'/CompileCacheEditScaffoldStrip.php';

/**
 * Edit-scaffold restore for AOT one-file-edit (#36387 / #36403).
 *
 * Extracted from {@see CompileCache} so gen-0 split-TU and the size-budget ratchet
 * can hollow the cache hub (alongside {@see CompileCachePartialEmitDemote} /
 * {@see CompileCacheSemanticHash}). Strip *planning* lives in
 * {@see CompileCacheEditScaffoldPlan}; LLVM strip/rebind/rebase lives in
 * {@see CompileCacheEditScaffoldStrip}. Move-only — no new C ABI. php-src analogy:
 * Zend opcache / file-cache invalidation on changed units (Zend/zend_file_cache.c).
 */
trait CompileCacheEditScaffold
{
    use CompileCacheEditScaffoldStrip;

    /**
     * Same-project edit path: restore prior module.bc, strip user symbols, rebind helpers.
     *
     * Requires thin Context boot (bitcode before namedStructType/addFunction) so types and
     * functionScope bind to the restored module. {@see Context} parses {@see bitcodePath()}
     * when {@see armEditScaffold()} is set, then this strips user symbols for re-lower (#36387).
     *
     * @internal
     */
    public static function tryRestoreEditScaffold(Context $context, string $previousKey): bool
    {
        $meta = self::readMeta($previousKey);
        if (null === $meta) {
            return false;
        }
        $bcPath = self::bitcodePath($previousKey);
        if (!is_file($bcPath)) {
            return false;
        }
        $userSymbols = $meta['user_symbols'] ?? null;
        $helperSymbols = $meta['helper_symbols'] ?? null;
        if (!is_array($userSymbols) || [] === $userSymbols) {
            return false;
        }
        if (!is_array($helperSymbols)) {
            $helperSymbols = [];
        }

        if (!self::$editScaffoldBitcodeBound) {
            try {
                $context->replaceModuleFromBitcodeFile($bcPath);
            } catch (\Throwable $e) {
                return false;
            }
        }

        $byMember = is_array($meta['user_symbols_by_member'] ?? null)
            ? $meta['user_symbols_by_member']
            : [];
        $byFunction = is_array($meta['user_symbols_by_function'] ?? null)
            ? $meta['user_symbols_by_function']
            : [];
        self::$editScaffoldByFunction = self::normalizeByFunctionMap($byFunction);
        $toStrip = self::userSymbolsToStripForEdit($userSymbols, $byMember);
        $partial = self::wouldPartialStrip($userSymbols, $byMember);
        self::stripUserSymbolsFromModule($context, $toStrip);
        // Prefer full logical→LLVM map saved at cold emit; fall back to NestedJIT helpers (#36387).
        $functionSymbols = $meta['function_llvm_symbols'] ?? null;
        if (!is_array($functionSymbols) || [] === $functionSymbols) {
            $functionSymbols = $helperSymbols;
        }
        self::rebindHelperSymbols($context, $functionSymbols);
        $link = self::readLinkManifest($previousKey);
        if (null !== $link) {
            \PHPCompiler\AOT\HelperRuntimeCache::adoptUnitSlugsForLink($link['helper_slugs']);
        }
        SuperglobalInit::rebindGlobalsFromModule($context);
        $context->rebindFunctionScopeFromModule();
        $context->rebindInitShutdownAfterModuleReplace();
        $context->reopenInitLinearForEditScaffold();
        $context->refreshIntrinsicAfterModuleReplace();
        $context->syncIntrinsicBuilder();
        // Clear PHP-side string/array const maps so re-lower allocates fresh globals (#36387).
        $context->resetCompileTimeConstantMapsForEditScaffold();
        self::$skipModuleFuncCompile = true;
        self::$editScaffoldActive = true;
        self::$editScaffoldPartial = $partial;
        self::$partialEmitBaseObject = null;
        if ($partial) {
            $baseObject = self::objectPath($previousKey);
            if (is_file($baseObject) && filesize($baseObject) > 0) {
                self::$partialEmitBaseObject = $baseObject;
            }
        }
        self::$pendingEditScaffoldKey = null;
        \PHPCompiler\AOT\BuildTiming::note('edit_scaffold_hit', 1.0);
        if ($partial) {
            \PHPCompiler\AOT\BuildTiming::note('edit_scaffold_partial', 1.0);
        }

        return true;
    }
}
