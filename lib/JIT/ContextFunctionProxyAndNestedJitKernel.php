<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\CompilerVersion;
use PHPCompiler\Config;
use PHPCompiler\Func\Internal as FuncInternal;
use PHPCompiler\Module;
use PHPCompiler\OpCode;
use PHPLLVM;

/**
 * Function-proxy resolve + NestedJIT kernel / external-stub registry for {@see Context} (#36387).
 *
 * Extracted from {@see Context} so the ~680-line function-proxy and pre-registerModule
 * NestedJIT kernel cluster stays a separate TU from the Context construction / compile hub
 * (split-TU / size-budget ratchet; Context already under 4k after VariableOperandBinding).
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

    /** VM class methods safe to resolve from registry during SPINE_CHUNK chunk emits. */
    private static function isSpineChunkVmRegistryClass(string $lc): bool
    {
        if (!str_contains($lc, '::')) {
            return false;
        }
        $classLc = strtolower(explode('::', $lc, 2)[0]);

        return str_starts_with($classLc, 'phpcompiler\\vm\\');
    }

    /**
     * VM registry lookup for `Class::method` — one table for VM and JIT (#36202).
     */
    private static function findInternalClassMethodInVmRegistry(self $context, string $lc): ?FuncInternal
    {
        if (!str_contains($lc, '::')) {
            return null;
        }
        [$classLc, $methodLc] = explode('::', $lc, 2);
        $classLc = strtolower(ltrim($classLc, '\\'));
        $methodLc = strtolower($methodLc);
        if ('' === $classLc || '' === $methodLc) {
            return null;
        }

        $vm = $context->runtime->vmContext;
        while (isset($vm->classAliases[$classLc])) {
            $classLc = $vm->classAliases[$classLc];
        }
        if (!isset($vm->classes[$classLc])) {
            return null;
        }

        $entry = $vm->classes[$classLc];
        if (!isset($entry->methods[$methodLc])) {
            return null;
        }

        $method = $entry->methods[$methodLc];
        if (!$method instanceof FuncInternal) {
            return null;
        }

        return $method;
    }

    /** True when an Internal / VmClassMethod overrides {@see VmClassMethod::call()} for JIT. */
    private static function internalBuiltinHasJitLowering(FuncInternal $method): bool
    {
        if (!$method instanceof \PHPCompiler\VM\Builtin\VmClassMethod) {
            return true;
        }
        $ref = new \ReflectionMethod($method, 'call');

        return \PHPCompiler\VM\Builtin\VmClassMethod::class !== $ref->getDeclaringClass()->getName();
    }

    /**
     * Internal builtins safe to resolve from Runtime modules during SPINE_CHUNK chunk emits.
     *
     * Opening the full stdlib surface pulls in helpers that fail module verify in isolation
     * (e.g. strncasecmp LLVM verify — Instruction referencing instruction not embedded in a basic
     * block; #36147). Same constraint as NestedJIT pre-register whitelist (#15417).
     */
    private static function isSpineChunkRuntimeInternalKernel(string $lc): bool
    {
        return match ($lc) {
            'count',
            'explode',
            'file_exists',
            'in_array',
            'intdiv',
            'intval',
            'is_float',
            'is_nan',
            'is_numeric',
            'realpath',
            'rtrim',
            'sprintf',
            'str_contains',
            'strlen',
            'strpbrk',
            'strpos',
            'strtolower',
            'substr',
            'trim',
            => true,
            default => false,
        };
    }

    private static function findInternalBuiltinInModule(Module $module, string $lc): ?FuncInternal
    {
        foreach ($module->getFunctions() as $func) {
            if (!$func instanceof FuncInternal) {
                continue;
            }
            if (strtolower($func->getName()) === $lc) {
                return $func;
            }
        }

        return null;
    }

    /**
     * Kernels safe to resolve from Runtime modules during NestedJIT before
     * {@see registerModule()} — must not open the full stdlib Internal surface (#15417).
     */
    private static function isPreRegisterModuleNestedJitKernel(string $lc): bool
    {
        return match ($lc) {
            // file_put_contents NestedJIT leaf (#30127) — whitelist file_put_contents →
            // file_put_contents::call → JitFilePutContentsLibc (kernel Internal removed;
            // peer file_get_contents #29833 / readfile #29915).
            'file_put_contents',
            // readfile NestedJIT leaf (#29915) — whitelist readfile →
            // readfile::call → JitReadfileLibc (kernel Internal removed;
            // peer file_get_contents #29833 / crypt #29545).
            'readfile',
            // file_get_contents NestedJIT leaf (#29833) — whitelist file_get_contents →
            // file_get_contents::call → JitFileGetContentsLibc (kernel Internal removed;
            // peer crypt #29545 / random_bytes #29531; former kernel #29510 / #26756).
            'file_get_contents',

            // rename(2) NestedJIT leaf (#29141) — whitelist rename → rename_::call →
            // StringRename::invokeNestedLeaf (module-local rename(2); kernel removed).
            'rename',
            // link(2) NestedJIT leaf (#33406) — whitelist link → link_::call →
            // StringLink::invokeNestedLeaf (module-local link(2); peer rename #29141).
            'link',
            // symlink(2) NestedJIT leaf (#33417) — whitelist symlink → symlink_::call →
            // StringSymlink::invokeNestedLeaf (module-local symlink(2); peer link #33406).
            'symlink',
            // chown(2)/chgrp NestedJIT leaf (#32466) — whitelist → chown_/chgrp_::call →
            // JitChown/JitChgrp::invokeNestedLeaf (module-local chown/fchownat; peer rename).
            'chown',
            'lchown',
            'chgrp',
            'lchgrp',
            // chdir(2) NestedJIT leaf (#29219) — whitelist chdir → chdir_::call →
            // StringChdir::invokeNestedLeaf (module-local chdir(2); kernel removed).
            'chdir',
            // chroot(2) NestedJIT leaf (#30558) — whitelist chroot → chroot_::call →
            // StringChroot::invokeNestedLeaf (module-local chroot(2); always-on Module decl removed).
            'chroot',
            // proc_nice NestedJIT leaf (#30615) — whitelist proc_nice → proc_nice::call →
            // StringProcNice::invokeNestedLeaf (module-local nice(3); always-on JitProcNice LLVM removed).
            'proc_nice',
            // getcwd(2) NestedJIT leaf (#29429) — whitelist getcwd → getcwd_::call →
            // GetcwdJit::invokeNestedLeaf (module-local getcwd(2); always-on realpath LLVM removed).
            'getcwd',
            // sys_get_temp_dir NestedJIT leaf (#29433) — whitelist sys_get_temp_dir →
            // SysGetTempDirRuntime::invokeNestedLeaf (thin getenv/realpath; always-on LLVM removed).
            'sys_get_temp_dir',
            // tempnam NestedJIT leaf (#29940) — whitelist tempnam → tempnam::call →
            // StringTempnam / JitTempnamKernel mkstemp leaf (thin-AOT always-on kernel removed;
            // peer sys_get_temp_dir #29433 / gethostname #29364).
            'tempnam',
            // glob()/scandir() NestedJIT leaf (#29986) — whitelist → glob_/scandir::call →
            // JitFsGlob collectList / JitFsGlobKernel libc vec (thin-AOT always-on fork removed;
            // peer tempnam #29940 / sys_get_temp_dir #29433).
            'glob',
            'scandir',

            // getenv(3) NestedJIT leaf (#29313) — whitelist getenv → getenv_::call →
            // JitEnv::getenvNestedLeaf / StringGetenv::invokeNestedLeaf (kernel removed).
            'getenv',
            'phpc_ob_write_stdout_kernel',
            'phpc_url_rewriter_apply_kernel',
            'phpc_rewrite_vars_set_tags_kernel',
            // random_bytes NestedJIT leaf (#29531) — whitelist random_bytes → random_bytes::call →
            // JitRandomBytes::generate / JitRandomBytesKernel /dev/urandom leaf (kernel Internal removed).
            'random_bytes',
            // crypt NestedJIT leaf (#29545) — whitelist crypt → crypt::call →
            // JitLibcryptKernel libc crypt(3) (kernel Internal removed; peer random_bytes #29531).
            'crypt',
            // Password NestedJIT leaves (#26773) — peer random_bytes (#21186 / #29531) / hash crypto (#21026).
            'phpc_libcrypt_verify',
            'phpc_argon2_hash',
            'phpc_argon2_verify',
            // gethostname NestedJIT leaf (#29364) — whitelist gethostname → gethostname::call →
            // StringGethostname / JitGethostnameKernel /proc leaf (kernel Internal removed).
            'gethostname',
            // microtime NestedJIT leaf (#29405) — whitelist microtime → microtime::call →
            // StringMicrotime::invokeFloat/invokeString thin gettimeofday leaf.
            'microtime',
            // time NestedJIT leaf (#30332) — whitelist time → time::call →
            // StringTime::invoke / JitTimeKernel thin libc time(2) leaf.
            'time',
            // getmypid NestedJIT leaf (#30623) — whitelist getmypid → getmypid::call →
            // ProcessIdentityJit::getmypid / JitGetmypidKernel thin libc getpid(2) leaf
            // (former always-on ProcessIdentityJit getpid LLVM; peer time #30332).
            'getmypid',
            // posix_getpid NestedJIT leaf (#30696) — whitelist posix_getpid → posix_getpid::call →
            // PosixGetpidJit::invoke / JitGetmypidKernel thin libc getpid(2) leaf
            // (former always-on JitPosix::getpid LLVM; peer getmypid #30623).
            'posix_getpid',
            // ftok NestedJIT leaf (#31478) — whitelist ftok → ftok::call →
            // FtokRuntime::invoke / JitFtokKernel thin libc ftok(3) leaf
            // (former always-on FtokRuntime stat+layout LLVM; peer posix_getpid #30696).
            'ftok',
            // posix_getppid NestedJIT leaf (#30728) — whitelist posix_getppid → posix_getppid::call →
            // PosixGetppidJit::invoke / JitPosixGetppidKernel thin libc getppid(2) leaf
            // (former always-on JitPosix::getppid LLVM; peer posix_getpid #30696).
            'posix_getppid',
            // posix_getuid NestedJIT leaf (#30744) — whitelist posix_getuid → posix_getuid::call →
            // PosixGetuidJit::invoke / JitPosixGetuidKernel thin libc getuid(2) leaf
            // (former always-on JitPosix::getuid LLVM; peer posix_getppid #30728).
            'posix_getuid',
            // posix_geteuid NestedJIT leaf (#30767) — whitelist posix_geteuid → posix_geteuid::call →
            // PosixGeteuidJit::invoke / JitPosixGeteuidKernel thin libc geteuid(2) leaf
            // (former always-on JitPosix::geteuid LLVM; peer posix_getuid #30744).
            'posix_geteuid',
            // posix_getgid NestedJIT leaf (#30803) — whitelist posix_getgid → posix_getgid::call →
            // PosixGetgidJit::invoke / JitPosixGetgidKernel thin libc getgid(2) leaf
            // (former always-on JitPosix::getgid LLVM; peer posix_geteuid #30767).
            'posix_getgid',
            // posix_getegid NestedJIT leaf (#30986) — whitelist posix_getegid → posix_getegid::call →
            // PosixGetegidJit::invoke / JitPosixGetegidKernel thin libc getegid(2) leaf
            // (former always-on JitPosix::getegid LLVM; peer posix_getgid #30803).
            'posix_getegid',
            // posix_setuid NestedJIT leaf (#31038) — whitelist posix_setuid → posix_setuid::call →
            // PosixSetuidJit::invoke / JitPosixSetuidKernel thin libc setuid(2) leaf
            // (former always-on JitPosix::setId LLVM; peer posix_getegid #30986 / proc_nice #30615).
            'posix_setuid',
            // posix_setgid NestedJIT leaf (#31066) — whitelist posix_setgid → posix_setgid::call →
            // PosixSetgidJit::invoke / JitPosixSetgidKernel thin libc setgid(2) leaf
            // (former always-on JitPosix::setId LLVM; peer posix_setuid #31038).
            'posix_setgid',
            // posix_seteuid NestedJIT leaf (#31066) — whitelist posix_seteuid → posix_seteuid::call →
            // PosixSeteuidJit::invoke / JitPosixSeteuidKernel thin libc seteuid(2) leaf
            // (former always-on JitPosix::setId LLVM; peer posix_setuid #31038).
            'posix_seteuid',
            // posix_setegid NestedJIT leaf (#31066) — whitelist posix_setegid → posix_setegid::call →
            // PosixSetegidJit::invoke / JitPosixSetegidKernel thin libc setegid(2) leaf
            // (former always-on JitPosix::setId LLVM; peer posix_setuid #31038).
            'posix_setegid',
            // posix_setsid NestedJIT leaf (#31235) — whitelist posix_setsid → posix_setsid::call →
            // PosixSetsidJit::invoke / JitPosixSetsidKernel thin libc setsid(2) leaf
            // (former always-on JitPosix::setsid LLVM; peer posix_getpid #30696 / posix_setuid #31038).
            'posix_setsid',
            // posix_setpgid NestedJIT leaf (#31235) — whitelist posix_setpgid → posix_setpgid::call →
            // PosixSetpgidJit::invoke / JitPosixSetpgidKernel thin libc setpgid(2) leaf
            // (former always-on JitPosix::setpgid LLVM; peer posix_setuid #31038 / posix_setgid #31066).
            'posix_setpgid',
            // fnmatch(3) NestedJIT leaf (#30383) — whitelist fnmatch → fnmatch::call →
            // StringFnmatch::invokeNestedLeaf (module-local fnmatch(3); always-on Module decl removed).
            'fnmatch',
            // nl_langinfo(3) NestedJIT leaf (#30404) — whitelist nl_langinfo → nl_langinfo::call →
            // StringNlLanginfo / JitNlLanginfo libc leaf (always-on Module decl removed; peer fnmatch #30383).
            'nl_langinfo',
            // strxfrm(3) NestedJIT leaf (#30420) — whitelist strxfrm → strxfrm::call →
            // StringStrxfrm / JitStrxfrm libc leaf (always-on Module decl removed; peer nl_langinfo #30404).
            'strxfrm',
            // putenv(3) NestedJIT leaf (#29334) — whitelist putenv → putenv_::call →
            // JitEnv::putenvNestedLeaf / StringGetenv::invokePutenvNestedLeaf (kernel removed).
            'putenv',
            // Stat path always-helper NestedJIT leaves (#20742) — peer rename (#20603).
            'phpc_stat_mode_kernel',
            'phpc_access_kernel',
            // Hash crypto always-helper NestedJIT EVP leaves (#21026).
            'phpc_hash_crypto_hash',
            'phpc_hash_crypto_hmac',
            'phpc_hash_crypto_pbkdf2',
            'phpc_hash_crypto_hkdf' => true,
            default => false,
        };
    }

    public function recordExternalMethodStub(string $proxyName): void
    {
        $this->externalMethodStubs[strtolower($proxyName)] = true;
    }

    /**
     * Surface methods that lowered to a silent null because their class is not in this module (#579).
     *
     * {@see Call\ExternalMethod} turns such a call into `__value__writeNull` with no diagnostic, so a
     * module that is missing a class miscompiles quietly rather than failing to build. The record was
     * write-only until now; this makes it readable, which is what any split-module work needs in order
     * to tell "compiled into another unit" apart from "silently became null".
     *
     * PHP_COMPILER_REPORT_EXTERNAL_STUBS=1 logs them; PHP_COMPILER_FAIL_ON_EXTERNAL_STUBS=1 makes it
     * an error. Both are opt-in — some stubs are legitimate on bundles that intentionally exclude a
     * class, so this reports rather than assuming a defect.
     */
    public function reportExternalMethodStubs(): void
    {
        if ([] === $this->externalMethodStubs) {
            return;
        }
        $strict = '1' === Config::getenv('PHP_COMPILER_FAIL_ON_EXTERNAL_STUBS');
        if (!$strict && '1' !== Config::getenv('PHP_COMPILER_REPORT_EXTERNAL_STUBS')) {
            return;
        }

        $names = array_keys($this->externalMethodStubs);
        sort($names, SORT_STRING);
        $jsonPath = Config::getenv('PHP_COMPILER_EXTERNAL_STUBS_JSON');
        if (is_string($jsonPath) && '' !== $jsonPath) {
            $payload = [
                'stub_count' => count($names),
                'stubs' => $names,
                'generated_at' => gmdate('c'),
            ];
            $dir = dirname($jsonPath);
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException('cannot create directory for external stubs JSON: '.$dir);
            }
            file_put_contents(
                $jsonPath,
                json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n"
            );
        }
        $summary = sprintf(
            '%d method call(s) lowered to a silent null — class not in this module (#579): %s',
            count($names),
            implode(', ', array_slice($names, 0, 40)).(count($names) > 40 ? ', …' : '')
        );

        if ($strict) {
            throw new \RuntimeException('external method stubs: '.$summary);
        }
        if (\defined('STDERR') && \is_resource(STDERR)) {
            fwrite(STDERR, 'phpc: external method stubs — '.$summary."\n");
        }
    }

    /**
     * Producer chunk: write logical→symbol manifest for cross-TU bind (#36155 Phase C).
     *
     * PHP_COMPILER_EXTERNAL_METHOD_MANIFEST_EXPORT=/path/to/manifest.json
     * PHP_COMPILER_EMIT_BITCODE=/path/to/chunk.bc  (optional; recorded as relative path)
     */
    private function exportChunkMethodManifestIfRequested(): void
    {
        $exportPath = Config::getenv('PHP_COMPILER_EXTERNAL_METHOD_MANIFEST_EXPORT');
        if (!is_string($exportPath) || '' === $exportPath) {
            return;
        }
        $methods = [];
        foreach ($this->functionLlvmSymbols as $logical => $symbol) {
            if (!is_string($logical) || '' === $logical || !is_string($symbol) || '' === $symbol) {
                continue;
            }
            $methods[strtolower($logical)] = ['symbol' => $symbol];
        }
        ksort($methods, SORT_STRING);
        $bitcodeEnv = Config::getenv('PHP_COMPILER_EMIT_BITCODE');
        $bitcodeRel = null;
        if (is_string($bitcodeEnv) && '' !== $bitcodeEnv) {
            $manifestDir = dirname($exportPath);
            $bitcodeAbs = $bitcodeEnv;
            if (!str_starts_with($bitcodeAbs, '/')) {
                $bitcodeAbs = getcwd().'/'.$bitcodeAbs;
            }
            if (str_starts_with($bitcodeAbs, $manifestDir.'/')) {
                $bitcodeRel = substr($bitcodeAbs, \strlen($manifestDir) + 1);
            } else {
                $bitcodeRel = basename($bitcodeAbs);
            }
        }
        $payload = [
            'bitcode' => $bitcodeRel,
            'method_count' => count($methods),
            'methods' => $methods,
            'generated_at' => gmdate('c'),
        ];
        $dir = dirname($exportPath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('cannot create directory for chunk method manifest: '.$dir);
        }
        file_put_contents(
            $exportPath,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n"
        );
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
