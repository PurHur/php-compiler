<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * Partial-edit object demote for AOT edit-scaffold (#36387 / #36199).
 *
 * Extracted from {@see CompileCache} so one-file-edit ≤25%-of-cold work stays a
 * separate TU (split-TU / compile-cache iterability) while CompileCache keeps a
 * thin {@see CompileCache::demoteBodiesForPartialObjectEmit()} delegate.
 *
 * Before TargetMachine emit on partial keep: drop bodies that already exist in the
 * prior `aot.o`, leaving declarations. Rebuild set (stripped user symbols + main +
 * init/shutdown) keep their bodies. Unused `string_const_*` / `array_const_*` /
 * `object_const_*` globals are pruned so sibling-member literals do not inflate the
 * delta object. NestedJIT (`PHPCompiler_*`) already present in the prior `aot.o` is
 * demoted via rename+declaration+delete (BB-walk demote SIGSEGVs on some IniJitHelper
 * bodies).
 *
 * No new C ABI — pure LLVM IR surgery via PHPLLVM (php-src has no analogue; Zend
 * recompiles whole request scripts).
 */
final class CompileCachePartialEmitDemote
{
    /**
     * @param list<string>           $strippedUserSymbols
     * @param array<string, mixed>   $keptUserSymbols
     *
     * @return int number of functions demoted to declarations
     */
    public static function demoteBodiesForPartialObjectEmit(
        Context $context,
        ?string $baseObject,
        bool $editScaffoldPartial,
        array $strippedUserSymbols,
        array $keptUserSymbols
    ): int {
        if (null === $baseObject || !$editScaffoldPartial) {
            return 0;
        }
        $prevDefs = self::objectDefinedSymbols($baseObject);
        if ([] === $prevDefs) {
            return 0;
        }

        $mustKeepBody = ['main' => true];
        foreach ($strippedUserSymbols as $name) {
            if (is_string($name) && '' !== $name) {
                $mustKeepBody[$name] = true;
            }
        }
        foreach ([$context->initFunc, $context->shutdownFunc] as $lifecycle) {
            if ($lifecycle instanceof \PHPLLVM\Value\Function_) {
                $n = self::llvmFunctionName($context, $lifecycle);
                if ('' !== $n) {
                    $mustKeepBody[$n] = true;
                }
            }
        }
        $candidates = [];
        foreach (array_keys($keptUserSymbols) as $name) {
            if (is_string($name) && '' !== $name) {
                $candidates[$name] = true;
            }
        }
        foreach (array_keys($prevDefs) as $name) {
            if (is_string($name) && '' !== $name && self::isSharedRuntimeDemoteCandidate($name)) {
                $candidates[$name] = true;
            }
        }

        $demoted = 0;
        $nestedjitDemoted = 0;
        foreach (array_keys($candidates) as $name) {
            if (isset($mustKeepBody[$name]) || str_ends_with($name, '.stale') || str_starts_with($name, 'llvm.')) {
                continue;
            }
            $fn = null;
            try {
                $fn = $context->module->getNamedFunction($name);
            } catch (\Throwable $e) {
                continue;
            }
            if (!$fn instanceof \PHPLLVM\Value\Function_) {
                continue;
            }
            $blocks = 0;
            try {
                $blocks = (int) $fn->countBasicBlocks();
            } catch (\Throwable $e) {
                continue;
            }
            if ($blocks < 1) {
                continue;
            }
            if (self::demoteFunctionBodyToDeclaration($context, $fn)) {
                ++$demoted;
                if (str_starts_with($name, 'PHPCompiler_')) {
                    ++$nestedjitDemoted;
                }
            }
        }

        if ($demoted > 0) {
            \PHPCompiler\AOT\BuildTiming::note('edit_scaffold_demoted', (float) $demoted);
        }
        if ($nestedjitDemoted > 0) {
            \PHPCompiler\AOT\BuildTiming::note('edit_scaffold_nestedjit_demoted', (float) $nestedjitDemoted);
        }

        $pruned = 0;
        // Skip prune on small deltas — 4k×named-global probes dominate tiny scaffolds
        // (comment-only <30% gate). MiniWebApp-scale demotes benefit (#36387).
        if ($demoted >= 100) {
            $pruned = self::pruneUnusedGlobalsAfterDemote($context);
            if ($pruned > 0) {
                \PHPCompiler\AOT\BuildTiming::note('edit_scaffold_globals_pruned', (float) $pruned);
            }
        }

        return $demoted;
    }

    /**
     * Runtime / NestedJIT symbols safe to take from prior aot.o on partial edit (#36387).
     */
    private static function isSharedRuntimeDemoteCandidate(string $name): bool
    {
        if ('' === $name) {
            return false;
        }
        // NestedJIT helpers already linked into prior aot.o — demote via safe rename+delete.
        if (str_starts_with($name, 'PHPCompiler_')) {
            return true;
        }

        return str_starts_with($name, '__value__')
            || str_starts_with($name, '__string__')
            || str_starts_with($name, '__hashtable__')
            || str_starts_with($name, '__ref__')
            || str_starts_with($name, '__object__')
            || str_starts_with($name, 'phpc_')
            || str_starts_with($name, '__compiler_')
            || str_starts_with($name, '__phpc_')
            || str_starts_with($name, '__superglobals__')
            || str_starts_with($name, 'internal_');
    }

    private static function llvmFunctionName(Context $context, object $fn): string
    {
        if (!isset($fn->value)) {
            return '';
        }
        try {
            $raw = $context->llvm->lib->LLVMGetValueName($fn->value);
        } catch (\Throwable $e) {
            return '';
        }
        if (null === $raw) {
            return '';
        }
        if (is_object($raw) && method_exists($raw, 'toString')) {
            return (string) $raw->toString();
        }

        return is_string($raw) ? $raw : '';
    }

    /**
     * After demoting unchanged bodies, drop unused user const globals so
     * sibling-member string/array consts do not inflate the delta `.o` (#36387).
     *
     * Dense named lookup only (no full-module global walk) — walking every
     * NestedJIT global dominates tiny edit scaffolds and erased the emit win.
     */
    private static function pruneUnusedGlobalsAfterDemote(Context $context): int
    {
        $prefixes = ['string_const_', 'array_const_', 'object_const_'];
        $suffixes = ['_main', ''];
        $pruned = 0;
        for ($pass = 0; $pass < 3; ++$pass) {
            $batch = [];
            $misses = 0;
            for ($i = 0; $i < 4096; ++$i) {
                $hit = false;
                foreach ($prefixes as $prefix) {
                    foreach ($suffixes as $suffix) {
                        $name = $prefix.$i.$suffix;
                        $g = null;
                        try {
                            $g = $context->module->getNamedGlobal($name);
                        } catch (\Throwable $e) {
                            $g = null;
                        }
                        if (!$g instanceof \PHPLLVM\Value) {
                            continue;
                        }
                        $hit = true;
                        if (self::llvmValueHasNoUses($context, $g)) {
                            $batch[] = $g;
                        }
                    }
                }
                if ($hit) {
                    $misses = 0;
                } elseif (++$misses >= 64) {
                    break;
                }
            }
            if ([] === $batch) {
                break;
            }
            foreach ($batch as $g) {
                if (!is_object($g) || !method_exists($g, 'delete')) {
                    continue;
                }
                try {
                    $g->delete();
                    ++$pruned;
                } catch (\Throwable $e) {
                }
            }
        }

        return $pruned;
    }

    private static function llvmValueHasNoUses(Context $context, object $value): bool
    {
        if (!isset($value->value)) {
            return false;
        }
        try {
            $use = $context->llvm->lib->LLVMGetFirstUse($value->value);
        } catch (\Throwable $e) {
            return false;
        }

        return null === $use;
    }

    /**
     * Turn a defined function into an extern declaration without walking BBs (#36387).
     *
     * BB-delete demote SIGSEGVs on some NestedJIT bodies (e.g. IniJitHelper::__iniget).
     * Rename the definition, mint a same-typed declaration under the original name,
     * RAUW call sites, then delete the old body entirely.
     */
    private static function demoteFunctionBodyToDeclaration(Context $context, \PHPLLVM\Value\Function_ $fn): bool
    {
        $name = self::llvmFunctionName($context, $fn);
        if ('' === $name) {
            return false;
        }
        try {
            $ptrTy = $fn->typeOf();
            $funcTy = $ptrTy;
            if ($ptrTy instanceof \PHPLLVM\Type\Pointer) {
                $funcTy = $ptrTy->getElementType();
            }
            if (!$funcTy instanceof \PHPLLVM\Type\Function_) {
                return false;
            }
            $deadName = $name.'.demote_dead';
            $suffix = 0;
            while (true) {
                $existing = null;
                try {
                    $existing = $context->module->getNamedFunction($deadName);
                } catch (\Throwable $e) {
                    $existing = null;
                }
                if (null === $existing) {
                    break;
                }
                $deadName = $name.'.demote_dead.'.$suffix;
                ++$suffix;
                if ($suffix > 64) {
                    return false;
                }
            }
            $fn->setName($deadName);
            $decl = $context->module->addFunction($name, $funcTy);
            if (!$decl instanceof \PHPLLVM\Value\Function_) {
                $fn->setName($name);

                return false;
            }
            $fn->replaceAllUsesWith($decl);
            $fn->delete();

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Defined ELF symbol names in an object file (nm -g --defined-only) (#36387).
     *
     * @return array<string, true>
     */
    private static function objectDefinedSymbols(string $objectPath): array
    {
        if (!is_file($objectPath) || filesize($objectPath) < 1) {
            return [];
        }
        $out = [];
        $rc = 1;
        exec('nm -g --defined-only '.escapeshellarg($objectPath).' 2>/dev/null', $out, $rc);
        if (0 !== $rc) {
            return [];
        }
        $defs = [];
        foreach ($out as $line) {
            $parts = preg_split('/\s+/', trim((string) $line));
            if (!is_array($parts) || count($parts) < 3) {
                continue;
            }
            $name = $parts[count($parts) - 1];
            if (is_string($name) && '' !== $name && !str_starts_with($name, '.')) {
                $defs[$name] = true;
            }
        }

        return $defs;
    }
}
