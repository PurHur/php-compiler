<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * M3 emit-TU Runtime parse/init spine ensure helpers (#36387).
 *
 * Extracted from {@see \PHPCompiler\JIT}: {@code ensureM3EmitTuRuntimeParseSpineDeps}
 * through {@code isM3EmitTuTrivialEchoSidecarActive} so the hub shrinks toward
 * split-TU iterability under the size-budget ratchet.
 *
 * php-src: Zend/zend_compile.c + Zend/zend_execute_API.c parse/compile entry —
 * inventory argv / emit-bridge spine stubs before Runtime::parse; move-only Concern
 * extract; no new C ABI and no opcode/IR shape change.
 */
trait M3EmitTuRuntimeParseAndInitSpine
{
    /** Private Runtime helpers required before lowering parse() on inventory argv links (#2967). */
    private function ensureM3EmitTuRuntimeParseSpineDeps(): void
    {
        if (!$this->shouldEnsureInventoryArgvParseHelperStubs()) {
            return;
        }
        $this->ensureM3EmitTuRuntimeInventoryArgvParsePreprocessStubs();
        $stubBlock = $this->m3CompileDriverMainBlock ?? $this->m3EmitTuMainBlock;
        if (null === $stubBlock) {
            return;
        }
        foreach ([
            'detectfilestricttypes',
            'resetparsernameresolverstate',
            'formatparseandcompilenulldetail',
            'emitparseandcompilenulldiagnostic',
            'recordlastparsefailure',
            'formatphpparsererrorcontext',
            'emitparsecompilefailurestderr',
            'setdebug',
            'setaotdebugsymbols',
        ] as $methodLc) {
            $logical = 'PHPCompiler\\Runtime::'.$methodLc;
            $lc = strtolower($logical);
            if (isset($this->context->functions[$lc])) {
                continue;
            }
            $this->compileSkippedCompilerSplitCfgStub(
                $this->llvmInternalName($logical),
                $stubBlock,
                $logical
            );
        }
    }

    /**
     * Inventory argv parse spine: stub preprocess/rewrite (heavy rewriter deps) and real-lower prepare wrapper (#11809).
     */
    private function ensureM3EmitTuRuntimeInventoryArgvParsePreprocessStubs(): void
    {
        if (!$this->shouldStubInventoryArgvPreprocessSpineMethods()) {
            return;
        }
        // Prefer identity stubs with real signatures over void stubs from {main} (#26756).
        $this->ensureM5ArgvPrepareSpineIdentityStubs();
        $stubBlock = $this->m3CompileDriverMainBlock ?? $this->m3EmitTuMainBlock;
        if (null === $stubBlock) {
            return;
        }
        foreach (['preprocesssourceforparse', 'rewritesourcebeforeparser'] as $methodLc) {
            $logical = 'PHPCompiler\\Runtime::'.$methodLc;
            $lc = strtolower($logical);
            if (isset($this->context->functions[$lc])) {
                continue;
            }
            $this->compileSkippedCompilerSplitCfgStub(
                $this->llvmInternalName($logical),
                $stubBlock,
                $logical
            );
        }
        $prepareLogical = 'PHPCompiler\\Runtime::preparesourceforparser';
        $prepareLc = strtolower($prepareLogical);
        if (!isset($this->context->functions[$prepareLc])) {
            $this->compileM3EmitTuRuntimeMethodFromRuntimePhpFile('preparesourceforparser', $prepareLogical, $prepareLc);
        }
    }

    /** Inventory argv: AssignOp::optimize is link-only on compileEmitSmoke spine (#11809). */
    private function ensureM3EmitTuInventoryArgvVmOptimizerStub(): void
    {
        if (!$this->shouldUseM3CompileDriverRealLowering()) {
            return;
        }
        // M5 argv / gen-0 seed real-lowers compileEmitSmoke even when inventory-emit
        // classification flickers; still need void optimize() stubs (#26756).
        if (!$this->shouldUseM3InventoryEmitDriver() && !$this->shouldUseM5DriverHostCompile()) {
            return;
        }
        $objectPtr = $this->context->getTypeFromString('__object__*');
        $voidTy = $this->context->getTypeFromString('void');
        foreach ([
            'PHPCompiler\\VM\\Optimizer::optimize',
            'PHPCompiler\\VM\\Optimizer\\AssignOp::optimize',
        ] as $logical) {
            $lc = strtolower($logical);
            if (isset($this->context->functions[$lc])) {
                continue;
            }
            $func = $this->context->module->addFunction(
                $this->llvmInternalName($logical),
                $this->context->context->functionType($voidTy, false, $objectPtr, $objectPtr)
            );
            $bb = $func->appendBasicBlock('entry');
            $saved = $this->context->builder;
            $this->context->builder = $this->context->context->builderCreate();
            $this->context->builder->positionAtEnd($bb);
            $this->context->builder->returnVoid();
            $this->context->builder->clearInsertionPosition();
            $this->context->builder = $saved;
            $this->context->functions[$lc] = $func;
            $this->context->functionReturnType[$lc] = 'void';
            $this->context->functionProxies[$lc] = new JIT\Call\Native(
                $func,
                $logical,
                [$objectPtr, $objectPtr],
                []
            );
        }
    }

    /** Ensure parse + Compiler::compileEmitSmoke exist before emit-bridge LLVM (#2666). */
    private function ensureM3EmitTuEmitBridgeSpineSymbols(): void
    {
        if (!$this->shouldUseM3CompileDriverRealLowering()) {
            return;
        }
        if (!$this->shouldUseM3EmitTuNativeBridge() && !$this->shouldUseM3InventoryEmitDriver()) {
            return;
        }
        $this->ensureM3EmitTuCompilerRuntimeCompileDeps();
        $this->ensureM3EmitTuRuntimeParseSpineDeps();
        $this->ensureM3EmitTuInventoryArgvVmOptimizerStub();
        $stubBlock = $this->m3CompileDriverMainBlock ?? $this->m3EmitTuMainBlock;
        if ($this->shouldStubInventoryEmitParseCompileSpine() && null !== $stubBlock) {
            $parseLc = strtolower('PHPCompiler\\Runtime::parse');
            if (!isset($this->context->functions[$parseLc])) {
                $this->emitM3EmitTuRuntimeParseStubNative(
                    $this->llvmInternalName('PHPCompiler\\Runtime::parse'),
                    'PHPCompiler\\Runtime::parse',
                    $stubBlock
                );
            }
            $runtimeEmitLc = strtolower('PHPCompiler\\Runtime::compileEmitSmoke');
            if (!isset($this->context->functions[$runtimeEmitLc])) {
                $this->emitM3EmitTuRuntimeCompileEmitSmokeNative(
                    $this->llvmInternalName('PHPCompiler\\Runtime::compileEmitSmoke'),
                    'PHPCompiler\\Runtime::compileEmitSmoke',
                    $stubBlock
                );
            }
            $compilerEmitLc = 'phpcompiler\\compiler::compileemitsmoke';
            if (!isset($this->context->functions[$compilerEmitLc])) {
                $this->emitM3EmitTuCompilerCompileEmitSmokeNativeFunction(
                    $this->llvmInternalName('PHPCompiler\\Compiler::compileEmitSmoke'),
                    'PHPCompiler\\Compiler::compileEmitSmoke'
                );
            }
        } else {
            $parseLc = strtolower('PHPCompiler\\Runtime::parse');
            if (!isset($this->context->functions[$parseLc])) {
                $this->compileM3EmitTuRuntimeMethodFromModules('parse');
            }
            $emitSmokeLc = strtolower('PHPCompiler\\Runtime::parseandcompileemitsmoke');
            if (!isset($this->context->functions[$emitSmokeLc])) {
                $this->compileM3EmitTuRuntimeMethodFromModules('parseandcompileemitsmoke');
            }
            foreach (['preparesourceforparser', 'compileemitsmoke', 'noteparsecompilenullforscript', 'peeklastparsefailure'] as $methodLc) {
                $runtimeLc = strtolower('PHPCompiler\\Runtime::'.$methodLc);
                if (!isset($this->context->functions[$runtimeLc])) {
                    $this->compileM3EmitTuRuntimeMethodFromModules($methodLc);
                }
            }
            $compilerEmitLc = 'phpcompiler\\compiler::compileemitsmoke';
            if (!isset($this->context->functions[$compilerEmitLc])) {
                $this->compileM3EmitTuCompilerMethodFromRuntimeModules('compileemitsmoke');
            }
        }
    }

    /**
     * Emit-helper RuntimeEmitTuInit calls these spine symbols; ensure they are defined (#2633).
     */
    private function ensureM3EmitTuRuntimeInitSpineSymbols(Block $stubBlock): void
    {
        if ($this->shouldEnsureInventoryArgvParseHelperStubs()
            && !$this->shouldRealLowerInventoryArgvParseSpine()
        ) {
            foreach (['initparsepipeline', 'initcompiler', 'initvmcontext', 'loadcoremodules'] as $methodLc) {
                $logical = 'PHPCompiler\\Runtime::'.$methodLc;
                $lc = strtolower($logical);
                if (isset($this->context->functions[$lc])) {
                    continue;
                }
                $this->emitM3EmitTuRuntimeInitVoidStub(
                    $this->llvmInternalName($logical),
                    $logical,
                    $stubBlock
                );
            }
            $noteLogical = 'PHPCompiler\\Runtime::noteparsecompilenullforscript';
            $noteLc = strtolower($noteLogical);
            if (!isset($this->context->functions[$noteLc])) {
                $this->emitM3EmitTuRuntimeTwoObjectVoidStub(
                    $this->llvmInternalName($noteLogical),
                    $noteLogical,
                    $stubBlock
                );
            }
            $peekLogical = 'PHPCompiler\\Runtime::peeklastparsefailure';
            $peekLc = strtolower($peekLogical);
            if (!isset($this->context->functions[$peekLc])) {
                $this->emitM3EmitTuCompilerNullStringGetterStub(
                    $this->llvmInternalName($peekLogical),
                    $peekLogical,
                    $stubBlock
                );
            }

            return;
        }
        foreach (['initparsepipeline', 'loadcoremodules'] as $methodLc) {
            $logical = 'PHPCompiler\\Runtime::'.$methodLc;
            $lc = strtolower($logical);
            if ($this->shouldUseM3InventoryEmitDriver()) {
                unset($this->context->functions[$lc], $this->context->functionReturnType[$lc], $this->context->functionProxies[$lc]);
            } elseif (!isset($this->context->functions[$lc])) {
                $this->compileM3EmitTuRuntimeMethodFromModules($methodLc);
            }
            if (!$this->shouldUseM3InventoryEmitDriver() && isset($this->context->functions[$lc])) {
                continue;
            }
            if ('initparsepipeline' === $methodLc) {
                $this->compileRuntimeInitParsePipelineM3Native(
                    $this->llvmInternalName($logical),
                    $stubBlock,
                    $logical
                );
                continue;
            }
            $this->compileRuntimeLoadCoreModulesM3Native(
                $this->llvmInternalName($logical),
                $stubBlock,
                $logical
            );
        }
    }

    /** Link-time trivial-echo AOT sidecar for emit-helper TU (#2559, #2566). */
    private function isM3EmitTuTrivialEchoSidecarActive(): bool
    {
        // M5 argv driver + inventory gen-2→gen-3 bin/compile.php links need bootstrap-aot sidecars
        // even when EMIT_HELPER_LINK is unset (#3004).
        if ($this->shouldUseM5DriverHostCompile() || $this->shouldUseM3InventoryEmitDriver()) {
            $this->cacheM3EmitTuTrivialEchoAtLinkTime();

            return \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::isRegistered($this->context);
        }
        // M4 argv bin/compile.php links with -u PHP_COMPILER_EMIT_HELPER_LINK but still needs
        // compile_smoke / HelloWorld sidecars at link time (#3004, #2880).
        $inventoryArgvSidecar = $this->shouldUseM3InventoryEmitDriver() && $this->shouldUseM4BinCompileArgvMainNative();
        if (!$this->shouldUseEmitHelperLinkStubs() && !$inventoryArgvSidecar) {
            return false;
        }
        if (!$this->shouldUseM3EmitTuNativeBridge() && !$this->shouldUseM3InventoryEmitDriver()) {
            return false;
        }
        $this->cacheM3EmitTuTrivialEchoAtLinkTime();

        return \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::isRegistered($this->context);
    }
}
