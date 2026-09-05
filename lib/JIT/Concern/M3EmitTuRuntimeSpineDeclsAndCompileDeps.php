<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * M3 emit-TU Runtime/Compiler spine decl + compile-deps helpers (#36387).
 *
 * Extracted from {@see \PHPCompiler\JIT}: {@code compileM3EmitTuRuntimeSpineDecls}
 * through {@code ensureM3EmitTuCompilerRuntimeCompileDeps} so the hub shrinks toward
 * split-TU iterability under the size-budget ratchet.
 *
 * php-src: Zend/zend_compile.c compile_* / zend_execute API surface for
 * Runtime::parseAndCompile + Compiler::compile link stubs — move-only Concern
 * extract; no new C ABI and no opcode/IR shape change.
 */
trait M3EmitTuRuntimeSpineDeclsAndCompileDeps
{
    /** Lower Runtime/Compiler spine before native emit bridge (#1937, #2512). */
    private function compileM3EmitTuRuntimeSpineDecls(?Block $compileDriverStubBlock = null): void
    {
        $emitTu = $this->shouldUseM3EmitTuNativeBridge() && null !== $this->m3EmitTuMainBlock;
        $inventoryArgvCompileDriver = null !== $compileDriverStubBlock
            && $this->shouldUseM3InventoryEmitForCompileDriverBlock($compileDriverStubBlock);
        $compileDriver = null !== $compileDriverStubBlock
            && ($this->shouldUseM3CompileDriverMainNative() || $inventoryArgvCompileDriver);
        if (!$emitTu && !$compileDriver) {
            return;
        }
        $stubBlock = $emitTu ? $this->m3EmitTuMainBlock : $compileDriverStubBlock;
        if ($this->shouldUseM3CompileDriverRealLowering() || $inventoryArgvCompileDriver) {
            $inventoryArgvParseHelper = $this->shouldEnsureInventoryArgvParseHelperStubs()
                && !$this->shouldRealLowerInventoryArgvParseSpine();
            if ($inventoryArgvParseHelper) {
                $this->ensureM3EmitTuCompilerRuntimeCompileDeps();
                $this->ensureM3EmitTuRuntimeParseSpineDeps();
                if (null !== $stubBlock) {
                    $this->ensureM3EmitTuRuntimeInitSpineSymbols($stubBlock);
                    $this->ensureM3EmitTuEmitBridgeSpineSymbols();
                    $this->emitM3EmitTuRuntimeConstructNativeFunction(
                        $this->llvmInternalName('PHPCompiler\\Runtime::__construct'),
                        'PHPCompiler\\Runtime::__construct',
                        $stubBlock
                    );
                }
                $this->compileM3EmitTuRuntimeParseAndCompileNativeDecl([
                    'parseandcompile' => true,
                    'parseandcompileemitsmoke' => true,
                ]);

                return;
            }
            $sidecar = $emitTu && $this->isM3EmitTuTrivialEchoSidecarActive();
            $this->compileM3EmitTuRuntimeSpineMethodsForRealLowering();
            foreach (['initparsepipeline', 'initcompiler', 'initvmcontext', 'loadcoremodules', 'parseandcompileemitsmoke', 'standalone'] as $methodLc) {
                if ('standalone' === $methodLc && ($sidecar || $this->shouldUseM3InventoryEmitForCompileDriverBlock($stubBlock))) {
                    if (null !== $stubBlock) {
                        if ($this->shouldUseM3InventoryEmitForCompileDriverBlock($stubBlock)) {
                            $standaloneLc = strtolower('PHPCompiler\\Runtime::standalone');
                            unset(
                                $this->context->functions[$standaloneLc],
                                $this->context->functionReturnType[$standaloneLc],
                                $this->context->functionProxies[$standaloneLc]
                            );
                        }
                        $this->emitM3EmitTuRuntimeStandaloneStubNative(
                            $this->llvmInternalName('PHPCompiler\\Runtime::standalone'),
                            'PHPCompiler\\Runtime::standalone',
                            $stubBlock
                        );
                    }
                    continue;
                }
                $this->compileM3EmitTuRuntimeMethodFromQueue($methodLc);
            }
            if ($emitTu) {
                $this->compileM3EmitTuCompilerSpineMethodsFromMainBlock(['compileemitsmoke']);
            } else {
                $this->compileM3EmitTuCompilerMethodFromRuntimeModules('compileemitsmoke');
            }
            if (!$this->shouldUseM4InventoryArgvNativeEmitRebuild($compileDriverStubBlock)) {
                $this->runQueue();
            }
            if (null !== $stubBlock && ($emitTu || $compileDriver)) {
                $this->ensureM3EmitTuRuntimeInitSpineSymbols($stubBlock);
                $this->ensureM3EmitTuEmitBridgeSpineSymbols();
                if ($compileDriver) {
                    $this->emitM3EmitTuRuntimeConstructNativeFunction(
                        $this->llvmInternalName('PHPCompiler\\Runtime::__construct'),
                        'PHPCompiler\\Runtime::__construct',
                        $stubBlock
                    );
                }
            }
            $this->compileM3EmitTuRuntimeParseAndCompileNativeDecl([
                'parseandcompile' => true,
                'parseandcompileemitsmoke' => true,
            ]);

            return;
        }
        if (!$emitTu) {
            return;
        }
        $this->compileM3EmitTuRuntimeSpineMethodsForRealLowering();
        if ($this->shouldUseM3EmitTuEmitHelperSpineRealLowering()) {
            foreach (['initparsepipeline', 'initcompiler', 'loadcoremodules'] as $methodLc) {
                // M5 argv uses C-floor initParsePipeline — do not pre-register void ret (#26756).
                if ('initparsepipeline' === $methodLc && $this->shouldUseM5DriverHostCompile()) {
                    continue;
                }
                $logical = 'PHPCompiler\\Runtime::'.$methodLc;
                $this->emitM3EmitTuRuntimeInitVoidStub(
                    $this->llvmInternalName($logical),
                    $logical,
                    $stubBlock
                );
            }
        } elseif (null !== $stubBlock) {
            $this->emitM3EmitTuRuntimeParseStubNative(
                $this->llvmInternalName('PHPCompiler\\Runtime::parse'),
                'PHPCompiler\\Runtime::parse',
                $stubBlock
            );
            $this->emitM3EmitTuRuntimeCompileEmitSmokeNative(
                $this->llvmInternalName('PHPCompiler\\Runtime::compileEmitSmoke'),
                'PHPCompiler\\Runtime::compileEmitSmoke',
                $stubBlock
            );
        }
        $this->emitM3EmitTuRuntimeConstructNativeFunction(
            $this->llvmInternalName('PHPCompiler\\Runtime::__construct'),
            'PHPCompiler\\Runtime::__construct',
            $stubBlock
        );
        $this->compileM3EmitTuRuntimeParseAndCompileNativeDecl([
            'parseandcompile' => true,
            'parseandcompileemitsmoke' => true,
        ]);
        $this->emitM3EmitTuRuntimeBlockPtrStubNative(
            $this->llvmInternalName('PHPCompiler\\Runtime::compile'),
            'PHPCompiler\\Runtime::compile',
            $stubBlock
        );
        $this->emitM3EmitTuRuntimeStandaloneStubNative(
            $this->llvmInternalName('PHPCompiler\\Runtime::standalone'),
            'PHPCompiler\\Runtime::standalone',
            $stubBlock
        );
        $this->compileM3EmitTuCompilerEmitSmokeNativeDecl();
        $this->runQueue();
    }

    /**
     * After spine pre-lower, register emit-bridge decls that call real Runtime::parse (#2512, #2550).
     */
    private function finalizeM3EmitTuRuntimeSpineAfterQueue(): void
    {
        if (!$this->shouldUseM3CompileDriverRealLowering()) {
            return;
        }
        if ($this->shouldUseM3EmitTuNativeBridge() && null !== $this->m3EmitTuMainBlock) {
            $this->compileM3EmitTuRuntimeParseAndCompileNativeDecl([
                'parseandcompile' => true,
                'parseandcompileemitsmoke' => true,
            ]);
            $this->compileM3EmitTuCompilerEmitSmokeNativeDecl();

            return;
        }
        if (
            null !== $this->m3CompileDriverMainBlock
            && (
                $this->shouldUseM3InventoryEmitForCompileDriverBlock($this->m3CompileDriverMainBlock)
                || $this->shouldUseM4InventoryArgvNativeEmitRebuild($this->m3CompileDriverMainBlock)
            )
        ) {
            $this->compileM3EmitTuRuntimeParseAndCompileNativeDecl([
                'parseandcompile' => true,
                'parseandcompileemitsmoke' => true,
            ]);
            $this->compileM3EmitTuCompilerEmitSmokeNativeDecl();
        }
    }

    /**
     * Lower Runtime::parseAndCompile* from lib/Runtime.php for emit/inventory drivers (#2516, #2967).
     *
     * Do not register the native emit-bridge wrapper: it calls back into the same symbol and segfaults.
     *
     * @param array<string, true> $methods lowercase method names
     */
    private function compileM3EmitTuRuntimeParseAndCompileNativeDecl(array $methods): void
    {
        if ([] === $methods) {
            return;
        }
        if (
            !$this->shouldUseM3EmitTuNativeBridge()
            && !$this->shouldUseM3InventoryEmitDriver()
            && !$this->shouldUseM4BinCompileArgvMainNative()
            && !$this->shouldEnsureInventoryArgvParseHelperStubs()
        ) {
            return;
        }
        $this->ensureM3EmitTuEmitBridgeSpineSymbols();
        $savedClassId = $this->context->scope->classId;
        $savedClassName = $this->context->scope->className;
        $this->context->scope->classId = $this->context->type->object->lookup('PHPCompiler\\Runtime');
        $this->context->scope->className = 'phpcompiler\\runtime';
        $forceRealParseSpine = $this->shouldRealLowerInventoryArgvParseSpine();
        $inventoryArgvParseHelper = $this->shouldEnsureInventoryArgvParseHelperStubs()
            && !$forceRealParseSpine;
        $stubBlock = $this->m3EmitTuMainBlock ?? $this->m3CompileDriverMainBlock;
        if ($forceRealParseSpine) {
            // Inventory argv Zend rebuild keeps preprocess CFG stubs; only parse/emit spine is real (#11809).
            $forceRealUnset = $this->shouldUseM3InventoryEmitDriver()
                ? ['parse', 'compileemitsmoke']
                : ['preprocesssourceforparse', 'rewritesourcebeforeparser', 'preparesourceforparser', 'parse', 'compileemitsmoke'];
            foreach ($forceRealUnset as $spineLc) {
                $spineLcKey = strtolower('PHPCompiler\\Runtime::'.$spineLc);
                unset(
                    $this->context->functions[$spineLcKey],
                    $this->context->functionReturnType[$spineLcKey],
                    $this->context->functionProxies[$spineLcKey]
                );
            }
        }
        if (!$this->shouldUseM4InventoryArgvNativeEmitRebuild() && !$inventoryArgvParseHelper) {
            $spineCompileList = $this->shouldUseM3InventoryEmitDriver()
                ? ['parse', 'compileemitsmoke']
                : [
                    'preprocesssourceforparse',
                    'rewritesourcebeforeparser',
                    'preparesourceforparser',
                    'parse',
                    'compileemitsmoke',
                ];
            foreach ($spineCompileList as $spineLc) {
                $spineLcKey = strtolower('PHPCompiler\\Runtime::'.$spineLc);
                if (isset($this->context->functions[$spineLcKey])) {
                    continue;
                }
                $this->compileM3EmitTuRuntimeMethodFromQueue($spineLc);
                if (!isset($this->context->functions[$spineLcKey])) {
                    $this->compileM3EmitTuRuntimeMethodFromModules($spineLc);
                }
            }
        }
        if ($inventoryArgvParseHelper) {
            $this->ensureM3EmitTuRuntimeParseAndCompileDeclBeforeQueue($methods, $stubBlock);
        }
        if (!$this->shouldUseM4InventoryArgvNativeEmitRebuild() && !$inventoryArgvParseHelper) {
            $this->runQueue();
        }
        if (!$inventoryArgvParseHelper) {
            $this->ensureM3EmitTuRuntimeParseAndCompileDeclBeforeQueue($methods, $stubBlock);
        }
        $this->context->scope->classId = $savedClassId;
        $this->context->scope->className = $savedClassName;
    }

    /**
     * Register parse/compileEmitSmoke stubs and parseAndCompile* decls for inventory argv (#12036).
     *
     * @param array<string, true> $methods
     */
    private function ensureM3EmitTuRuntimeParseAndCompileDeclBeforeQueue(array $methods, ?Block $stubBlock): void
    {
        foreach (['parse', 'compileemitsmoke'] as $spineLc) {
            $spineLogical = 'PHPCompiler\\Runtime::'.$spineLc;
            $spineLcKey = strtolower($spineLogical);
            if (isset($this->context->functions[$spineLcKey]) || null === $stubBlock) {
                continue;
            }
            // Do not install null stubs that poison later Runtime.php lowering (#26756).
            if ('parse' === $spineLc && $this->shouldRealLowerInventoryArgvParseSpine()) {
                continue;
            }
            if ('parse' === $spineLc) {
                $this->emitM3EmitTuRuntimeParseStubNative(
                    $this->llvmInternalName($spineLogical),
                    $spineLogical,
                    $stubBlock
                );
            } else {
                $this->emitM3EmitTuRuntimeCompileEmitSmokeNative(
                    $this->llvmInternalName($spineLogical),
                    $spineLogical,
                    $stubBlock
                );
            }
        }
        foreach (array_keys($methods) as $methodLc) {
            $logical = 'PHPCompiler\\Runtime::'.$methodLc;
            $lc = strtolower($logical);
            unset(
                $this->context->functions[$lc],
                $this->context->functionReturnType[$lc],
                $this->context->functionProxies[$lc]
            );
            \PHPCompiler\JIT\BootstrapCompileSmokeM3Emit::declareRuntimeParseAndCompileViaParseEmitSmoke(
                $this->context,
                $this->llvmInternalName($logical),
                $logical,
                $methodLc
            );
        }
    }

    /**
     * Pre-lower emit spine before native emit bridge (#2550, #2559).
     *
     * Compile-driver path: host-lowers Runtime::__construct/parse/compileEmitSmoke from modules.
     * Emit-helper path: link-time trivial-echo AOT sidecar for parseAndCompile* / standalone.
     */
    private function compileM3EmitTuRuntimeSpineMethodsForRealLowering(): void
    {
        if ($this->shouldEnsureInventoryArgvParseHelperStubs()
            && !$this->shouldRealLowerInventoryArgvParseSpine()
        ) {
            return;
        }
        $sidecar = $this->isM3EmitTuTrivialEchoSidecarActive();
        if (!$this->shouldUseM3CompileDriverRealLowering()) {
            return;
        }
        $this->ensureM3EmitTuCompilerRuntimeCompileDeps();
        $this->ensureM3EmitTuRuntimeParseSpineDeps();
        // Void Optimizer/AssignOp::optimize before host-lowering compileEmitSmoke (#11809, #26756).
        $this->ensureM3EmitTuInventoryArgvVmOptimizerStub();
        $emitHelperStubMethods = [];
        if ($this->shouldStubInventoryEmitParseCompileSpine()) {
            $emitHelperStubMethods = ['parse', 'preparesourceforparser', 'preprocesssourceforparse', 'rewritesourcebeforeparser', 'compileemitsmoke'];
        } elseif ($this->shouldRealLowerInventoryArgvParseSpine()) {
            // Inventory argv: real-lower parse; preprocess helpers stay CFG stubs (#11809).
            // Keep compileEmitSmoke stubbed — full CFG hits object::optimize() under NestedJIT (#26756).
            // M5 argv: also skip PHP CFG for init*/diagnostics (native RuntimeEmitTuInit / void stubs);
            // host-lowering initParsePipeline hung the Zend rebuild for hours (#26756).
            $emitHelperStubMethods = [
                'preparesourceforparser',
                'preprocesssourceforparse',
                'rewritesourcebeforeparser',
                'compileemitsmoke',
                // Native __string__* stubs — real PHP lowering returns boxed __value__* and
                // BootstrapCompileSmokeM3Emit::echoLastParseFailureSuffix structGeps __string__
                // fields (#36144).
                'noteparsecompilenullforscript',
                'peeklastparsefailure',
            ];
            if ($this->shouldUseM5DriverHostCompile()) {
                // C-floor initParsePipeline via compileRuntimeInitParsePipelineM3Native (#26756).
                // C-floor Runtime::parse via RuntimeParseM5Native — skip NestedJIT mid-BB + prepare SEGV.
                // prepare/preprocess/rewrite stay as identity stubs (RuntimePrepareSpineIdentity).
                $emitHelperStubMethods = array_merge($emitHelperStubMethods, [
                    'parse',
                    'initparsepipeline',
                ]);
            }
        }
        $inventoryEmitHelper = $this->shouldStubM3InventoryEmitJitSpineMethods();
        foreach ([
            '__construct',
            'parse',
            'preparesourceforparser',
            'compile',
            'compileemitsmoke',
            'parseandcompileemitsmoke',
            'initparsepipeline',
            'initcompiler',
            'loadcoremodules',
            'noteparsecompilenullforscript',
            'peeklastparsefailure',
        ] as $methodLc) {
            if (in_array($methodLc, $emitHelperStubMethods, true)
                || ('compile' === $methodLc && $inventoryEmitHelper)
            ) {
                continue;
            }
            $this->compileM3EmitTuRuntimeMethodFromModules($methodLc);
        }
        // M5 / inventory argv: NestedJIT peer methods then PHPCfg\Parser::parse so C-floor
        // Runtime::parse can call astParser->parse (#26756 / #27426, #36144).
        if ($this->shouldUseM5ParseSpineCFloor()) {
            $this->ensureM5ParseSpineCFloorSymbols();
        }
        // M5 argv seed host-lowers Runtime::parse first; emitting the sidecar standalone
        // stub here runQueues mid-parse and fatals on a null LLVM insert block (#26756).
        if ($sidecar && null !== $this->m3EmitTuMainBlock && !$this->shouldUseM5DriverHostCompile()) {
            $this->emitM3EmitTuRuntimeStandaloneStubNative(
                $this->llvmInternalName('PHPCompiler\\Runtime::standalone'),
                'PHPCompiler\\Runtime::standalone',
                $this->m3EmitTuMainBlock
            );
        }
        if (!$this->shouldUseM4InventoryArgvNativeEmitRebuild()) {
            $this->runQueue();
        }
        if ($sidecar && null !== $this->m3EmitTuMainBlock && $this->shouldUseM5DriverHostCompile()) {
            $this->emitM3EmitTuRuntimeStandaloneStubNative(
                $this->llvmInternalName('PHPCompiler\\Runtime::standalone'),
                'PHPCompiler\\Runtime::standalone',
                $this->m3EmitTuMainBlock
            );
        }
    }

    /**
     * Runtime::compile calls these before Compiler::compile — native void stubs avoid parsing
     * lib/Compiler.php during inventory emit link (#1492).
     */
    private function ensureM3EmitTuCompilerRuntimeCompileDeps(): void
    {
        if (!$this->shouldUseM3CompileDriverRealLowering()) {
            return;
        }
        if (!$this->shouldUseM3EmitTuNativeBridge() && !$this->shouldUseM3InventoryEmitDriver()) {
            return;
        }
        $stubBlock = $this->m3EmitTuMainBlock ?? $this->m3CompileDriverMainBlock;
        foreach (['setpropertyhookregistry', 'setknownclassreadonly', 'setbarerethrowlines'] as $methodLc) {
            $logical = 'PHPCompiler\\Compiler::'.$methodLc;
            $lc = strtolower($logical);
            if (!isset($this->context->functions[$lc])) {
                $this->emitM3EmitTuCompilerArrayPropertySetterVoidStub(
                    $this->llvmInternalName($logical),
                    $logical,
                    $stubBlock
                );
            }
        }
        foreach (['setcompileabortdetailifempty', 'setdebuglastphaseinputfile'] as $methodLc) {
            $logical = 'PHPCompiler\\Compiler::'.$methodLc;
            $lc = strtolower($logical);
            if (!isset($this->context->functions[$lc])) {
                $this->emitM3EmitTuCompilerStringSetterVoidStub(
                    $this->llvmInternalName($logical),
                    $logical,
                    $stubBlock
                );
            }
        }
        $resetLogical = 'PHPCompiler\\Compiler::resetcompileabortdetail';
        $resetLc = strtolower($resetLogical);
        if (!isset($this->context->functions[$resetLc])) {
            $this->emitM3EmitTuCompilerVoidStub(
                $this->llvmInternalName($resetLogical),
                $resetLogical,
                $stubBlock
            );
        }
        foreach (['getdebuglastphaseinputfile', 'getcompileabortdetail'] as $methodLc) {
            $logical = 'PHPCompiler\\Compiler::'.$methodLc;
            $lc = strtolower($logical);
            if (!isset($this->context->functions[$lc])) {
                $this->emitM3EmitTuCompilerNullStringGetterStub(
                    $this->llvmInternalName($logical),
                    $logical,
                    $stubBlock
                );
            }
        }
        if ($this->shouldStubM3InventoryEmitJitSpineMethods()) {
            $compileLogical = 'PHPCompiler\\Compiler::compile';
            $compileLc = strtolower($compileLogical);
            if (!isset($this->context->functions[$compileLc])) {
                $this->emitM3EmitTuCompilerCompileNullStubNative(
                    $this->llvmInternalName($compileLogical),
                    $compileLogical
                );
            }
            foreach (['loadjit', 'loadjitcontext', 'createjit', 'jitcontextforloadjit', 'loadjitcompilemodulefuncs', 'jitemitinplace'] as $methodLc) {
                $logical = 'PHPCompiler\\Runtime::'.$methodLc;
                $lc = strtolower($logical);
                if (!isset($this->context->functions[$lc]) && null !== $stubBlock) {
                    $this->emitM3EmitTuRuntimeInitVoidStub(
                        $this->llvmInternalName($logical),
                        $logical,
                        $stubBlock
                    );
                }
            }
        }
    }

}
