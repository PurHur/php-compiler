<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * M3/M4/M5 compile-driver / inventory emit policy predicates (#36387).
 *
 * Extracted from {@see \PHPCompiler\JIT}: {@code shouldUseM3CompileDriverMainNative}
 * through {@code isM3CompileDriverBlockPhpLoweringName} so the hub shrinks toward
 * split-TU iterability under the size-budget ratchet.
 *
 * php-src: Zend/zend_compile.c compile_file / compile_string driver paths;
 * Zend/zend_execute_API.c — move-only Concern extract; no new C ABI and no
 * opcode/IR shape change.
 */
trait M3M4M5CompileDriverEmitPolicy
{
    /** Opt-in when linking test/selfhost compile_driver.php bundles (#1056, #1768). */
    private function shouldUseM3CompileDriverMainNative(): bool
    {
        if (!$this->shouldUseM3CompileDriverRealLowering()) {
            return false;
        }
        if ($this->shouldUseM4BinCompileArgvMainNative()) {
            return true;
        }
        $flag = Config::getenv('PHP_COMPILER_M3_COMPILE_DRIVER_MAIN');

        return '1' === $flag || 'true' === strtolower((string) $flag);
    }

    /**
     * True when the active entry is helloworld/bootstrap_loop compile_driver.php under a compiled argv driver.
     *
     * Zend bin/compile.php sets inventory emit env when linking compile_driver.php; native emitMainEntry
     * does not run those putenv hooks, so gate inventory {main} from the entry path (#3053).
     */
    private function isM3HelloworldInventoryCompileDriverTarget(?Block $block = null): bool
    {
        if (!\function_exists('php_compiler_cli_should_skip_entry_driver')) {
            return false;
        }
        /** @var list<string> $paths */
        $paths = [];
        if (null !== $block) {
            $path = $block->scriptPath();
            if ('' !== $path) {
                $paths[] = $path;
            }
        }
        if (null !== $this->m3CompileDriverMainBlock) {
            $path = $this->m3CompileDriverMainBlock->scriptPath();
            if ('' !== $path) {
                $paths[] = $path;
            }
        }
        $fromCtx = $this->context->aotSourceFilename ?? '';
        if ('' !== $fromCtx) {
            $paths[] = $fromCtx;
        }
        foreach (array_unique($paths) as $path) {
            $norm = str_replace('\\', '/', $path);
            if (str_contains($norm, 'compiler_helloworld_smoke/compile_driver.php')
                || str_contains($norm, 'bootstrap_loop_smoke/compile_driver.php')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Inventory-scale M3 emit via compile_driver.php {main} — no separate *_m3_emit_native_entry.php (#2843).
     */
    private function shouldUseM3InventoryEmitDriver(?Block $block = null): bool
    {
        if (!$this->shouldUseM3CompileDriverMainNative()) {
            return false;
        }
        // M4/M5 bin/compile.php host link uses real argv {main} unless inventory emit is explicit (#3004).
        foreach (['PHP_COMPILER_M3_INVENTORY_EMIT_DRIVER', 'BOOTSTRAP_M3_USE_INVENTORY_EMIT_DRIVER'] as $envKey) {
            $flag = getenv($envKey);
            if ('1' === $flag || 'true' === strtolower((string) $flag)) {
                return true;
            }
        }
        if ($this->isM3HelloworldInventoryCompileDriverTarget($block)) {
            return true;
        }

        return false;
    }

    /**
     * Gen-2 helloworld-prefix argv driver re-linking bin/compile.php — inventory {main}, not stub sidecar (#3011).
     */
    private function shouldUseHelloworldBinCompileInventoryArgvLink(): bool
    {
        // Only consulted together with isM4BinCompileScriptMain() — always inventory argv link (#3011).
        return true;
    }

    private function shouldUseM3InventoryEmitForCompileDriverBlock(Block $block): bool
    {
        // M4 bin/compile.php argv driver uses emitMainEntry argv bridge, not compile_driver emit TU (#2930).
        if ($this->isM4BinCompileScriptMain($block) && $this->shouldUseM4BinCompileArgvMainNative()) {
            return false;
        }
        if ($this->shouldUseM3InventoryEmitDriver()) {
            return true;
        }

        return $this->isM4BinCompileScriptMain($block) && $this->shouldUseHelloworldBinCompileInventoryArgvLink();
    }

    /**
     * Inventory argv drivers must real-lower Runtime::parse when not on the M4 stub-spine rebuild path (#2967, #3028).
     */
    private function shouldRealLowerInventoryArgvParseSpine(): bool
    {
        // M5 argv / gen-0 seed: force real parse spine even when M4 bin/compile.php
        // inventory-emit-for-block is false (#26756 / re-#23468).
        if ($this->shouldUseM5DriverHostCompile() && $this->shouldUseM3CompileDriverRealLowering()) {
            return true;
        }

        return $this->shouldUseM3InventoryEmitDriver() && $this->shouldUseM3CompileDriverRealLowering();
    }

    /** Register Runtime parse-diagnostic LLVM stubs for helloworld bin/compile.php inventory argv (#12036). */
    private function shouldEnsureInventoryArgvParseHelperStubs(): bool
    {
        if ($this->shouldRealLowerInventoryArgvParseSpine()) {
            return true;
        }
        $m3Driver = Config::getenv('PHP_COMPILER_M3_COMPILE_DRIVER');
        if ('1' !== $m3Driver && 'true' !== strtolower((string) $m3Driver)) {
            return false;
        }
        $main = $this->m3CompileDriverMainBlock ?? $this->m3EmitTuMainBlock;

        return null !== $main && $this->isM4BinCompileScriptMain($main);
    }

    /**
     * Inventory argv driver real-lowers Runtime::parse but not the full preprocess rewriter chain
     * (SealedClassPreprocessor, PropertyHooks, …) — identity LLVM stubs suffice for gen-0 refresh (#11809).
     */
    private function shouldStubInventoryArgvPreprocessSpineMethods(): bool
    {
        return $this->shouldRealLowerInventoryArgvParseSpine();
    }

    /** Inventory emit TU is compile_driver.php — do not host-compile it again as a link sidecar (#2843). */
    private function shouldSkipM3InventoryEmitDriverSelfSidecar(string $path): bool
    {
        if (!$this->shouldUseM3InventoryEmitDriver()) {
            return false;
        }

        $norm = str_replace('\\', '/', $path);
        if (!str_contains($norm, 'test/selfhost/') || !str_contains($norm, '/compile_driver.php')) {
            return false;
        }
        $mainBlock = $this->m3CompileDriverMainBlock ?? $this->m3EmitTuMainBlock;
        if (null === $mainBlock) {
            return false;
        }
        $mainPath = str_replace('\\', '/', $mainBlock->scriptPath());

        return $mainPath === $norm || str_ends_with($mainPath, '/'.basename($norm));
    }

    private function isM3CompileDriverScriptMain(Block $block): bool
    {
        return null !== $block->func
            && null === $block->func->class
            && '{main}' === $block->func->name;
    }

    /**
     * Host-compile a functional production driver (bin/compile.php) — not link-only sidecar bytes (#1521).
     *
     * Sidecar registration keeps {main} stubbed; set this env when emitting a driver that must run argv/compile.
     */
    private function shouldUseM5DriverHostCompile(): bool
    {
        $flag = Config::getenv('PHP_COMPILER_M5_DRIVER_HOST');

        return '1' === $flag || 'true' === strtolower((string) $flag);
    }

    /**
     * C-floor Runtime::parse (+ PHPCfg peers) — inventory argv compile_driver host link
     * segfaults on NestedJIT PHP CFG (#26756, #36144); same path as M5 argv seed.
     */
    private function shouldUseM5ParseSpineCFloor(): bool
    {
        return $this->shouldUseM5DriverHostCompile() || $this->shouldRealLowerInventoryArgvParseSpine();
    }

    /** Register PHPCfg peers + RuntimeParseM5Native before inventory argv parse spine (#27426). */
    private function ensureM5ParseSpineCFloorSymbols(): void
    {
        if (!$this->shouldUseM5ParseSpineCFloor()) {
            return;
        }
        $m5ForceParserCbs = [
            $this->context,
            fn (string $n): string => $this->llvmInternalName($n),
            function (callable $body): void {
                JIT\NestedJitCompileScope::run($this->context, $body);
            },
            function ($block, string $logical): void {
                $this->compileBlock($block, $logical);
            },
            function (string $logical, $cfgFunc) {
                return $this->context->runtime->compileFunc($logical, $cfgFunc);
            },
            function (string $code, string $path) {
                return $this->context->runtime->parse($code, $path);
            },
        ];
        JIT\RuntimeParseM5AstPeer::ensureMethods(...$m5ForceParserCbs);
        JIT\RuntimeParseM5PhpCfgParser::ensureParse(...$m5ForceParserCbs);
        if ($this->shouldUseM5DriverHostCompile()) {
            $m5TrivialNested = Config::getenv('PHP_COMPILER_M5_TRIVIAL_ECHO_NESTEDJIT');
            if ('1' === $m5TrivialNested || 'true' === strtolower((string) $m5TrivialNested)) {
                $this->ensureM5TrivialEchoScriptParseAndCompileLowered();
            } else {
                JIT\M5TrivialEchoNative::ensureParseAndCompile(
                    $this->context,
                    fn (string $n): string => $this->llvmInternalName($n)
                );
            }
        }
        $parseLogical = 'PHPCompiler\\Runtime::parse';
        $parseLc = strtolower($parseLogical);
        if (!isset($this->context->functions[$parseLc])) {
            JIT\RuntimeParseM5Native::emitFunction(
                $this->context,
                $this->llvmInternalName($parseLogical),
                $parseLogical,
                fn (string $n): string => $this->llvmInternalName($n)
            );
        }
    }

    /**
     * NestedJIT of PHPCfg\Parser::parse under M5 argv host-compile (#27426 / #26756).
     *
     * Vendor parse() has no PHP type hints; CFG defaults to __value__ params/return while
     * RuntimeParseM5Native calls (__object__*, __string__*, __string__*) -> __object__*.
     */
    private function isM5NestedJitPhpCfgParserParse(?string $logicalName): bool
    {
        if (null === $logicalName
            || !$this->shouldUseM5DriverHostCompile()
            || !JIT\NestedJitCompileScope::isActive()
        ) {
            return false;
        }
        $lc = strtolower($logicalName);
        if ('phpcfg\\parser::parse' === $lc
            || 'php\\cfg\\parser::parse' === $lc
            || (str_ends_with($lc, '\\parser::parse') && str_contains($lc, 'cfg'))
        ) {
            return true;
        }
        // activeFunction / llvmInternalName may be mangled PHPCfg_Parser__parse
        if ('phpcfg_parser__parse' === $lc) {
            return true;
        }
        if (str_ends_with($lc, '_parser__parse') && str_contains($lc, 'cfg')) {
            return true;
        }

        return false;
    }

    /**
     * NestedJIT of M5ParserAstPeer::parse under M5 argv (#27426).
     * Typed string $code must stay __string__* for Parser::parse call sites.
     */
    private function isM5NestedJitM5ParserAstPeerParse(?string $logicalName): bool
    {
        if (null === $logicalName
            || !$this->shouldUseM5DriverHostCompile()
            || !JIT\NestedJitCompileScope::isActive()
        ) {
            return false;
        }
        $lc = strtolower($logicalName);

        return 'phpcompiler\\jit\\m5parserastpeer::parse' === $lc
            || 'm5parserastpeer::parse' === $lc
            || str_ends_with($lc, '\\m5parserastpeer::parse')
            || 'phpcompiler_jit_m5parserastpeer__parse' === $lc
            || str_ends_with($lc, '_m5parserastpeer__parse');
    }

    /**
     * Return-ABI string for the function currently being lowered.
     * Prefers the LLVM signature forced at create (Parser::parse → __object__*) over
     * untyped CFG default __value__ (#27426).
     */
    private function effectiveReturnCallbackType(?\PHPCfg\Func $cfgFunc): ?string
    {
        if ($this->isM5NestedJitPhpCfgParserParse($this->context->activeFunction)) {
            return '__object__*';
        }
        $expected = $this->cfgFunctionReturnCallbackType($cfgFunc);
        if (null === $expected && null !== $this->context->activeFunction) {
            $expected = $this->context->functionReturnType[strtolower($this->context->activeFunction)] ?? null;
        }

        return $expected;
    }

    /** Identity prepare/preprocess/rewrite stubs before parse host-lower (#26756 / #11809). */
    private function ensureM5ArgvPrepareSpineIdentityStubs(): void
    {
        JIT\RuntimePrepareSpineIdentity::ensure(
            $this->context,
            fn (string $logical): string => $this->llvmInternalName($logical),
            function (string $logical, $func, array $args, array $defaults): void {
                $this->context->functionProxies[strtolower($logical)] = new JIT\Call\Native(
                    $func,
                    $logical,
                    $args,
                    $defaults
                );
            }
        );
    }

    /**
     * Native argv {main} for production bin/compile.php (M4 full revision / BIN_COMPILE sidecar — #2880).
     */
    private function shouldUseM4BinCompileArgvMainNative(): bool
    {
        if (!$this->shouldUseM3CompileDriverRealLowering()) {
            return false;
        }
        if ($this->shouldUseM5DriverHostCompile()) {
            return true;
        }
        $flag = Config::getenv('PHP_COMPILER_M4_BIN_COMPILE_DRIVER');

        return '1' === $flag || 'true' === strtolower((string) $flag);
    }

    private function isM4BinCompileScriptMain(Block $block): bool
    {
        if (!$this->isM3CompileDriverScriptMain($block)) {
            return false;
        }
        $path = str_replace('\\', '/', $block->scriptPath());
        if ('' === $path) {
            $fromCtx = $this->context->aotSourceFilename ?? '';
            $path = str_replace('\\', '/', is_string($fromCtx) ? $fromCtx : '');
        }

        return str_ends_with($path, '/bin/compile.php');
    }

    /** True when lowering targets script {main}, not spine stubs that reuse the compile-driver CFG block. */
    private function isM4BinCompileNativeMainLogicalName(?string $logicalName): bool
    {
        if (null === $logicalName) {
            return true;
        }

        return '{main}' === strtolower($logicalName);
    }

    /** Drop queued bin/compile.php {main} PHP lowering when native argv rebuild owns {main} (#2930). */
    private function filterM4InventoryArgvMainFromQueue(): void
    {
        $this->queue = array_values(array_filter(
            $this->queue,
            function (array $item): bool {
                $cfg = $item[1] ?? null;
                if (!$cfg instanceof Block) {
                    return true;
                }

                return !$this->isM4BinCompileScriptMain($cfg)
                    || !(
                        $this->shouldUseM4BinCompileArgvMainNative()
                        || $this->shouldUseHelloworldBinCompileInventoryArgvLink()
                    );
            }
        ));
    }

    /**
     * Inventory argv link real-lowers parse/init spine; stub rebuild is M4-only without emit driver (#8708).
     */
    private function shouldUseM4InventoryArgvNativeEmitRebuild(?Block $block = null): bool
    {
        if (!$this->shouldUseM4BinCompileArgvMainNative() || $this->shouldUseM5DriverHostCompile()) {
            return false;
        }
        if ($this->shouldUseM3InventoryEmitDriver()) {
            return false;
        }
        $main = $block ?? $this->m3CompileDriverMainBlock;
        if (null === $main || !$this->isM4BinCompileScriptMain($main)) {
            return false;
        }

        return !$this->shouldUseM3InventoryEmitForCompileDriverBlock($main);
    }

    /** M5 emit sidecar host-compile targets — stub {main} under self-host AOT (#2697, #2699). */
    private function isM5BootstrapSidecarScriptMain(Block $block): bool
    {
        if ($this->shouldUseM5DriverHostCompile()) {
            return false;
        }
        if (!$this->isM3CompileDriverScriptMain($block)) {
            return false;
        }
        $path = $block->scriptPath();

        // bin/compile.php needs real {main} for native CLI driver sidecars (#2697).
        return str_ends_with($path, '/bin/vm.php')
            || str_ends_with($path, '/src/cli_driver.php');
    }

    private function isM3CompileDriverBundleScriptMain(Block $block): bool
    {
        if (!$this->isM3CompileDriverScriptMain($block)) {
            return false;
        }

        return str_contains($block->scriptPath(), 'compile_driver.php');
    }

    /** Opt-in when linking test/selfhost/compiler_helloworld_smoke/compile_driver.php (#1056). */
    private function shouldUseM3CompileDriverRealLowering(): bool
    {
        $flag = Config::getenv('PHP_COMPILER_M3_COMPILE_DRIVER');
        if ('1' !== $flag && 'true' !== strtolower((string) $flag)) {
            return false;
        }
        // M5 argv / gen-0 seed: keep compile-driver allowlist even if user-script AOT
        // briefly cleared SELFHOST_AOT (#26756 / re-#23468).
        if ($this->shouldUseM5DriverHostCompile()) {
            return true;
        }

        return $this->shouldUseSelfHostJitStubs();
    }

    /**
     * Large Composer IncludeHelper graphs: NestedJIT PregJitHelperThinAot while the LLVM
     * module is still small. Mid-graph first use (Nyholm Uri::withUserInfo) stalls for
     * minutes as NestedJIT walks a fat module (#36382).
     *
     * Prefer {@see Runtime::standalone} eager link (flag {@see Runtime::$eagerThinPregHelpers});
     * this helper remains for call sites that set the flag after Context load.
     * Nyholm graphs should use {@see Runtime::$eagerUriComposerHelpers} instead — eager thin
     * preg fattens the module before Uri lowering.
     */
    private function maybeEagerLinkThinPregHelpers(): void
    {
        if (!$this->context->runtime->eagerThinPregHelpers) {
            return;
        }
        // Consume once — NestedJIT of the preg bundle re-enters compile() and must not loop.
        $this->context->runtime->eagerThinPregHelpers = false;
        if (JIT\NestedJitCompileScope::isActive()) {
            return;
        }
        JIT\Progress::noteFunction('eager_thin_preg_begin');
        JIT\Builtin\PregMatchRuntime::ensureLinked($this->context);
        JIT\Progress::noteFunction('eager_thin_preg_done');
    }

    /**
     * Eager NestedJIT UriRawurlencodeReplaceJitHelper + ParseUrl before IncludeHelper fattens
     * the module (#36382). Peer of {@see maybeEagerLinkThinPregHelpers}.
     */
    private function maybeEagerLinkUriComposerHelpers(): void
    {
        if (!$this->context->runtime->eagerUriComposerHelpers) {
            return;
        }
        $this->context->runtime->eagerUriComposerHelpers = false;
        if (JIT\NestedJitCompileScope::isActive()) {
            return;
        }
        JIT\Progress::noteFunction('eager_uri_composer_begin');
        JIT\JitVmHelperLink::ensureCompiled(
            $this->context,
            '/ext/standard/UriRawurlencodeReplaceJitHelper.php',
            ['PHPCompiler\\ext\\standard\\UriRawurlencodeReplaceJitHelper::replaceArgv'],
            '#36382'
        );
        JIT\Builtin\ParseUrlRuntime::ensureLinked($this->context);
        JIT\Progress::noteFunction('eager_uri_composer_done');
    }

    /** Emit native entry TU only — not compile_driver bundles that include compile_smoke_m3_emit (#1937). */
    private function shouldUseM3EmitTuNativeBridge(): bool
    {
        // Inventory emit links compile_driver.php {main} via the same bridge as helloworld_m3_emit (#2843).
        if ($this->shouldUseM3InventoryEmitDriver() && $this->shouldUseEmitHelperLinkStubs()) {
            return true;
        }
        $flag = Config::getenv('PHP_COMPILER_M3_EMIT_TU');

        return '1' === $flag || 'true' === strtolower((string) $flag);
    }

    /** Bundled bootstrap-aot smoke FUNCDEF names (BootstrapAot / legacy Lint bundle) (#1515). */
    private function isBootstrapHelloWorldSmokeName(string $lower): bool
    {
        return str_ends_with($lower, '\\bootstrapaot\\helloworld_compile_smoke')
            || 'helloworld_compile_smoke' === $lower
            || str_ends_with($lower, '\\helloworld_compile_smoke');
    }

    /** M3 native emit bridge entrypoints (Runtime parseAndCompile + standalone — #1983, #2294). */
    private function isBootstrapM3RuntimeEmitBridgeName(string $lower): bool
    {
        return str_ends_with($lower, '\\bootstrapaot\\compile_smoke_m3_emit')
            || 'compile_smoke_m3_emit' === $lower
            || str_ends_with($lower, '\\compile_smoke_m3_emit')
            || str_ends_with($lower, '\\bootstrapaot\\runtime_compile_smoke_m3_emit')
            || 'runtime_compile_smoke_m3_emit' === $lower
            || str_ends_with($lower, '\\runtime_compile_smoke_m3_emit');
    }

    private function isBootstrapRuntimeCtorSmokeName(string $lower): bool
    {
        return str_ends_with($lower, '\\bootstrapaot\\runtime_ctor_smoke')
            || 'runtime_ctor_smoke' === $lower
            || str_ends_with($lower, '\\runtime_ctor_smoke');
    }

    /** M3 HelloWorld compile driver: real LLVM lowering for parseAndCompile + standalone emit (#1056, #1402). */
    private function isM3CompileDriverRealLoweringName(string $lower): bool
    {
        if (!$this->shouldUseM3CompileDriverRealLowering()) {
            return false;
        }
        if ($this->shouldStubM3InventoryEmitJitSpineMethods()) {
            if (preg_match('/\\\\runtime::(loadjit|loadjitcontext|createjit|jitcontextforloadjit|loadjitcompilemodulefuncs|jitemitinplace)$/', $lower)) {
                return false;
            }
            if (str_ends_with($lower, '\\runtime::compile')) {
                return false;
            }
        }

        if ($this->isM3CompileDriverSpineDenyName($lower)) {
            return false;
        }
        if (str_ends_with($lower, '\\runtime::__construct')) {
            return true;
        }
        if (str_ends_with($lower, '\\runtime::parseandcompile')) {
            return true;
        }
        if (str_ends_with($lower, '\\runtime::loadjitcontext')) {
            return true;
        }
        if (str_ends_with($lower, '\\runtime::createjit')) {
            return true;
        }
        if (str_ends_with($lower, '\\runtime::jitcontextforloadjit')) {
            return true;
        }
        if (str_ends_with($lower, '\\runtime::loadjitcompilemodulefuncs')) {
            return true;
        }
        if (str_ends_with($lower, '\\runtime::standalone')) {
            return true;
        }
        if (str_ends_with($lower, '\\runtime::preparesourceforparser')
            || str_ends_with($lower, '\\runtime::preprocesssourceforparse')
            || str_ends_with($lower, '\\runtime::rewritesourcebeforeparser')) {
            if ($this->shouldStubInventoryArgvPreprocessSpineMethods()) {
                return false;
            }

            return !$this->shouldStubInventoryEmitParseCompileSpine();
        }
        if (str_ends_with($lower, '\\runtime::parse')) {
            return !$this->shouldStubInventoryEmitParseCompileSpine();
        }
        if (str_ends_with($lower, '\\runtime::detectfilestricttypes')
            || str_ends_with($lower, '\\runtime::resetparsernameresolverstate')
            || str_ends_with($lower, '\\runtime::recordlastparsefailure')
            || str_ends_with($lower, '\\runtime::formatparseandcompilenulldetail')) {
            // Inventory argv: ensureM3EmitTuRuntimeParseSpineDeps registers link stubs —
            // real-lowering formatParseAndCompileNullDetail hits detached memcpy/GEP (#36144).
            if ($this->shouldRealLowerInventoryArgvParseSpine()) {
                return false;
            }

            return true;
        }
        if (str_ends_with($lower, '\\runtime::compileemitsmoke')) {
            if ($this->shouldRealLowerInventoryArgvParseSpine()) {
                return false;
            }

            return true;
        }
        if (str_ends_with($lower, '\\runtime::parseandcompileemitsmoke')) {
            return true;
        }
        if (str_ends_with($lower, '\\runtime::compile')) {
            if ($this->shouldRealLowerInventoryArgvParseSpine()) {
                return true;
            }

            return true;
        }
        if (str_ends_with($lower, '\\runtime::emitparseandcompilenulldiagnostic')) {
            if ($this->shouldRealLowerInventoryArgvParseSpine()) {
                return false;
            }

            return true;
        }
        if (str_ends_with($lower, '\\runtime::loadjit')) {
            return true;
        }
        if (str_ends_with($lower, '\\runtime::initvmcontext')) {
            return true;
        }
        // M5 argv: C-floor initParsePipeline (compileRuntimeInitParsePipelineM3Native) —
        // NestedJIT of the PHP CFG hung Zend rebuilds for hours (#26756).
        if ($this->shouldUseM5DriverHostCompile()
            && (str_ends_with($lower, '\\runtime::initparsepipeline')
                || str_ends_with($lower, '\\runtime::noteparsecompilenullforscript')
                || str_ends_with($lower, '\\runtime::peeklastparsefailure'))
        ) {
            return false;
        }
        if (str_ends_with($lower, '\\runtime::initparsepipeline')) {
            return true;
        }
        if (str_ends_with($lower, '\\runtime::initcompiler')) {
            return true;
        }
        if (str_ends_with($lower, '\\runtime::loadcoremodules')) {
            return true;
        }
        if (str_ends_with($lower, '\\runtime::noteparsecompilenullforscript')) {
            return true;
        }
        if (str_ends_with($lower, '\\runtime::peeklastparsefailure')) {
            return true;
        }
        if (str_ends_with($lower, '\\runtime::__destruct')) {
            return true;
        }
        if (str_ends_with($lower, 'slotindexforvariablename')) {
            return true;
        }
        if (str_ends_with($lower, 'slotforoperand')) {
            return true;
        }
        if ($this->shouldUseM5DriverHostCompile()) {
            if ('run' === $lower || str_ends_with($lower, '\\php_compiler_cli_dispatch')
                || str_ends_with($lower, '\\php_compiler_cli_should_run_entry_driver')
            ) {
                return true;
            }
        }

        if (str_ends_with($lower, '\\compiler::compilefunc')) {
            return true;
        }

        return false;
    }

    /**
     * Former LLVM 9 crash denylist for M3 compile-driver link (#1402 / #1514).
     *
     * Empty as of #35009: BootstrapAot fixtures are not on the compile spine allowlist, so a deny
     * fragment never changed lowering. Keep the hook for a proven crasher — do not re-add fixtures
     * that are merely stubbed via other SELFHOST_AOT paths.
     *
     * @return list<string> lowercase name fragments
     */
    private function m3CompileDriverSpineDenyNames(): array
    {
        return [];
    }

    private function isM3CompileDriverSpineDenyName(string $lower): bool
    {
        foreach ($this->m3CompileDriverSpineDenyNames() as $fragment) {
            if (str_contains($lower, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /** Block helpers real-lowered on M3 compile-driver spine (#2848, JIT VarFetch path). */
    private function isM3CompileDriverBlockPhpLoweringName(string $lower): bool
    {
        if (!$this->shouldUseM3CompileDriverRealLowering()) {
            return false;
        }

        return str_ends_with($lower, '\\block::slotindexforvariablename')
            || str_ends_with($lower, '\\block::slotforoperand');
    }
}
