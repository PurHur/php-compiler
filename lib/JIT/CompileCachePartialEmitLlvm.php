<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * LLVM IR surgery helpers for AOT partial-edit demote (#36387 / #36199).
 *
 * Extracted from {@see CompileCachePartialEmitDemote} so rename+declaration demote,
 * unused const-global prune, and prior-object symbol probes stay a separate TU from
 * keep/strip orchestration (size-budget / split-TU ratchet). Called only from the
 * demote hub — no new C ABI.
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
     * After demoting unchanged bodies, drop unused user const globals so
     * sibling-member string/array consts do not inflate the delta `.o` (#36387).
     *
     * Dense named lookup only (no full-module global walk) — walking every
     * NestedJIT global dominates tiny edit scaffolds and erased the emit win.
     */
    public static function pruneUnusedGlobalsAfterDemote(Context $context): int
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

    public static function llvmValueHasNoUses(Context $context, object $value): bool
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
