<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Func\Internal as FuncInternal;
use PHPCompiler\Module;

/**
 * Function-proxy resolve + module/builtin registration for {@see Context} (#36387).
 *
 * NestedJIT / SPINE_CHUNK kernel whitelist helpers live in
 * {@see ContextFunctionProxyNestedJitKernelRegistry}; external-stub report + chunk
 * method-manifest export live in {@see ContextFunctionProxyExternalMethodStubReport}
 * (split-TU / size-budget ratchet toward ≤ 320 lines, #36199 / #36403).
 *
 * Used via {@code use ContextFunctionProxyAndNestedJitKernel;} on {@see Context}.
 *
 * No new C ABI. php-src analogy: Zend function table lookup and internal-function handlers
 * (Zend/zend_compile.c function_table, Zend/zend_execute_API.c) sit beside the executor proper.
 */
trait ContextFunctionProxyAndNestedJitKernel
{
    public function resolveFunctionProxy(string $proxyName): Call
    {
        $proxy = $this->lookupFunctionProxy($proxyName);
        if (null !== $proxy) {
            return $proxy;
        }
        if (LazyBuiltins::isEnabled($this->loadType) && $this->runtime->ensureJitBuiltinCompiled($proxyName)) {
            $proxy = $this->lookupFunctionProxy($proxyName);
            if (null !== $proxy) {
                return $proxy;
            }
        }
        $lc = strtolower($proxyName);
        $internal = $this->resolveRegisteredInternalBuiltin($lc);
        if (null !== $internal) {
            $this->functionProxies[$lc] = $internal;

            return $internal;
        }
        // Known helper-runtime / chunk-manifest symbols → real extern, not null (#24429).
        $bound = \PHPCompiler\AOT\ExternalMethodBind::tryBind($this, $proxyName);
        if (null !== $bound) {
            return $bound;
        }
        $intrinsic = SpineChunkIntrinsicCallBind::tryBind($this, $proxyName);
        if (null !== $intrinsic) {
            return $intrinsic;
        }
        $standard = SpineChunkStandardHelperBind::tryBind($this, $proxyName);
        if (null !== $standard) {
            return $standard;
        }
        $nestedObject = SpineChunkNestedVmBind::tryBindObjectStaticProxy($this, $lc);
        if (null !== $nestedObject) {
            return $nestedObject;
        }
        // User-script AOT rejects silent-null for registered-but-unlowered methods (#36202).
        // SPINE_CHUNK TUs are partial hubs: cross-chunk / VM-only methods must stay on
        // ExternalMethod stubs until peer-manifest bind (#24429 / #36387). compile.php always
        // latches AOT_USER_SCRIPT for -o emits, so without this gate every slim hub that
        // touches ffi::new (etc.) aborts before capacity-safe split-TU emit can finish.
        if ($this->isUserScriptAot()
            && !\PHPCompiler\AOT\ExternalMethodBind::spineChunkMode()
            && str_contains($lc, '::')
        ) {
            $vmOnly = self::findInternalClassMethodInVmRegistry($this, $lc);
            if (null !== $vmOnly && !self::internalBuiltinHasJitLowering($vmOnly)) {
                throw new \LogicException(
                    \sprintf('%s is registered in the VM but has no JIT lowering (#36202)', $proxyName)
                );
            }
        }
        $this->functionProxies[$lc] = new Call\ExternalMethod($proxyName);

        return $this->functionProxies[$lc];
    }

    private function lookupFunctionProxy(string $proxyName): ?Call
    {
        $lc = strtolower($proxyName);
        if (isset($this->functionProxies[$lc])) {
            $existing = $this->functionProxies[$lc];
            if ($existing instanceof Call\ExternalMethod) {
                $internal = $this->resolveRegisteredInternalBuiltin($lc);
                if (null !== $internal) {
                    $this->functionProxies[$lc] = $internal;

                    return $internal;
                }
                $bound = \PHPCompiler\AOT\ExternalMethodBind::tryBind($this, $proxyName);
                if (null !== $bound && !($bound instanceof Call\ExternalMethod)) {
                    return $bound;
                }
                $intrinsic = SpineChunkIntrinsicCallBind::tryBind($this, $proxyName);
                if (null !== $intrinsic && !($intrinsic instanceof Call\ExternalMethod)) {
                    $this->functionProxies[$lc] = $intrinsic;

                    return $intrinsic;
                }
                $standard = SpineChunkStandardHelperBind::tryBind($this, $proxyName);
                if (null !== $standard && !($standard instanceof Call\ExternalMethod)) {
                    $this->functionProxies[$lc] = $standard;

                    return $standard;
                }
                $nestedObject = SpineChunkNestedVmBind::tryBindObjectStaticProxy($this, $lc);
                if (null !== $nestedObject) {
                    $this->functionProxies[$lc] = $nestedObject;

                    return $nestedObject;
                }
            }

            return $existing;
        }
        if (preg_match('/^(.+)\\\\([^\\\\]+)::(.+)$/', $lc, $matches)) {
            $shortKey = $matches[2].'::'.$matches[3];
            if (isset($this->functionProxies[$shortKey])) {
                return $this->functionProxies[$shortKey];
            }
        }
        // NsFuncCall lowers unqualified calls to the current namespace (e.g.
        // PHPCompiler\Web\dirname); fall back to the global builtin when no
        // namespaced function exists in the bundle.
        if (str_contains($lc, '\\') && !str_contains($lc, '::')) {
            $globalFn = substr($lc, strrpos($lc, '\\') + 1);
            if (isset($this->functionProxies[$globalFn])) {
                return $this->functionProxies[$globalFn];
            }
            $internal = $this->resolveRegisteredInternalBuiltin($globalFn);
            if (null !== $internal) {
                $this->functionProxies[$globalFn] = $internal;

                return $internal;
            }
        }
        $internal = $this->resolveRegisteredInternalBuiltin($lc);
        if (null !== $internal) {
            $this->functionProxies[$lc] = $internal;

            return $internal;
        }
        if (DomInstanceMethodJit::isDomInstanceMethodProxy($lc)) {
            DomInstanceMethodJit::ensureProxy($this, $lc);
            if (isset($this->functionProxies[$lc])
                && !($this->functionProxies[$lc] instanceof Call\ExternalMethod)) {
                return $this->functionProxies[$lc];
            }
        }
        if (XmlReaderInstanceMethodJit::isXmlReaderInstanceMethodProxy($lc)
            && XmlReaderInstanceMethodJit::isUserScriptAot()
        ) {
            XmlReaderInstanceMethodJit::ensureProxy($this, $lc);
            if (isset($this->functionProxies[$lc])
                && !($this->functionProxies[$lc] instanceof Call\ExternalMethod)) {
                return $this->functionProxies[$lc];
            }
        }
        // Dom\HTMLDocument/XMLDocument factories: ext/dom/Module::jitInit (#36204).
        if (isset($this->functionProxies[$lc])
            && !($this->functionProxies[$lc] instanceof Call\ExternalMethod)) {
            return $this->functionProxies[$lc];
        }

        return null;
    }

    private function resolveRegisteredInternalBuiltin(string $lc): ?FuncInternal
    {
        foreach ($this->modules as $module) {
            $found = self::findInternalBuiltinInModule($module, $lc);
            if (null !== $found) {
                return $found;
            }
        }

        // Pre-registerModule NestedJIT (#15417): Context->modules is still empty so most
        // builtins stay ExternalMethod stubs. Allow only known *JitHelper kernel leaves
        // from Runtime modules so always-helper user-script AOT emits libc (#20290).
        if ([] === $this->modules
            && NestedJitCompileScope::isActive()
            && self::isPreRegisterModuleNestedJitKernel($lc)
            && [] !== $this->runtime->modules
        ) {
            foreach ($this->runtime->modules as $module) {
                $found = self::findInternalBuiltinInModule($module, $lc);
                if (null !== $found) {
                    return $found;
                }
            }
        }

        // Spine split-TU: chunk TUs may register their extension module while stdlib kernels
        // (explode, file_exists, …) live only on Runtime modules (#36147). After the chunk-local
        // scan above, fall back to Runtime for the whitelisted kernel set.
        if (\PHPCompiler\AOT\ExternalMethodBind::spineChunkMode()
            && self::isSpineChunkRuntimeInternalKernel($lc)
            && [] !== $this->runtime->modules
        ) {
            foreach ($this->runtime->modules as $module) {
                $found = self::findInternalBuiltinInModule($module, $lc);
                if (null !== $found) {
                    return $found;
                }
            }
        }

        // Registered builtin class methods with JIT lowering — one table for VM and JIT (#36202).
        // VM-only handlers stay on ExternalMethod until call() is implemented (helper infra).
        // Under SPINE_CHUNK only phpcompiler\vm\* — opening ext/standard via registry fails
        // module verify in isolation (#36147 / #15417).
        if (!\PHPCompiler\AOT\ExternalMethodBind::spineChunkMode()) {
            $vmMethod = self::findInternalClassMethodInVmRegistry($this, $lc);
            if (null !== $vmMethod && self::internalBuiltinHasJitLowering($vmMethod)) {
                return $vmMethod;
            }
        } elseif (self::isSpineChunkVmRegistryClass($lc)) {
            $vmMethod = self::findInternalClassMethodInVmRegistry($this, $lc);
            if (null !== $vmMethod && self::internalBuiltinHasJitLowering($vmMethod)) {
                return $vmMethod;
            }
        }

        return null;
    }

    /**
     * Whether a function name resolves to a builtin or user function in this compile unit (issue #1216).
     */
    public function functionIsRegistered(string $name): bool
    {
        $normalized = ltrim($name, '\\');
        $lc = strtolower($normalized);
        if (DomInstanceMethodJit::isDomInstanceMethodProxy($lc)) {
            DomInstanceMethodJit::ensureProxy($this, $lc);
        }
        if (SimpleXmlInstanceMethodJit::isSimpleXmlInstanceMethodProxy($lc)
            && UserScriptAotEnv::isActive()
        ) {
            SimpleXmlInstanceMethodJit::ensureProxy($this, $lc);
        }
        if (XmlReaderInstanceMethodJit::isXmlReaderInstanceMethodProxy($lc)
            && XmlReaderInstanceMethodJit::isUserScriptAot()
        ) {
            XmlReaderInstanceMethodJit::ensureProxy($this, $lc);
        }
        if (XmlWriterInstanceMethodJit::isXmlWriterInstanceMethodProxy($lc)
            && XmlWriterInstanceMethodJit::isUserScriptAot()
        ) {
            XmlWriterInstanceMethodJit::ensureProxy($this, $lc);
        }
        if ($this->functionProxyIsCallable($lc)) {
            return true;
        }
        $short = SelfHostBuiltinPolicy::normalizeName($name);
        if ($short !== $lc && $this->functionProxyIsCallable($short)) {
            return true;
        }
        if (isset($this->registeredBuiltinNames()[$lc]) || ($short !== $lc && isset($this->registeredBuiltinNames()[$short]))) {
            return true;
        }

        return isset($this->functions[$lc]) || ($short !== $lc && isset($this->functions[$short]));
    }

    /** @return array<string, true> */
    private function registeredBuiltinNames(): array
    {
        if (null !== $this->registeredBuiltinLookup) {
            return $this->registeredBuiltinLookup;
        }
        $this->registeredBuiltinLookup = [];
        foreach ($this->modules as $module) {
            foreach ($module->getFunctions() as $func) {
                $this->registeredBuiltinLookup[strtolower($func->getName())] = true;
            }
        }

        return $this->registeredBuiltinLookup;
    }

    /**
     * @return list<string> Lowercase user-defined function names compiled into this unit.
     */
    public function userFunctionNames(): array
    {
        $names = [];
        foreach ($this->functionProxies as $lc => $proxy) {
            if ($proxy instanceof Call\ExternalMethod || $proxy instanceof FuncInternal) {
                continue;
            }
            if ($proxy instanceof Call\Native || $proxy instanceof Call\Vararg) {
                $names[] = $lc;
            }
        }

        return array_values(array_unique($names));
    }

    private function functionProxyIsCallable(string $lc): bool
    {
        if (!isset($this->functionProxies[$lc])) {
            return false;
        }

        return !($this->functionProxies[$lc] instanceof Call\ExternalMethod);
    }

    public function registerModule(Module $module): void {
        $this->modules[] = $module;
        $this->registeredBuiltinLookup = null;
        $module->jitInit($this);
    }

    public function registerBuiltin(Builtin $builtin): void {
        $this->builtins[] = $builtin;
    }

    /** User examples or bootstrap-aot-link: thin standalone main without session/header reset LLVM (#13571, #14459). */
}
