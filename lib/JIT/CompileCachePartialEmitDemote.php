<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

require_once __DIR__.'/CompileCachePartialEmitPruneGlobals.php';
require_once __DIR__.'/CompileCachePartialEmitSymbolProbe.php';
require_once __DIR__.'/CompileCachePartialEmitLlvm.php';

/**
 * Partial-edit object demote for AOT edit-scaffold (#36387 / #36199).
 *
 * Orchestrates keep/strip candidate selection before TargetMachine emit on partial
 * keep. LLVM rename+declaration demote lives in {@see CompileCachePartialEmitLlvm};
 * shared-runtime / nm symbol probes in {@see CompileCachePartialEmitSymbolProbe};
 * unused const-global prune in {@see CompileCachePartialEmitPruneGlobals}
 * (split-TU / size-budget). CompileCache keeps a thin
 * {@see CompileCache::demoteBodiesForPartialObjectEmit()} delegate via
 * {@see CompileCacheEditSession}.
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
        $prevDefs = CompileCachePartialEmitSymbolProbe::objectDefinedSymbols($baseObject);
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
                $n = CompileCachePartialEmitSymbolProbe::llvmFunctionName($context, $lifecycle);
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
            if (is_string($name) && '' !== $name && CompileCachePartialEmitSymbolProbe::isSharedRuntimeDemoteCandidate($name)) {
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
            if (CompileCachePartialEmitLlvm::demoteFunctionBodyToDeclaration($context, $fn)) {
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
            $pruned = CompileCachePartialEmitPruneGlobals::pruneUnusedGlobalsAfterDemote($context);
            if ($pruned > 0) {
                \PHPCompiler\AOT\BuildTiming::note('edit_scaffold_globals_pruned', (float) $pruned);
            }
        }

        return $demoted;
    }
}
