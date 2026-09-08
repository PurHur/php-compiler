<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * Edit-scaffold bitcode restore orchestration for AOT one-file-edit (#36387).
 *
 * Extracted from {@see CompileCacheEditScaffold} so the restore hub stays a thin
 * composition of Plan + Strip + Restore (size-budget / split-TU ratchet).
 * Move-only — no new C ABI.
 *
 * php-src analogy: Zend opcache file-cache loads a prior unit image then
 * invalidates changed symbols (Zend/zend_file_cache.c).
 */
trait CompileCacheEditScaffoldRestore
{
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

    /**
     * @param array<string, mixed> $raw
     *
     * @return array<string, array<string, list<string>>>
     */
    private static function normalizeByFunctionMap(array $raw): array
    {
        $out = [];
        foreach ($raw as $member => $scopedMap) {
            if (!is_string($member) || !is_array($scopedMap)) {
                continue;
            }
            $resolved = realpath($member);
            $key = false !== $resolved ? $resolved : $member;
            $funcs = [];
            foreach ($scopedMap as $scoped => $syms) {
                if (!is_string($scoped) || !is_array($syms)) {
                    continue;
                }
                $list = [];
                foreach ($syms as $sym) {
                    if (is_string($sym) && '' !== $sym) {
                        $list[] = $sym;
                    }
                }
                if ([] !== $list) {
                    $funcs[$scoped] = array_values(array_unique($list));
                }
            }
            if ([] !== $funcs) {
                $out[$key] = $funcs;
            }
        }

        return $out;
    }
}
