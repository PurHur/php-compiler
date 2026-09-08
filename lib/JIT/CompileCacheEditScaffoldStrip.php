<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * Edit-scaffold LLVM mutation: strip user symbols, rebind helpers, purge stale (#36387).
 *
 * Extracted from {@see CompileCacheEditScaffold} so restore orchestration stays a
 * separate TU from module rewrite (size-budget / split-TU ratchet). Composed into
 * {@see CompileCache} alongside EditScaffold. Move-only — no new C ABI.
 *
 * php-src analogy: Zend opcache file-cache invalidates a unit's compiled image
 * then rebinds remaining symbols (Zend/zend_file_cache.c / accelerator hash).
 */
trait CompileCacheEditScaffoldStrip
{
    /**
     * After re-lower, point CallInsts that still target `foo.stale` at the new `foo`
     * and delete the stale Function_ — enables keeping unchanged member bodies (#36387).
     *
     * Only probes symbols recorded at strip time — never walks the full restored
     * module (thousands of builtin funcs via FFI; measured ~1.5s on MiniWebApp).
     */
    public static function rebaseStaleUserSymbols(Context $context): void
    {
        if ([] === self::$strippedUserSymbols) {
            return;
        }

        $purged = 0;
        foreach (self::$strippedUserSymbols as $orig) {
            if (!is_string($orig) || '' === $orig) {
                continue;
            }
            $staleFn = $context->module->getNamedFunction($orig.'.stale');
            if (!$staleFn instanceof \PHPLLVM\Value\Function_) {
                continue;
            }
            $fresh = $context->module->getNamedFunction($orig);
            if ($fresh instanceof \PHPLLVM\Value\Function_ && $fresh !== $staleFn) {
                try {
                    $staleFn->replaceAllUsesWith($fresh);
                } catch (\Throwable $e) {
                    // Fall through to delete attempt.
                }
            }
            if (self::tryDeleteFunction($staleFn)) {
                ++$purged;
            }
        }
        self::$strippedUserSymbols = [];

        if ($purged > 0) {
            \PHPCompiler\AOT\BuildTiming::note('edit_scaffold_stale_purged', (float) $purged);
        }
    }

    private static function tryDeleteFunction(\PHPLLVM\Value\Function_ $fn): bool
    {
        try {
            $fn->delete();

            return true;
        } catch (\Throwable $e) {
            try {
                $fn->setName((string) $fn->getName().'.dead');
            } catch (\Throwable $e2) {
                return false;
            }

            return false;
        }
    }

    /**
     * @param list<mixed> $userSymbols
     */
    private static function stripUserSymbolsFromModule(Context $context, array $userSymbols): void
    {
        $names = [];
        foreach ($userSymbols as $name) {
            if (is_string($name) && '' !== $name) {
                $names[$name] = true;
            }
        }
        // Always drop standalone main wrapper — recreated in compileToFile.
        $names['main'] = true;

        self::$strippedUserSymbols = [];
        foreach (array_keys($names) as $name) {
            $fn = $context->module->getNamedFunction($name);
            if ($fn instanceof \PHPLLVM\Value\Function_) {
                try {
                    // LLVMDeleteFunction is undefined if the symbol still has uses
                    // (__init__ string-const stores, old main). Rename so re-lower can
                    // addFunction the original name without dangling IR (#36387).
                    $fn->setName($name.'.stale');
                    self::$strippedUserSymbols[] = $name;
                } catch (\Throwable $e) {
                    try {
                        $fn->delete();
                    } catch (\Throwable $e2) {
                        // Leave stale symbol; recompile may fail loudly rather than miscompile.
                    }
                }
            }
        }

        // Keep user const globals: deleting them while __init__ still references
        // them SIGSEGVs on re-lower. resetCompileTimeConstantMaps allocates new names.
    }

    private static function stripUserConstGlobals(Context $context): void
    {
        $prefixes = ['string_const_', 'array_const_', 'object_const_'];
        $toDelete = [];
        try {
            $global = $context->module->getFirstGlobal();
        } catch (\Throwable $e) {
            return;
        }
        $guard = 0;
        while ($global instanceof \PHPLLVM\Value && $guard < 100000) {
            ++$guard;
            $name = '';
            try {
                $name = (string) $global->getName();
            } catch (\Throwable $e) {
                break;
            }
            $isUserConst = false;
            foreach ($prefixes as $prefix) {
                if (str_starts_with($name, $prefix) && str_ends_with($name, '_main')) {
                    $isUserConst = true;
                    break;
                }
            }
            $next = null;
            try {
                if (method_exists($global, 'getNextGlobal')) {
                    $next = $global->getNextGlobal();
                }
            } catch (\Throwable $e) {
                $next = null;
            }
            if ($isUserConst) {
                $toDelete[] = $global;
            }
            if (!$next instanceof \PHPLLVM\Value) {
                // Fall back: only first-global walk without Next — stop after collecting known names via getNamedGlobal.
                break;
            }
            $global = $next;
        }

        // Named lookup for dense const indices (0..N) when NextGlobal is unavailable.
        if ([] === $toDelete) {
            for ($i = 0; $i < 4096; ++$i) {
                foreach (['string_const_', 'array_const_', 'object_const_'] as $prefix) {
                    $g = $context->module->getNamedGlobal($prefix.$i.'_main');
                    if ($g instanceof \PHPLLVM\Value) {
                        $toDelete[] = $g;
                    }
                }
            }
        }

        foreach ($toDelete as $g) {
            if ($g instanceof \PHPLLVM\Value\Global_ || (is_object($g) && method_exists($g, 'delete'))) {
                try {
                    $g->delete();
                } catch (\Throwable $e) {
                    // ignore — unused consts are harmless if delete fails
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $helperSymbols logical lc → LLVM name
     */
    private static function rebindHelperSymbols(Context $context, array $helperSymbols): void
    {
        foreach ($helperSymbols as $logical => $llvm) {
            if (!is_string($logical) || !is_string($llvm) || '' === $logical || '' === $llvm) {
                continue;
            }
            $fn = $context->module->getNamedFunction($llvm);
            if (!$fn instanceof \PHPLLVM\Value\Function_) {
                continue;
            }
            $lc = strtolower($logical);
            $context->functions[$lc] = $fn;
            $context->functionLlvmSymbols[$lc] = $llvm;
            $argTypes = [];
            $n = $fn->countParams();
            for ($i = 0; $i < $n; ++$i) {
                $argTypes[] = $fn->getParam($i)->typeOf();
            }
            $context->functionProxies[$lc] = new Call\Native($fn, $logical, $argTypes);
        }
    }
}
