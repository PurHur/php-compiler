<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Config;
use PHPLLVM;

/**
 * Edit-scaffold / bitcode module rebind helpers for {@see Context} (#36387 / #36199).
 *
 * Extracted from {@see Context} so thin-boot bind, init/shutdown rebind,
 * function-scope refresh, and intrinsic rebuild stay a separate TU from the
 * Context construction / register hub (split-TU / one-file-edit path).
 * Core-type / structFieldMap seed lives in {@see ContextEditScaffoldCoreTypeSeed}.
 *
 * Used via {@code use ContextEditScaffoldModuleRebind;} on {@see Context}.
 *
 * No new C ABI. php-src analogy: Zend opcache attaches a cached script image and
 * rebinds executor globals without a full re-compile (Zend/zend_file_cache.c).
 */
trait ContextEditScaffoldModuleRebind
{
    /**
     * Thin-boot: load prior AOT module.bc into this LLVM context before CreateNamed (#36387).
     *
     * Post-register {@see replaceModuleFromBitcodeFile()} uniqueifies structs (__string__.11)
     * and leaves PHP Values pointing at the discarded empty module (SIGSEGV on re-lower).
     */
    private function tryBindEditScaffoldBitcodeBeforeBuiltins(): void
    {
        $key = CompileCache::pendingEditScaffoldKey();
        if (null === $key) {
            return;
        }
        $bcPath = CompileCache::bitcodePath($key);
        if (!is_file($bcPath)) {
            return;
        }
        try {
            $this->replaceModuleFromBitcodeFile($bcPath);
            $this->seedCoreTypesFromModuleForEditScaffold();
            $this->rebindInitShutdownAfterModuleReplace();
            CompileCache::markEditScaffoldBitcodeBound();
        } catch (\Throwable $e) {
            // Leave the empty module; caller falls back to a full Context boot.
        }
    }

    /**
     * After {@see replaceModuleFromBitcodeFile()}, Context init pointers still reference
     * the discarded module — rebind from the restored bitcode (#36199).
     */
    public function rebindInitShutdownAfterModuleReplace(): void
    {
        $suffix = (string) Config::getenv('PHP_COMPILER_INIT_SYMBOL_SUFFIX');
        $init = $this->module->getNamedFunction('__init__'.$suffix);
        if ($init instanceof PHPLLVM\Value\Function_) {
            $this->initFunc = $init;
            $this->initBlock = $this->firstBasicBlock($init);
            $this->initLinearBlock = $this->initBlock;
        }
        $shutdown = $this->module->getNamedFunction('__shutdown__'.$suffix);
        if ($shutdown instanceof PHPLLVM\Value\Function_) {
            $this->shutdownFunc = $shutdown;
            $this->shutdownBlock = $this->firstBasicBlock($shutdown);
        }
        $headerPreFlush = $this->module->getNamedFunction('__header_pre_flush__'.$suffix);
        if ($headerPreFlush instanceof PHPLLVM\Value\Function_) {
            $this->headerPreFlushFunc = $headerPreFlush;
            $this->headerPreFlushBlock = $this->firstBasicBlock($headerPreFlush);
        }
        $this->initShutdownBlocksReady = true;
    }

    /**
     * After edit-scaffold bitcode restore, strip sealed terminators so re-lower can
     * emit new string/array consts into __init__ (#36387).
     */
    public function reopenInitLinearForEditScaffold(): void
    {
        $this->rebindInitShutdownAfterModuleReplace();
        if (!$this->initFunc instanceof PHPLLVM\Value\Function_) {
            return;
        }
        $blocks = $this->initFunc->getBasicBlocks();
        if ([] === $blocks) {
            return;
        }
        // Only unseal the linear tail's terminator (typically ret void).
        // Erasing every block's terminator dropped branches and made {main} a no-op (#36387).
        $tail = $blocks[count($blocks) - 1];
        $term = $tail->getTerminator();
        if (null !== $term && method_exists($term, 'eraseFromParent')) {
            $term->eraseFromParent();
        }
        $this->initLinearBlock = $tail;
    }

    /** Clear const caches so edit re-lower does not reuse disposed module Values (#36387). */
    public function resetCompileTimeConstantMapsForEditScaffold(): void
    {
        $this->stringConstantMap = [];
        $this->arrayConstantMap = [];
        $this->objectConstantMap = [];
        $this->boolValues = [];
        $this->constants = [];
    }

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

    /** Intrinsic holds the old module — rebuild after replaceModuleFromBitcodeFile (#36387). */
    public function refreshIntrinsicAfterModuleReplace(): void
    {
        $this->intrinsic = $this->module->intrinsic($this->builder);
    }

    private function firstBasicBlock(PHPLLVM\Value\Function_ $func): ?PHPLLVM\BasicBlock
    {
        foreach ($func->getBasicBlocks() as $block) {
            return $block;
        }

        return null;
    }
}
