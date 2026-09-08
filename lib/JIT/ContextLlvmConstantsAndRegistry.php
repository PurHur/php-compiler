<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM;

/**
 * LLVM function/type registry for {@see Context} (#36387).
 *
 * Extracted from {@see Context} so lookupFunction / registerType / namedStructType
 * stay a separate TU from compile-time constant materialization
 * ({@see ContextLlvmConstantEmit}) and from Context construction / compile hubs
 * (split-TU / size-budget ratchet).
 *
 * Used via {@code use ContextLlvmConstantsAndRegistry;} on {@see Context}.
 *
 * No new C ABI. php-src analogy: function_table / type registration live beside
 * the executor (Zend/zend_compile.c, Zend/zend_execute_API.c) rather than inside
 * request startup or constant materialization forever.
 */
trait ContextLlvmConstantsAndRegistry
{
    public function lookupFunction(string $name): PHPLLVM\Value\Function_ {
        // Lazy __value__writeDouble — Value::implement no longer eager-implements (#36141 /
        // peer #36135 writeLong). Thin hello-world must not emit double-box LLVM during init.
        if ('__value__writeDouble' === $name) {
            Builtin\ValueBoxWriteDoubleJit::ensureLinked($this);
        }
        // Lazy __value__writeLong — Value::implement no longer eager-implements (#36135 /
        // peer #36124 writeNull). Thin hello-world must not emit long-box LLVM during init.
        if ('__value__writeLong' === $name) {
            Builtin\ValueBoxWriteLongJit::ensureLinked($this);
        }
        // Lazy __value__writeNull — Value::implement no longer eager-implements (#36124 /
        // peer #36108 writeBool). Thin hello-world must not emit null-box LLVM during init.
        if ('__value__writeNull' === $name) {
            Builtin\ValueBoxWriteNullJit::ensureLinked($this);
        }
        // Lazy __value__writeBool — Value::implement no longer eager-implements (#36108 /
        // peer #36100 malloc). Thin hello-world must not emit bool-box LLVM during init.
        if ('__value__writeBool' === $name) {
            Builtin\ValueBoxWriteBoolJit::ensureLinked($this);
        }
        // Lazy __value__copy — one outlined type-switch per module (#36193).
        if ('__value__copy' === $name) {
            Builtin\ValueBoxCopyJit::ensureLinked($this);
        }
        if (isset($this->functionScope[$name])) {
            return $this->functionScope[$name];
        }
        // After edit-scaffold / bitcode restore, prefer live module over stale scope (#36387).
        $fromModule = $this->module->getNamedFunction($name);
        if ($fromModule instanceof PHPLLVM\Value\Function_) {
            try {
                // php-llvm may wrap a null LLVMValueRef; probe before trusting it.
                $fromModule->countParams();
                $this->functionScope[$name] = $fromModule;

                return $fromModule;
            } catch (\Throwable $e) {
                // fall through to lazy ensure / throw
            }
        }
        // Lazy libc exit(3)/abort(3) — Type::register no longer always-on ensures (#35428 /
        // leftover #33267 / peer #35392). ~292 call sites lookup without a nearby ensure.
        // Lazy setlocale(3) — LocaleStartupRuntime ensures before use (#36074 / #30789).
        // Lazy malloc/realloc/free — MemoryManager\Native::implement + NestedJIT leaves
        // (#36100 / peer #32273); register() no longer eager-ensures the family.
        if ('exit' === $name || 'abort' === $name) {
            LibcExtern::ensureExitAbort($this);
            if (isset($this->functionScope[$name])) {
                return $this->functionScope[$name];
            }
        }
        if ('setlocale' === $name) {
            LibcExtern::ensureSetlocaleDecl($this);
            if (isset($this->functionScope[$name])) {
                return $this->functionScope[$name];
            }
        }
        if ('malloc' === $name || 'realloc' === $name || 'free' === $name) {
            LibcExtern::ensureMallocFamily($this);
            if (isset($this->functionScope[$name])) {
                return $this->functionScope[$name];
            }
        }
        // Lazy stream I/O ABI — Type::register no longer always-on ensures (#33055).
        // SPINE_CHUNK standard hub (VmFs slice) lowers fread before StreamIo::ensureLinked
        // (#36155 Phase B/C); JitFread::invoke lookupFunction must self-ensure.
        if (Builtin\StreamIoRuntime::isLazyLookupRuntimeFunction($name)) {
            Builtin\StreamIoRuntime::ensureLinkedForUserScriptLowering($this);
            if (isset($this->functionScope[$name])) {
                return $this->functionScope[$name];
            }
        }
        throw new \LogicException('Unable to lookup non-existing function ' . $name);
    }

    /** Scope probe for LibcExtern ensure* without re-entering lookupFunction (#35428). */
    public function tryGetRegisteredFunction(string $name): ?PHPLLVM\Value\Function_ {
        return $this->functionScope[$name] ?? null;
    }

    public function registerFunction(string $name, PHPLLVM\Value\Function_ $func): void {
        $this->functionScope[$name] = $func;
    }

    public function registerType(string $name, PHPLLVM\Type $type): void {
        $this->typeMap[$name] = $type;
    }

    /**
     * Prefer an existing module named struct (edit-scaffold bitcode) over CreateNamed (#36387).
     *
     * CreateNamed after parseBitcode uniqueifies (__string__.11) and breaks GEPs.
     */
    public function namedStructType(string $name): PHPLLVM\Type
    {
        if (CompileCache::isEditScaffoldActive() || null !== CompileCache::pendingEditScaffoldKey()) {
            try {
                $existing = $this->module->getTypeByName($name);
                if ($existing instanceof PHPLLVM\Type\Struct) {
                    return $existing;
                }
                if ($existing instanceof PHPLLVM\Type) {
                    try {
                        $existing->getKind();

                        return $existing;
                    } catch (\Throwable $e) {
                        // null wrapper — fall through
                    }
                }
            } catch (\Throwable $e) {
                // fall through to CreateNamed
            }
        }

        return $this->context->namedStructType($name);
    }

    /**
     * setBody only when the named struct is still opaque (bitcode already defined it) (#36387).
     */
    public function setNamedStructBody(PHPLLVM\Type $struct, bool $packed, PHPLLVM\Type ...$elements): void
    {
        if ($struct instanceof PHPLLVM\Type\Struct) {
            try {
                if (!$struct->isOpaque()) {
                    return;
                }
            } catch (\Throwable $e) {
                // try setBody anyway
            }
        }
        if (!method_exists($struct, 'setBody')) {
            return;
        }
        $struct->setBody($packed, ...$elements);
    }
}
