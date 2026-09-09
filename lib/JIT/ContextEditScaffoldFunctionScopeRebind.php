<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM;

/**
 * Edit-scaffold functionScope / $functions rebind from restored bitcode (#36387 / #36199).
 *
 * Extracted from {@see ContextEditScaffoldModuleRebind} so function-scope refresh
 * stays a separate TU from thin-boot bind, init/shutdown rebind, and intrinsic
 * rebuild (split-TU / size-budget ratchet / one-file-edit path).
 *
 * Used via {@code use ContextEditScaffoldFunctionScopeRebind;} on {@see Context}.
 *
 * No new C ABI. php-src analogy: Zend attaches a cached script image and rebinds
 * function tables without a full re-compile (Zend/zend_file_cache.c,
 * Zend/zend_execute_API.c function table refresh).
 */
trait ContextEditScaffoldFunctionScopeRebind
{
    /**
     * After edit-scaffold bitcode restore, re-point functionScope at live module Functions (#36387).
     *
     * register() stored decls from the throwaway empty module; replaceModule discards them.
     * Thin boot skips register — import every defined function from the restored module.
     */
    public function rebindFunctionScopeFromModule(): void
    {
        $names = array_keys($this->functionScope);
        $this->functionScope = [];
        foreach ($names as $name) {
            if (!is_string($name) || '' === $name) {
                continue;
            }
            $fn = $this->module->getNamedFunction($name);
            if ($fn instanceof PHPLLVM\Value\Function_) {
                $this->functionScope[$name] = $fn;
            }
        }
        // Thin boot: register() skipped — merge every named function from bitcode (#36387).
        if (CompileCache::isEditScaffoldActive()) {
            try {
                $fn = $this->module->getFirstFunction();
            } catch (\Throwable $e) {
                $fn = null;
            }
            $guard = 0;
            while ($fn instanceof PHPLLVM\Value\Function_ && $guard < 100000) {
                ++$guard;
                try {
                    $name = (string) $fn->getName();
                } catch (\Throwable $e) {
                    break;
                }
                if ('' !== $name) {
                    $this->functionScope[$name] = $fn;
                }
                try {
                    $next = method_exists($fn, 'getNext') ? $fn->getNext() : null;
                } catch (\Throwable $e) {
                    $next = null;
                }
                if (!$next instanceof PHPLLVM\Value\Function_) {
                    break;
                }
                $fn = $next;
            }
        }
        // Standalone refresh / init symbols may exist only in bitcode (thin init skipped declare).
        foreach ([
            '__superglobals__refresh',
            '__init__',
            '__shutdown__',
            '__header_pre_flush__',
        ] as $extra) {
            if (isset($this->functionScope[$extra])) {
                continue;
            }
            $fn = $this->module->getNamedFunction($extra);
            if ($fn instanceof PHPLLVM\Value\Function_) {
                $this->functionScope[$extra] = $fn;
            }
        }
        // Refresh $functions map entries that still name a live symbol.
        foreach ($this->functions as $lc => $old) {
            $llvm = $this->functionLlvmSymbols[$lc] ?? null;
            if (!is_string($llvm) || '' === $llvm) {
                if ($old instanceof PHPLLVM\Value\Function_) {
                    try {
                        $llvm = (string) $old->getName();
                    } catch (\Throwable $e) {
                        unset($this->functions[$lc]);
                        continue;
                    }
                } else {
                    unset($this->functions[$lc]);
                    continue;
                }
            }
            $fn = $this->module->getNamedFunction($llvm);
            if ($fn instanceof PHPLLVM\Value\Function_) {
                $this->functions[$lc] = $fn;
            } else {
                unset($this->functions[$lc]);
            }
        }
    }
}
