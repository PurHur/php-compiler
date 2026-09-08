<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM;

/**
 * Builtin register/implement/initialize for {@see Context} (#36387).
 *
 * Extracted from {@see Context}; the thin-AOT functionProxies catalog lives in
 * {@see ContextDefineBuiltinFunctionProxies} (split-TU / size-budget ratchet).
 * One-file-edit / edit-scaffold skip paths stay with this trait (#36199).
 *
 * Used via {@code use ContextDefineBuiltins;} on {@see Context}.
 *
 * No new C ABI. php-src analogy: Zend registers internal functions and class
 * methods into the executor globals at MINIT (Zend/zend_builtin_functions.c,
 * ext/standard/php_standard.h and peers) — a catalog separate from the compiler proper.
 */
trait ContextDefineBuiltins
{
    private function defineBuiltins(int $loadType): void {
        // Stale sg_* from a prior JITContext in the same PHP process breaks SessionDestroy::implement (#4415).
        SuperglobalInit::$globals = [];
        LibcExtern::register($this);
        if (!CompileCache::shouldSkipBuiltinImplement()) {
            LibcExtern::implementMcjitMemBodies($this);
        }
        foreach ($this->builtins as $builtin) {
            // Restored bitcode already has __mm__malloc etc. addFunction would mint
            // empty __mm__malloc.1 and lookupFunction would bind the stub (#36387).
            // Thin helper-cache AOT may restore bitcode without the mm family — still
            // register when the decls are absent (str-builder concat flatten, #36386).
            if (CompileCache::shouldSkipBuiltinImplement()
                && $builtin instanceof Builtin\MemoryManager) {
                $mm = $this->module->getNamedFunction('__mm__malloc');
                if ($mm instanceof PHPLLVM\Value\Function_) {
                    $this->registerFunction('__mm__malloc', $mm);
                    $realloc = $this->module->getNamedFunction('__mm__realloc');
                    if ($realloc instanceof PHPLLVM\Value\Function_) {
                        $this->registerFunction('__mm__realloc', $realloc);
                    }
                    $free = $this->module->getNamedFunction('__mm__free');
                    if ($free instanceof PHPLLVM\Value\Function_) {
                        $this->registerFunction('__mm__free', $free);
                    }
                } else {
                    $builtin->register();
                }
                continue;
            }
            $builtin->register();
        }
        if ($loadType === Builtin::LOAD_TYPE_IMPORT) {
            return;
        }
        // Edit-scaffold: helpers already defined in restored bitcode — skip implement IR (#36387).
        if (!CompileCache::shouldSkipBuiltinImplement()) {
        foreach ($this->builtins as $builtin) {
            // this is a separate loop, since initialize may
            // depend on functions defined during implement()
            // so this way, cross-builtin dependencies are honored
            $builtin->implement();
        }
        McjitEmbedRuntime::finalizeModule($this);
        $this->ensureInitShutdownBlocks();

        foreach ($this->builtins as $builtin) {
            $builtin->initialize();
        }

        SuperglobalInit::initialize($this);
        CliArgvGlobalInit::initialize($this);
        if (Builtin::LOAD_TYPE_STANDALONE === $this->loadType
            || Builtin::LOAD_TYPE_EMBED === $this->loadType) {
            SuperglobalInit::declareRefresh($this);
            SuperglobalInit::implementRefresh($this);
        }

        Builtin\ReflectionNative::registerDeclarations($this);
        Builtin\AttributeRegistry::registerDeclarations($this);
        if (Builtin::LOAD_TYPE_STANDALONE === $this->loadType) {
            if ($this->isUserScriptAot()) {
                $this->ensureMinimalUserStandaloneBodies();
            } elseif ($this->shouldUseBootstrapAotStandaloneBodies()) {
                $this->ensureBootstrapAotStandaloneBodies();
            } else {
                $this->ensureFullStandaloneBodies();
            }
        }
        } else {
            // Thin bitcode restore without mm bodies: implement MemoryManager only when
            // we had to mint empty __mm__* decls above (#36386 / str-builder helper=1).
            foreach ($this->builtins as $builtin) {
                if (!$builtin instanceof Builtin\MemoryManager) {
                    continue;
                }
                $mm = $this->module->getNamedFunction('__mm__malloc');
                if ($mm instanceof PHPLLVM\Value\Function_ && 0 === $mm->countBasicBlocks()) {
                    $builtin->implement();
                }
                break;
            }
        }

        $this->defineBuiltinFunctionProxies();
    }
}
