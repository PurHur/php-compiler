<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * LLVM IR surgery helpers for AOT partial-edit demote (#36387 / #36199).
 *
 * Extracted from {@see CompileCachePartialEmitDemote} so rename+declaration demote
 * and prior-object symbol probes stay a separate TU from keep/strip orchestration
 * (size-budget / split-TU ratchet). Unused const-global prune lives in
 * {@see CompileCachePartialEmitPruneGlobals}. Called only from the demote hub —
 * no new C ABI.
 *
 * php-src analogy: Zend opcache invalidates a unit's compiled image then rebinds
 * remaining symbols (Zend/zend_file_cache.c); there is no partial-object demote.
 */
final class CompileCachePartialEmitLlvm
{
    /**
     * Runtime / NestedJIT symbols safe to take from prior aot.o on partial edit (#36387).
     */
    public static function isSharedRuntimeDemoteCandidate(string $name): bool
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

    public static function llvmFunctionName(Context $context, object $fn): string
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
     * Turn a defined function into an extern declaration without walking BBs (#36387).
     *
     * BB-delete demote SIGSEGVs on some NestedJIT bodies (e.g. IniJitHelper::__iniget).
     * Rename the definition, mint a same-typed declaration under the original name,
     * RAUW call sites, then delete the old body entirely.
     */
    public static function demoteFunctionBodyToDeclaration(Context $context, \PHPLLVM\Value\Function_ $fn): bool
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
    public static function objectDefinedSymbols(string $objectPath): array
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
