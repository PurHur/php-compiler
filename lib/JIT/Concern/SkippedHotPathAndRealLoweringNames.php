<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * Self-host skip / real-lowering name predicates for compiler hot paths (#36387).
 *
 * Extracted from {@see \PHPCompiler\JIT}: {@code isSkippedCompilerHotPathName}
 * through {@code isSuperglobalsRealLoweringMethod} so the hub shrinks toward
 * split-TU iterability under the size-budget ratchet.
 *
 * php-src: Zend/zend_compile.c compile_* hot paths; CGI/superglobal bootstrap
 * (sapi/cgi/cgi_main.c, main/php_variables.c) — move-only Concern extract; no
 * new C ABI and no opcode/IR shape change.
 */
trait SkippedHotPathAndRealLoweringNames
{
    private function isSkippedCompilerHotPathName(string $name): bool
    {
        $lower = strtolower($name);
        if ($this->isM3CompileDriverRealLoweringName($lower)) {
            return false;
        }
        if ($this->isM3EmitHelperCompilerPhpLoweringName($lower)) {
            return false;
        }
        if ($this->isM3CompileDriverCompilerPhpLoweringName($lower)) {
            return false;
        }
        if ($this->isM3CompileDriverCompilerNativeLoweringName($lower)) {
            return false;
        }
        if ($this->shouldUseM3EmitTuNativeBridge() && str_contains($lower, '\\compiler::compileemitsmoke')) {
            return false;
        }
        if ($this->shouldUseSelfHostJitStubs() && str_contains($lower, '\\compiler::')) {
            return true;
        }

        return str_contains($lower, 'splitcfgblockafterstringkeyedarray')
            || str_contains($lower, 'compilecfgblock')
            || str_contains($lower, 'compileblock')
            || str_contains($lower, 'compileops')
            || str_contains($lower, 'compileclasslike')
            || str_contains($lower, 'compileclassbody')
            || str_contains($lower, 'compilefunction')
            || str_contains($lower, 'compileglobalconst')
            || str_contains($lower, 'compilestmt')
            || str_contains($lower, 'compileop')
            || str_contains($lower, 'compileswitchasjumpifchain')
            || str_contains($lower, 'compileexpr')
            || str_contains($lower, 'getopcodetype')
            || str_contains($lower, 'compileissetmulti')
            || str_contains($lower, 'compileisset')
            || str_contains($lower, 'compilecoalesce')
            || str_contains($lower, 'compilenullsafe')
            || str_contains($lower, 'compileincludeop')
            || str_contains($lower, 'compileparam')
            || str_contains($lower, 'compileterminal')
            || str_contains($lower, 'compilefunccall')
            || str_contains($lower, 'tryfoldvariablefunctionname')
            || str_contains($lower, 'compilecallargsends')
            || str_contains($lower, 'callargunpack')
            || str_contains($lower, 'compilearrayliteral')
            || str_contains($lower, 'compilearraydimfetchread')
            || str_contains($lower, 'compilebooltemporary')
            || str_contains($lower, 'compileboolconstant')
            || str_contains($lower, 'compiletypeconstrainedvariable')
            || str_contains($lower, 'compileclassconstfetch')
            || str_contains($lower, 'compilefirstclasscallable')
            || str_contains($lower, 'compilefirstclassfunctionnameslot')
            || str_contains($lower, 'compilefirstclassstaticnameslot')
            || str_contains($lower, 'compileinstanceof')
            || str_contains($lower, 'trycompiledefineasglobalconst')
            || str_contains($lower, 'markcallerlocalsusedbyliteralinclude')
            || str_contains($lower, 'requireoperandslot')
            || str_contains($lower, 'resolvesimplevariablename')
            || str_contains($lower, 'unwrap')
            || str_contains($lower, 'needscfg')
            || str_contains($lower, 'inheritfuncfromparent')
            || str_contains($lower, 'isarraydim')
            || str_contains($lower, 'findcoalesce')
            || str_contains($lower, 'resolvecoalesce')
            || str_contains($lower, 'resolveisset')
            || str_contains($lower, 'isredundantcoalescetailassign');
    }

    private function isSkippedSelfHostEntryName(string $name): bool
    {
        if (!$this->shouldUseSelfHostJitStubs()) {
            return false;
        }
        $lower = strtolower($name);
        if ($this->isM3CompileDriverRealLoweringName($lower)) {
            return false;
        }
        if ($this->isM3EmitTuRuntimeSpineLoweringName($lower)) {
            return false;
        }
        // M4 inventory argv rebuild: {main} is native emitMainEntry — skip PHP argv driver bodies (#2930).
        if ($this->shouldUseM4InventoryArgvNativeEmitRebuild()) {
            if ('run' === $lower
                || str_ends_with($lower, '\\php_compiler_cli_dispatch')
                || str_ends_with($lower, '\\php_compiler_cli_should_run_entry_driver')
                || str_ends_with($lower, '\\php_compiler_cli_should_skip_entry_driver')
                || str_ends_with($lower, '\\php_compiler_cli_note_progress')
                || str_ends_with($lower, '\\php_compiler_cli_note_invocation_cwd')
                || str_ends_with($lower, '\\php_compiler_cli_minimal_autoload')
            ) {
                return true;
            }
        }
        // Inventory emit-helper bundles compile_driver.php; PHP CFG for argv driver crashes at {main} (#2540).
        if ($this->shouldStubInventoryEmitHelperBundledBodies()) {
            if ($this->isBootstrapHelloWorldSmokeName($lower)
                || str_contains($lower, 'compiler_helloworld_compile_driver')
                || 'compiler_smoke_greeting' === $lower
                || str_ends_with($lower, '\\compiler_smoke_greeting')
            ) {
                return true;
            }
        }
        // M3 compile-smoke wrapper: native bridge in emit TU only (#1983 approach 3, #1937).
        if ($this->shouldUseM3EmitTuNativeBridge() && $this->isBootstrapM3RuntimeEmitBridgeName($lower)) {
            return true;
        }
        // Self-host bundle includes Runtime/VM/Func for closure only; stub non-Compiler bodies (#913).
        if (str_contains($lower, '\\runtime::')
            || str_contains($lower, '\\func\\php::')
            || str_contains($lower, '\\func::')
            || str_contains($lower, '\\frame::')
            || str_contains($lower, '\\block::')
        ) {
            return true;
        }

        return str_ends_with($lower, '\\compiler::compilefunc')
            || str_ends_with($lower, '\\compiler::compile')
            || str_ends_with($lower, '\\jit\\type_pair')
            || str_ends_with($lower, '\\vm\\type_pair')
            || $this->isBootstrapRuntimeCtorSmokeName($lower)
            || ($this->isBootstrapHelloWorldSmokeName($lower) && !$this->shouldUseM3CompileDriverRealLowering())
            || ($this->isBootstrapM3RuntimeEmitBridgeName($lower) && !$this->shouldUseM3CompileDriverRealLowering());
    }

    private function isSkippedWebBootstrapHotPathName(string $name): bool
    {
        if (!$this->shouldUseSelfHostJitStubs()) {
            return false;
        }
        $lower = strtolower($name);
        return (str_contains($lower, '\\web\\includepathresolver::') && !$this->isIncludePathResolverRealLoweringMethod($lower))
            || (str_contains($lower, '\\web\\literalincludediscovery::') && !$this->isLiteralIncludeDiscoveryRealLoweringMethod($lower))
            || (str_contains($lower, '\\web\\deployroot::') && !$this->isDeployRootRealLoweringMethod($lower))
            || (str_contains($lower, '\\web\\sourcebundler::') && !$this->isSourceBundlerRealLoweringMethod($lower))
            || (str_contains($lower, '\\web\\conststringfolder::') && !$this->isConstStringFolderRealLoweringMethod($lower))
            || (str_contains($lower, '\\web\\superglobals::')
                && !$this->isSuperglobalsRealLoweringMethod($lower)
                && !str_ends_with($lower, '::issuperglobalname'));
    }

    /** Stub M2 lib spine smoke units (Doctor, Cli, Web drivers, ext/standard JIT leaves) for self-host AOT (#1056). */
    private function isSkippedLibSpineSmokeHotPathName(string $name): bool
    {
        if (!$this->shouldUseSelfHostJitStubs()) {
            return false;
        }
        $lower = strtolower($name);

        return str_contains($lower, '\\doctor::')
            || str_contains($lower, '\\cli\\')
            || str_contains($lower, '\\web\\cgiaotdriver::')
            || str_contains($lower, '\\web\\cgidriver::')
            || str_contains($lower, '\\web\\projectdeploy::')
            || str_contains($lower, '\\web\\manifestvalidator::')
            || str_contains($lower, '\\web\\projectmanifest::')
            || str_contains($lower, '\\web\\projectautoload::')
            || str_contains($lower, '\\web\\projectbootstrap::')
            || str_contains($lower, '\\web\\responsecontext::')
            || str_contains($lower, '\\web\\devserver::')
            || str_contains($lower, '\\web\\params::')
            || str_contains($lower, '\\aot\\')
            || str_contains($lower, '\\ext\\standard\\')
            || str_contains($lower, '\\ext\\types\\')
            || str_contains($lower, '\\jit\\varfetchhelper::')
            || str_contains($lower, '\\jit\\unsethelper::')
            || str_contains($lower, '\\jit\\arraybuiltinhelper::')
            || str_contains($lower, '\\jit\\reflectionbuiltinhelper::')
            || str_contains($lower, '\\jit\\typecheck::')
            || str_contains($lower, '\\jit\\errorhandlercallbackpolicy::')
            || str_contains($lower, '\\jit\\builtin\\stringparsestr::')
            || str_contains($lower, '\\builtinparamnames::')
            || str_contains($lower, '\\jit\\builtin\\type\\object_::')
            || str_contains($lower, '\\jit\\builtin\\type\\hashtable::')
            || ($this->shouldUseEmitHelperLinkStubs() && str_contains($lower, '\\phptypes\\'));
    }

    /** IncludePathResolver methods with safe LLVM 9 lowering during self-host AOT (#816). */
    private function isIncludePathResolverRealLoweringMethod(string $lower): bool
    {
        if (!$this->shouldUseM3CompileDriverRealLowering()) {
            return false;
        }

        return str_ends_with($lower, '\\web\\includepathresolver::resolve');
    }

    /**
     * LiteralIncludeDiscovery real LLVM lowering during M3 compile-driver link (#816, #2843).
     *
     * Entry points call private CFG walkers and ConstStringFolder::foldForInclude; stubbed callees
     * return empty paths and break include bundling in bin/compile.php bundles.
     */
    private function isLiteralIncludeDiscoveryRealLoweringMethod(string $lower): bool
    {
        if (!$this->shouldUseM3CompileDriverRealLowering()) {
            return false;
        }
        if ($this->shouldStubInventoryEmitHelperBundledBodies()) {
            return false;
        }
        foreach ([
            'discoverdirectabsolutepaths',
            'discoverabsolutepaths',
            'pathsfrommainscopeforbundle',
            'pathsfromscript',
            'walkcfgblock',
            'walkcfgblockforbundle',
            'walkcfgblockinternal',
            'isbundlescopeboundary',
        ] as $suffix) {
            if (str_ends_with($lower, '\\web\\literalincludediscovery::'.$suffix)) {
                return true;
            }
        }

        return false;
    }

    /** DeployRoot helpers needed by bin/compile.php include bundling (#1521). */
    private function isDeployRootRealLoweringMethod(string $lower): bool
    {
        if (!$this->shouldUseM3CompileDriverRealLowering()) {
            return false;
        }

        return str_ends_with($lower, '\\web\\deployroot::findprojectrootforpath')
            || str_ends_with($lower, '\\web\\deployroot::relativedirfromproject');
    }

    /** SourceBundler entry used when literal includes are folded into one TU (#1521). */
    private function isSourceBundlerRealLoweringMethod(string $lower): bool
    {
        if (!$this->shouldUseM3CompileDriverRealLowering()) {
            return false;
        }

        return str_ends_with($lower, '\\web\\sourcebundler::bundleforaot');
    }

    /** @var list<string> */
    private const WEB_BOOTSTRAP_STUBBED_SUPERGLOBALS_SUFFIXES = [
        'populatefromenvironment',
        'populatecliargv',
    ];

    private function isSuperglobalsStubbedMethod(string $lower): bool
    {
        foreach (self::WEB_BOOTSTRAP_STUBBED_SUPERGLOBALS_SUFFIXES as $suffix) {
            if (str_ends_with($lower, '\\web\\superglobals::'.$suffix)) {
                return true;
            }
        }

        return false;
    }

    private function isSuperglobalsRealLoweringMethod(string $lower): bool
    {
        if ($this->isSuperglobalsStubbedMethod($lower)) {
            return false;
        }
        if (str_ends_with($lower, '\\web\\superglobals::readrequestbody')
            || str_ends_with($lower, '\\web\\superglobals::exportcgienvironment')) {
            return true;
        }

        return $this->shouldUseM3CompileDriverRealLowering()
            && str_contains($lower, '\\web\\superglobals::');
    }

}
