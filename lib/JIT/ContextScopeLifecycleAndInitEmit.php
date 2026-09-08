<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Block;
use PHPLLVM;

/**
 * Scope push/pop, exports, debug/AOT source setters, and init/shutdown/header
 * builder positioning for {@see Context} (#36387).
 *
 * Extracted from {@see Context} so lifecycle/scope helpers and {@see emitInInit}
 * / {@see emitInShutdown} / {@see emitInHeaderPreFlush} stay a separate TU from
 * the Context construction hub (split-TU / size-budget ratchet).
 *
 * Used via {@code use ContextScopeLifecycleAndInitEmit;} on {@see Context}.
 *
 * No new C ABI. php-src analogy: executor globals / EG(symbol_table) scope nesting
 * and request init/shutdown hooks live beside the compiler front-end
 * (Zend/zend_execute_API.c, Zend/zend_variables.c, main/main.c).
 */
trait ContextScopeLifecycleAndInitEmit
{
    public function setMain(PHPLLVM\Value\Function_ $func): void {
        $this->main = $func;
    }

    /** Keep intrinsic memcpy/memset on the live builder after save/restore swaps (#36144). */
    public function syncIntrinsicBuilder(): void
    {
        $this->intrinsic->builder = $this->builder;
    }


    public function addExport(string $name, string $signature, Block $block): void {
        $this->exports[] = [$name, $signature, $block];
        CompileCache::recordExport($name, $signature, $block);
    }

    public function pushScope(): void {
        $this->scopeStack[] = $this->scope;
        $this->scope = new Scope;
    }

    public function popScope(): void {
        if ([] === $this->scopeStack) {
            return;
        }
        $this->scope = array_pop($this->scopeStack);
    }

    /**
     * Resolve a JIT variable by PHP name across nested include scopes (#776).
     */
    public function variableForScopedName(string $name): ?Variable
    {
        foreach ($this->scope->variables as $op) {
            if (OperandName::resolve($op) === $name) {
                return $this->scope->variables[$op];
            }
        }
        for ($i = count($this->scopeStack) - 1; $i >= 0; --$i) {
            foreach ($this->scopeStack[$i]->variables as $op) {
                if (OperandName::resolve($op) === $name) {
                    return $this->scopeStack[$i]->variables[$op];
                }
            }
        }

        return null;
    }


    public function jitResult(): ?Result
    {
        return $this->result;
    }

    public function refreshSuperglobals(): void
    {
        SuperglobalInit::refreshFromVm($this);
    }


    private function registerAotDebugSourceGlobal(): void
    {
        if (!AotDebugSymbols::isEnabled()) {
            return;
        }
        $path = $this->aotSourceFilename;
        if (!is_string($path) || '' === $path || '-' === $path) {
            return;
        }
        $normalized = str_replace('\\', '/', $path);
        $const = $this->context->constString($normalized, true);
        $global = $this->module->addGlobal($const->typeOf(), '__phpc_aot_source_file');
        $global->setInitializer($const);
    }

    public function setDebugFile(string $file): void {
        $this->debugFile = $file;
        $this->setDebug(true);
    }

    public function setAotSourceFilename(?string $filename): void
    {
        $this->aotSourceFilename = $filename;
        if (null !== $filename && '' !== $filename) {
            $this->jitAotEntryScriptPath = str_replace('\\', '/', $filename);
        }
    }

    public function setDebug(bool $value): void {
        // Todo
    }

    /**
     * Temporarily position the builder at __init__ (for native registry calls).
     *
     * @param callable(self): void $emit
     */
    public function emitInInit(callable $emit): void
    {
        $oldBuilder = $this->builder;
        $this->builder = $this->context->builderCreate();
        ++$this->initLinearEmissionDepth;
        $this->positionBuilderAtInitEmission();
        try {
            $emit($this);
            // propertyStore TYPE_VALUE may leave insert in prop_store_box_ready (#34649);
            // keep initLinearBlock on the open tail so the next emitInInit does not see a
            // sealed "main" (#34662).
            $insert = BasicBlockHelper::tryGetInsertBlock($this);
            if (null !== $insert && null === $insert->getTerminator()) {
                $this->initLinearBlock = $insert;
            }
        } finally {
            if ($this->initLinearEmissionDepth > 0) {
                --$this->initLinearEmissionDepth;
            }
            $this->builder = $oldBuilder;
        }
    }

    /**
     * Temporarily position the builder at __shutdown__ (register_shutdown_function, issue #3120).
     *
     * @param callable(self): void $emit
     */
    public function emitInShutdown(callable $emit): void
    {
        $oldBuilder = $this->builder;
        $this->builder = $this->context->builderCreate();
        $this->builder->positionAtEnd($this->shutdownBlock);
        try {
            $emit($this);
        } finally {
            $this->builder = $oldBuilder;
        }
    }

    /**
     * Temporarily position the builder at __header_pre_flush__ (header_register_callback, #3759).
     *
     * @param callable(self): void $emit
     */
    public function emitInHeaderPreFlush(callable $emit): void
    {
        $oldBuilder = $this->builder;
        $this->builder = $this->context->builderCreate();
        $this->builder->positionAtEnd($this->headerPreFlushBlock);
        try {
            $emit($this);
        } finally {
            $this->builder = $oldBuilder;
        }
    }
}
