<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

require_once __DIR__.'/CompileCachePartialEmitSymbolProbe.php';

/**
 * LLVM IR surgery helpers for AOT partial-edit demote (#36387 / #36199).
 *
 * Extracted from {@see CompileCachePartialEmitDemote} so rename+declaration demote
 * stays a separate TU from keep/strip orchestration (size-budget / split-TU ratchet).
 * Shared-runtime candidacy / LLVM name / prior-object nm probes live in
 * {@see CompileCachePartialEmitSymbolProbe}; unused const-global prune in
 * {@see CompileCachePartialEmitPruneGlobals}. Called only from the demote hub —
 * no new C ABI.
 *
 * php-src analogy: Zend opcache invalidates a unit's compiled image then rebinds
 * remaining symbols (Zend/zend_file_cache.c); there is no partial-object demote.
 */
final class CompileCachePartialEmitLlvm
{
    /**
     * Turn a defined function into an extern declaration without walking BBs (#36387).
     *
     * BB-delete demote SIGSEGVs on some NestedJIT bodies (e.g. IniJitHelper::__iniget).
     * Rename the definition, mint a same-typed declaration under the original name,
     * RAUW call sites, then delete the old body entirely.
     */
    public static function demoteFunctionBodyToDeclaration(Context $context, \PHPLLVM\Value\Function_ $fn): bool
    {
        $name = CompileCachePartialEmitSymbolProbe::llvmFunctionName($context, $fn);
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
}
