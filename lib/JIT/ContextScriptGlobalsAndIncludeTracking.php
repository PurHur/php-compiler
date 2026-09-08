<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCfg\Operand;
use PHPCompiler\Config;
use PHPLLVM;

/**
 * Script-global slots + JIT include / helper-TU compile tracking for {@see Context} (#36387).
 *
 * Extracted from {@see Context} so foreach-slot keys, include/helper-TU compile marks,
 * `ensureScriptGlobal`, and `bindVariableByName` stay a separate TU from the Context
 * construction / compile hub (split-TU / size-budget ratchet).
 *
 * Used via {@code use ContextScriptGlobalsAndIncludeTracking;} on {@see Context}.
 *
 * No new C ABI. php-src analogy: EG(symbol_table) / included_files bookkeeping beside the
 * executor (Zend/zend_execute_API.c, Zend/zend_variables.c) rather than inside the compiler
 * front-end.
 */
trait ContextScriptGlobalsAndIncludeTracking
{
    /**
     * Map key for foreach alloca tables — include activeFunction so NestedJIT of a
     * multi-method helper cannot reuse a sibling method's entry alloca when
     * spl_object_id values collide after GC (#28053 / #27228).
     */
    public function foreachSlotMapKey(object $slotKey): string
    {
        return $this->activeFunction."\0".\spl_object_id($slotKey);
    }

    /** Clear per-script local name/ref bindings before lowering a new {main} TU (#4763). */
    public function resetScriptLocalBindings(): void
    {
        $this->namedVariableBindings = [];
        $this->foreachByRefLocalNames = [];
        $this->refAliasNames = [];
        $this->jitUndeclaredInstancePropertyWrites = [];
        $this->jitIncludedFiles = [];
        $this->jitAotIncludedCompileDone = [];
    }

    public function recordJitIncludedFile(string $path): void
    {
        $normalized = \PHPCompiler\VM\ScriptStack::normalize($path);
        if ('' !== $normalized && !\PHPCompiler\VM\ScriptStack::isVirtualCompileUnit($normalized)) {
            $this->jitIncludedFiles[] = $normalized;
        }
    }

    /** Outer {main} TU path for bootstrap/AOT entry classification (#11005, #11642). */
    private function resolveJitAotEntryScriptPath(): string
    {
        if ('' !== $this->jitAotEntryScriptPath) {
            return str_replace('\\', '/', $this->jitAotEntryScriptPath);
        }
        $fromAot = $this->aotSourceFilename;
        if (is_string($fromAot) && '' !== $fromAot) {
            return str_replace('\\', '/', $fromAot);
        }

        return '';
    }

    public function isCompilerLibSpineSmokeEntry(): bool
    {
        $entry = $this->resolveJitAotEntryScriptPath();

        return str_ends_with($entry, '/test/selfhost/compiler_lib_spine_smoke/main.php');
    }

    /**
     * M3/bootstrap selfhost entries under test/selfhost/ (not spine smoke) segfault when the VM
     * env-probe LLVM gate is emitted (#11005). User AOT scripts (examples/, app code) must keep the gate.
     */
    public function isBootstrapNonSpineSelfhostEntry(): bool
    {
        $entry = $this->resolveJitAotEntryScriptPath();
        if ('' === $entry) {
            return false;
        }

        return str_contains($entry, '/test/selfhost/') && !$this->isCompilerLibSpineSmokeEntry();
    }

    /**
     * VM env-probe LLVM gate is only for compiler_lib_spine_smoke (#8719, #8693). Bootstrap
     * test/selfhost/* entries (compiler_minimal, helloworld, …) call PHP main() directly — emitting
     * the gate for them segfaults at c:main_before_php (#10938, #11005). M3 compile-driver rebuild also skips.
     */
    private function shouldSkipStandaloneMainEnvProbeGate(): bool
    {
        if ($this->isThinStandaloneAotMain()) {
            return true;
        }
        $entry = $this->resolveJitAotEntryScriptPath();
        if ('' !== $entry && str_contains($entry, '/bootstrap-aot/')) {
            return true;
        }
        if ($this->isBootstrapNonSpineSelfhostEntry()) {
            return true;
        }
        $entry = $this->resolveJitAotEntryScriptPath();
        if ('' !== $entry && str_contains($entry, 'bootstrap-aot/')) {
            return true;
        }
        $bootstrapLink = Config::getenv('PHP_COMPILER_BOOTSTRAP_AOT_LINK');
        if ('1' === $bootstrapLink || 'true' === strtolower((string) $bootstrapLink)) {
            return true;
        }
        $flag = Config::getenv('PHP_COMPILER_M3_COMPILE_DRIVER_MAIN');

        return '1' === $flag || 'true' === strtolower((string) $flag);
    }

    public function hasJitIncludedFileCompiled(string $path): bool
    {
        $resolved = realpath($path);
        if (false === $resolved) {
            $resolved = $path;
        }
        $normalized = \PHPCompiler\VM\ScriptStack::normalize($resolved);
        if ('' === $normalized) {
            return false;
        }
        $key = $this->jitIncludeCompileScopeKey($normalized);

        return isset($this->jitAotIncludedCompileDone[$key]);
    }

    public function markJitIncludedFileCompiled(string $path): void
    {
        $resolved = realpath($path);
        if (false === $resolved) {
            $resolved = $path;
        }
        $normalized = \PHPCompiler\VM\ScriptStack::normalize($resolved);
        if ('' !== $normalized) {
            $this->jitAotIncludedCompileDone[$this->jitIncludeCompileScopeKey($normalized)] = true;
        }
    }

    /**
     * Module-global helper NestedJIT dedupe — statics must not split across activeFunction (#27566).
     */
    public function hasJitHelperTuCompiled(string $path): bool
    {
        $normalized = $this->normalizeJitHelperTuPath($path);

        return '' !== $normalized && isset($this->jitHelperTuCompiled[$normalized]);
    }

    public function markJitHelperTuCompiled(string $path): void
    {
        $normalized = $this->normalizeJitHelperTuPath($path);
        if ('' !== $normalized) {
            $this->jitHelperTuCompiled[$normalized] = true;
        }
    }

    private function normalizeJitHelperTuPath(string $path): string
    {
        $resolved = realpath($path);
        if (false === $resolved) {
            $resolved = $path;
        }

        return \PHPCompiler\VM\ScriptStack::normalize($resolved);
    }

    /** Per-LLVM-function include dedupe (#878): same path in different methods must re-inline. */
    private function jitIncludeCompileScopeKey(string $normalizedPath): string
    {
        return $this->activeFunction."\0".$normalizedPath;
    }

    /**
     * LLVM module-global __value__ slot for a script-level variable (#3601, #5393).
     *
     * Shared by {main} locals and `global $name` imports in nested functions.
     */
    public function ensureScriptGlobal(string $name): Variable
    {
        $storageKey = $name;
        if ($this->inlineIncludeDepth > 0 && '' !== $this->activeFunction) {
            $storageKey = $this->activeFunction."\0".$name;
        }
        if (!isset($this->jitGlobalVariables[$storageKey])) {
            if ('argv' === $name && null !== CliArgvGlobalInit::$global) {
                $this->jitGlobalVariables[$storageKey] = CliArgvGlobalInit::load($this);
            } elseif ('argc' === $name && null !== CliArgvGlobalInit::$argcGlobal) {
                $this->jitGlobalVariables[$storageKey] = CliArgvGlobalInit::loadArgc($this);
            } else {
                $globalName = 'phpc_script_global_'.substr(hash('sha256', 'script:'.$storageKey), 0, 16);
                $ptrTy = $this->getTypeFromString('__value__*');
                $global = $this->module->addGlobal($ptrTy, $globalName);
                $global->setInitializer($ptrTy->constNull());
                $scriptVar = new Variable(
                    $this,
                    Variable::TYPE_VALUE,
                    Variable::KIND_VALUE,
                    $global
                );
                $scriptVar->functionStaticGlobal = true;
                $this->jitGlobalVariables[$storageKey] = $scriptVar;
                $this->initScriptGlobalHeapBox($global);
            }
        }

        return $this->jitGlobalVariables[$storageKey];
    }

    private function initScriptGlobalHeapBox(PHPLLVM\Value $global): void
    {
        $restore = BasicBlockHelper::tryGetInsertBlock($this);
        $this->positionBuilderAtInitEmission();
        $valueType = $this->getTypeFromString('__value__');
        $heapVal = $this->memory->malloc($valueType);
        $heapPtr = $this->builder->pointerCast(
            $heapVal,
            $this->getTypeFromString('__value__*')
        );
        $this->builder->call(
            $this->lookupFunction('__value__writeNull'),
            $heapPtr
        );
        $this->builder->store($heapPtr, $global);
        if (null !== $restore) {
            BasicBlockHelper::restoreInsertBlock($this, $restore);
        } else {
            // NestedJIT helper compile can leave insert cleared; reopen so the
            // subsequent load of this global is parented (#32445).
            BasicBlockHelper::ensureOpenInsertBlock($this, 'script_global_after_init');
        }
    }

    public function bindVariableByName(string $name, Variable $var): void
    {
        $resolved = $this->resolveRefAliasName($name);
        if (isset($this->namedVariableBindings[$resolved])) {
            $existing = $this->namedVariableBindings[$resolved];
            // Instance-method FCC / fromCallable Closures must replace array-typed locals
            // (CFG types `$obj->m(...)` as array) so `$b()` sees the Closure object (#28613).
            if (
                null !== $var->closureCall
                && Variable::TYPE_OBJECT === $var->type
            ) {
                $this->namedVariableBindings[$resolved] = $var;
                foreach ($this->scope->variables as $scopeOp) {
                    if (!$scopeOp instanceof Operand) {
                        continue;
                    }
                    if ($resolved === OperandName::resolve($scopeOp)) {
                        $this->scope->variables[$scopeOp] = $var;
                    }
                }

                return;
            }
            // Closure use() snapshot reads must not rebind enclosing locals to MCJIT rvalues (#72).
            // `$r = &Class::$prop` must rebind onto the static global lvalue (#32036).
            // `$o->p =& $v` / `$a[] =& $v` must rebind onto property/dim KIND_VALUE lvalues (#34649).
            if (
                Variable::KIND_VARIABLE === $existing->kind
                && Variable::KIND_VALUE === $var->kind
                && null === $var->valueBoxAliasPtr
                && null === $var->staticPropertyGlobal
                && null === $var->objectPropertySlot
                && null === $var->writableHt
            ) {
                // FCC / Closure assigns still need invoke metadata on the stable lvalue (#24106, #24166).
                if (null !== $var->closureCall) {
                    $existing->closureCall = $var->closureCall;
                    $existing->closureIsStatic = $var->closureIsStatic;
                    $existing->closureIsMethodFake = $var->closureIsMethodFake;
                }

                return;
            }
        }
        $this->namedVariableBindings[$resolved] = $var;
        foreach ($this->scope->variables as $scopeOp) {
            if (!$scopeOp instanceof Operand) {
                continue;
            }
            if ($resolved === OperandName::resolve($scopeOp)) {
                $this->scope->variables[$scopeOp] = $var;
            }
        }
    }

}
