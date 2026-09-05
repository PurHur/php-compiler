<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPLLVM;

/**
 * Block dispatch, reflection metadata recording, and skip-name scoping (#36387).
 *
 * Extracted from {@see \PHPCompiler\JIT}: {@code requiredParameterCountFromBlock}
 * through {@code compileBlock} so the hub shrinks toward split-TU iterability
 * under the size-budget ratchet.
 *
 * php-src: Zend/zend_compile.c (compile_function / compile_method body dispatch),
 * Zend/zend_API.c ReflectionParameter required-count, Zend/zend_execute_API.c
 * scoped function names — move-only Concern extract; no new C ABI and no
 * opcode/IR shape change.
 */
trait CompileBlockDispatchAndReflectionMeta
{
    /**
     * Required parameter count for ReflectionMethod AOT metadata (#34216).
     */
    private static function requiredParameterCountFromBlock(Block $block): int
    {
        $required = 0;
        $paramNames = array_values($block->paramNames);
        for ($i = 0, $n = \count($paramNames); $i < $n; ++$i) {
            if (null !== $block->variadicParamIndex && (int) $block->variadicParamIndex === $i) {
                break;
            }
            if (VM\ParamArgumentCountError::parameterHasDefault($block, $i)) {
                break;
            }
            ++$required;
        }

        return $required;
    }

    /**
     * FUNCDEF/DECLARE_METHOD use short names; self-host skip/M3 gates need scoped names (#1402).
     */
    private function jitFunctionSkipName(?string $name, Block $block): string
    {
        $candidate = strtolower((string) $name);
        if (str_contains($candidate, '::')) {
            return $candidate;
        }
        if (null !== $block->func) {
            return strtolower($block->func->getScopedName());
        }

        return $candidate;
    }

    private function compileBlock(Block $block, ?string $funcName = null): PHPLLVM\Value {
        $logicalName = $funcName;
        if (null !== $logicalName && null !== $block->func) {
            JIT\Progress::noteFunction($block->func->getScopedName());
            $this->context->jitLoweringScopedName = $block->func->getScopedName();
        }
        if ([] !== $block->paramSensitive) {
            if (null !== $block->func && null !== $block->func->class) {
                $classLc = strtolower((string) $block->func->class->value);
                $methodLc = strtolower($block->func->name);
                foreach (array_keys($block->paramSensitive) as $position) {
                    JIT\Builtin\ParamSensitiveLowering::recordMethod($classLc, $methodLc, (int) $position);
                }
            } elseif (null !== $funcName && '' !== $funcName) {
                foreach (array_keys($block->paramSensitive) as $index) {
                    JIT\Builtin\ParamSensitiveLowering::recordFunction(strtolower($funcName), (int) $index);
                }
            }
        }
        if (\PHPCompiler\CompilerVersion::supportsReflectionFunctionGetNamedArguments()) {
            $paramNames = array_values($block->paramNames);
            if ([] !== $paramNames) {
                if (null !== $block->func && null !== $block->func->class) {
                    JIT\Builtin\ReflectionNamedArgumentsLowering::recordMethod(
                        strtolower((string) $block->func->class->value),
                        strtolower($block->func->name),
                        $paramNames
                    );
                } elseif (null !== $funcName && '' !== $funcName) {
                    JIT\Builtin\ReflectionNamedArgumentsLowering::recordFunction(strtolower($funcName), $paramNames);
                }
            }
        }
        if (
            null !== $block->variadicParamIndex
            && null !== $funcName
            && '' !== $funcName
            && (null === $block->func || null === $block->func->class)
        ) {
            JIT\Builtin\ReflectionFunctionVariadicLowering::recordFunction(strtolower($funcName));
        }
        // Thin AOT ReflectionFunction::{getNumberOfParameters,isUserDefined,isInternal} (#34218).
        if (
            null !== $funcName
            && '' !== $funcName
            && (null === $block->func || null === $block->func->class)
        ) {
            JIT\Builtin\ReflectionFunctionParamCountLowering::recordUserFunction(
                strtolower($funcName),
                \count(array_values($block->paramNames))
            );
        }
        // Thin AOT ReflectionMethod::{getNumberOfParameters,getNumberOfRequiredParameters} (#34216).
        if (null !== $block->func && null !== $block->func->class) {
            JIT\Builtin\ReflectionMethodQueryLowering::recordUserMethodFromBlock(
                (string) $block->func->class->value,
                $block->func->name,
                $block
            );
        }
        $skipName = $this->jitFunctionSkipName($logicalName, $block);
        if (!is_null($funcName)) {
            $internalName = $this->llvmInternalName($funcName);
        } else {
            $internalName = 'internal_'.(++self::$functionNumber);
            $debugMainName = JIT\AotDebugSymbols::scriptMainFunctionName($block);
            if (null !== $debugMainName) {
                $internalName = $debugMainName;
            }
        }
        if (str_contains($internalName, 'opcode_type_name')) {
            return $this->compileSkippedOpcodeNameStub($internalName, $block);
        }
        // M5 argv / gen-0 seed: ResolveSidecarJitHelper NestedJIT explodes (no phpc_str_replace
        // under NestedJitCompileScope; helper unit failed.json) — identity path stubs (#26756).
        if (
            $this->shouldUseM5DriverHostCompile()
            && null !== $logicalName
            && $this->isM5ArgvResolveSidecarIdentityStubName(strtolower($logicalName))
        ) {
            return $this->emitM5ArgvResolveSidecarIdentityStub($internalName, $logicalName, $block);
        }
        // M5 bootstrap sidecar: CLI entry scripts under `PHP_COMPILER_SELFHOST_AOT=1` only need a
        // linkable bundle; stub {main} to avoid LLVM 9 crashing while lowering argv driver chains
        // (#2697, #2699). `PHP_COMPILER_M5_DRIVER_HOST=1` opts into real argv lowering (#1521).
        if (
            $this->shouldUseSelfHostJitStubs()
            && !$this->shouldUseM5DriverHostCompile()
            && null === $logicalName
            && null !== $block->func
            && '{main}' === $block->func->name
            && $this->isM5BootstrapSidecarScriptMain($block)
        ) {
            return $this->compileSkippedCompilerSplitCfgStub($internalName, $block, '{main}');
        }
        if (
            $this->shouldUseM3CompileDriverMainNative()
            && $this->isM3CompileDriverBundleScriptMain($block)
            && !($this->shouldUseM3InventoryEmitDriver() && $this->shouldUseEmitHelperLinkStubs())
        ) {
            return $this->compileM3CompileDriverMainNative($internalName, $block, $logicalName);
        }
        if ($this->shouldUseM3EmitTuNativeBridge() && $this->isM3EmitTuScriptMain($block)) {
            return $this->compileM3EmitTuMainNative($internalName, $block, $logicalName);
        }
        if (
            $this->isM4BinCompileScriptMain($block)
            && (
                $this->shouldUseM4BinCompileArgvMainNative()
                || $this->shouldUseHelloworldBinCompileInventoryArgvLink()
            )
        ) {
            return $this->compileM3CompileDriverMainNative($internalName, $block, $logicalName);
        }
        if ($this->shouldUseM3EmitTuNativeBridge() && null !== $logicalName) {
            $m3EmitRuntime = strtolower($logicalName);
            if ($this->isM3EmitTuRuntimeSpineLoweringName($m3EmitRuntime)) {
                $methodLc = substr($m3EmitRuntime, (int) strrpos($m3EmitRuntime, '::') + 2);
                if ($this->shouldUseM3EmitTuRuntimeMethodStub($methodLc)) {
                    return $this->compileM3EmitTuRuntimeSpineStub(
                        $internalName,
                        $block,
                        $logicalName,
                        $m3EmitRuntime
                    );
                }
            }
            if ($this->isM3EmitTuCompilerSpineLoweringName($m3EmitRuntime)) {
                if ('phpcompiler\\compiler::compileemitsmoke' === $m3EmitRuntime) {
                    if (!$this->shouldUseM3CompileDriverRealLowering()) {
                        return $this->emitM3EmitTuCompilerCompileEmitSmokeNativeFunction(
                            $internalName,
                            $logicalName
                        );
                    }
                } elseif (!$this->shouldUseM3CompileDriverRealLowering()) {
                    return $this->compileSkippedCompilerSplitCfgStub(
                        $internalName,
                        $block,
                        $logicalName ?? $internalName
                    );
                }
            }
        }
        if (
            null !== $logicalName
            && $this->shouldUseM3EmitTuNativeBridge()
            && $this->isBootstrapM3RuntimeEmitBridgeName(strtolower($logicalName))
        ) {
            return $this->compileBootstrapCompileSmokeM3EmitNative($internalName, $block, $logicalName);
        }
        $emitTuSpine = $this->tryCompileM3EmitTuRuntimeSpineNative($internalName, $block, $logicalName);
        if (null !== $emitTuSpine) {
            return $emitTuSpine;
        }
        $emitTuCompiler = $this->tryCompileM3EmitTuCompilerSpineNative($internalName, $block, $logicalName);
        if (null !== $emitTuCompiler) {
            return $emitTuCompiler;
        }
        if ($this->shouldUseM3CompileDriverRealLowering() && null !== $logicalName) {
            $m3Spine = strtolower($logicalName);
            if ($this->isM3CompileDriverCompilerNativeLoweringName($m3Spine)) {
                return JIT\CompilerOperandChainNative::compile(
                    $this->context,
                    $this->llvmInternalName($internalName),
                    $block,
                    $logicalName
                );
            }
            if (JIT\VariableTypeMapNative::isNativeLoweringName($m3Spine)) {
                return JIT\VariableTypeMapNative::compile(
                    $this->context,
                    $this->llvmInternalName($internalName),
                    $block,
                    $logicalName
                );
            }
            if (JIT\OperandNameNative::isNativeLoweringName($m3Spine)) {
                return JIT\OperandNameNative::compile(
                    $this->context,
                    $this->llvmInternalName($internalName),
                    $block,
                    $logicalName
                );
            }
            if (str_ends_with($m3Spine, '\\runtime::loadjit')) {
                return $this->compileRuntimeLoadJitM3Native($internalName, $block, $logicalName);
            }
            if (str_ends_with($m3Spine, '\\runtime::loadjitcontext')) {
                return $this->compileRuntimeLoadJitContextM3Native($internalName, $block, $logicalName);
            }
            if (str_ends_with($m3Spine, '\\runtime::createjit')) {
                return $this->compileRuntimeCreateJitM3Native($internalName, $block, $logicalName);
            }
            if (str_ends_with($m3Spine, '\\runtime::jitcontextforloadjit')) {
                return $this->compileRuntimeJitContextForLoadJitM3Native($internalName, $block, $logicalName);
            }
            if (str_ends_with($m3Spine, '\\runtime::loadjitcompilemodulefuncs')) {
                return $this->compileRuntimeLoadJitCompileModuleFuncsM3Native($internalName, $block, $logicalName);
            }
            if (str_ends_with($m3Spine, '\\runtime::__construct')) {
                return $this->compileRuntimeConstructM3Native($internalName, $block, $logicalName);
            }
            if (str_ends_with($m3Spine, '\\runtime::initparsepipeline')) {
                return $this->compileRuntimeInitParsePipelineM3Native($internalName, $block, $logicalName);
            }
            if (str_ends_with($m3Spine, '\\runtime::initcompiler')) {
                return $this->compileRuntimeInitCompilerM3Native($internalName, $block, $logicalName);
            }
            if (str_ends_with($m3Spine, '\\runtime::initvmcontext')) {
                return $this->compileRuntimeInitVmContextM3Native($internalName, $block, $logicalName);
            }
            if (str_ends_with($m3Spine, '\\runtime::loadcoremodules')) {
                return $this->compileRuntimeLoadCoreModulesM3Native($internalName, $block, $logicalName);
            }
            if (str_ends_with($m3Spine, '\\runtime::__destruct')) {
                return $this->compileRuntimeDestructM3Native($internalName, $block, $logicalName);
            }
            if (
                $this->shouldUseM3EmitTuNativeBridge()
                && (
                    str_ends_with($m3Spine, '\\runtime::parseandcompile')
                    || str_ends_with($m3Spine, '\\runtime::parseandcompileemitsmoke')
                )
            ) {
                return $this->compileRuntimeParseAndCompileM3Native($internalName, $block, $logicalName);
            }
            if (str_ends_with($m3Spine, '\\runtime::parse')) {
                // M5 argv / inventory argv: C-floor parse (skip prepare list-unpack SEGV) (#26756, #36144).
                if ($this->shouldUseM5ParseSpineCFloor()) {
                    $this->ensureM5ParseSpineCFloorSymbols();

                    return JIT\RuntimeParseM5Native::emitFunction(
                        $this->context,
                        $internalName,
                        $logicalName,
                        fn (string $n): string => $this->llvmInternalName($n)
                    );
                }
                if ($this->shouldUseM3EmitTuRuntimeMethodStub('parse')) {
                    return $this->emitM3EmitTuRuntimeParseStubNative($internalName, $logicalName, $block);
                }

                return $this->compileRuntimeSpinePhpLowering($internalName, $block, $logicalName);
            }
            if ($this->shouldStubInventoryArgvPreprocessSpineMethods()
                && (
                    str_ends_with($m3Spine, '\\runtime::preprocesssourceforparse')
                    || str_ends_with($m3Spine, '\\runtime::rewritesourcebeforeparser')
                )
            ) {
                return $this->compileSkippedCompilerSplitCfgStub(
                    $internalName,
                    $this->m3CompileDriverMainBlock ?? $this->m3EmitTuMainBlock ?? $block,
                    $logicalName ?? $internalName
                );
            }
            if (str_ends_with($m3Spine, '\\runtime::compileemitsmoke')) {
                if ($this->shouldUseM3EmitTuRuntimeMethodStub('compileemitsmoke')) {
                    return $this->emitM3EmitTuRuntimeCompileEmitSmokeNative($internalName, $logicalName, $block);
                }

                return $this->compileRuntimeSpinePhpLowering($internalName, $block, $logicalName);
            }
            if (str_ends_with($m3Spine, '\\runtime::standalone')) {
                if ($this->shouldUseM3EmitTuRuntimeMethodStub('standalone')) {
                    return $this->emitM3EmitTuRuntimeStandaloneStubNative($internalName, $logicalName, $block);
                }

                return $this->compileRuntimeSpinePhpLowering($internalName, $block, $logicalName);
            }
            if (
                str_ends_with($m3Spine, '\\runtime::compile')
                || (
                    str_ends_with($m3Spine, '\\runtime::parseandcompile')
                    && !$this->shouldUseM3EmitTuNativeBridge()
                    && !$this->shouldUseM3InventoryEmitDriver()
                )
                || str_ends_with($m3Spine, '\\runtime::parseandcompileemitsmoke')
            ) {
                return $this->compileRuntimeSpinePhpLowering($internalName, $block, $logicalName);
            }
            if ($this->isM3EmitHelperCompilerPhpLoweringName($m3Spine)) {
                return $this->compileRuntimeSpinePhpLowering($internalName, $block, $logicalName);
            }
            if ($this->isM3CompileDriverBlockPhpLoweringName($m3Spine)) {
                return $this->compileBlockPhpLowering($internalName, $block, $logicalName, $funcName);
            }
        }
        if ($this->shouldUseM3EmitTuNativeBridge() && null !== $logicalName) {
            $m3Compiler = strtolower($logicalName);
            if ('phpcompiler\\compiler::compileemitsmoke' === $m3Compiler
                && !$this->shouldUseM3CompileDriverRealLowering()
            ) {
                return $this->emitM3EmitTuCompilerCompileEmitSmokeNativeFunction($internalName, $logicalName);
            }
        }
        if ($this->shouldUseM3CompileDriverRealLowering() && null !== $logicalName) {
            $m3CompilerSetter = strtolower($logicalName);
            if (str_ends_with($m3CompilerSetter, '\\compiler::setpropertyhookregistry')
                || str_ends_with($m3CompilerSetter, '\\compiler::setknownclassreadonly')
                || str_ends_with($m3CompilerSetter, '\\compiler::setbarerethrowlines')
            ) {
                return $this->emitM3EmitTuCompilerArrayPropertySetterVoidStub(
                    $internalName,
                    $logicalName,
                    $this->m3EmitTuMainBlock ?? $this->m3CompileDriverMainBlock
                );
            }
            if (str_ends_with($m3CompilerSetter, '\\compiler::setcompileabortdetailifempty')
                || str_ends_with($m3CompilerSetter, '\\compiler::setdebuglastphaseinputfile')
            ) {
                return $this->emitM3EmitTuCompilerStringSetterVoidStub(
                    $internalName,
                    $logicalName,
                    $this->m3EmitTuMainBlock ?? $this->m3CompileDriverMainBlock
                );
            }
            if (str_ends_with($m3CompilerSetter, '\\compiler::resetcompileabortdetail')) {
                return $this->emitM3EmitTuCompilerVoidStub(
                    $internalName,
                    $logicalName,
                    $this->m3EmitTuMainBlock ?? $this->m3CompileDriverMainBlock
                );
            }
            if (str_ends_with($m3CompilerSetter, '\\compiler::getdebuglastphaseinputfile')
                || str_ends_with($m3CompilerSetter, '\\compiler::getcompileabortdetail')
            ) {
                return $this->emitM3EmitTuCompilerNullStringGetterStub(
                    $internalName,
                    $logicalName,
                    $this->m3EmitTuMainBlock ?? $this->m3CompileDriverMainBlock
                );
            }
            if ('phpcompiler\\compiler::compile' === $m3CompilerSetter
                && $this->shouldUseM3InventoryEmitDriver()
                && $this->shouldUseEmitHelperLinkStubs()
            ) {
                return $this->emitM3EmitTuCompilerCompileNullStubNative($internalName, $logicalName);
            }
        }
        if ($this->shouldUseSelfHostJitStubs() && null !== $logicalName) {
            $vmSpineLc = strtolower($logicalName);
            if (JIT\VmSpineSmokeNative::isVmRunSmokeName($vmSpineLc)) {
                return $this->compileVmRunSmokeNative(
                    $this->llvmInternalName($internalName),
                    $block,
                    $logicalName
                );
            }
            if (JIT\VmUnitProbeExecuteNative::isVmUnitProbeRunName($vmSpineLc)) {
                return $this->compileVmUnitProbeRunNative(
                    $this->llvmInternalName($internalName),
                    $block,
                    $logicalName
                );
            }
            if (JIT\VmDriverExecuteNative::isBinVmRunName($vmSpineLc, $block)) {
                return $this->compileBinVmRunNative(
                    $this->llvmInternalName($internalName),
                    $block,
                    $logicalName
                );
            }
        }
        if (
            $this->shouldUseSelfHostJitStubs()
            && null !== $logicalName
            && $this->isSuperglobalNameJitFunction($logicalName)
        ) {
            return $this->compileSuperglobalNameNative($internalName, $block, $logicalName);
        }
        if (
            $this->shouldUseSelfHostJitStubs()
            && null !== $logicalName
            && JIT\OperandNameNative::isNativeLoweringName(strtolower($logicalName))
        ) {
            return JIT\OperandNameNative::compile(
                $this->context,
                $this->llvmInternalName($internalName),
                $block,
                $logicalName
            );
        }
        if ($this->isSkippedVmHotPathName($skipName)) {
            return $this->compileSkippedVmHotPathStub($internalName, $block, $logicalName ?? $internalName);
        }
        if ($block->isGenerator) {
            return JIT\GeneratorHelper::compileResumeFunction(
                $this,
                $internalName,
                $block,
                $logicalName ?? $internalName
            );
        }
        if ($this->isSkippedM3EmitTuBundledHelperName($skipName)) {
            return $this->compileSkippedCompilerSplitCfgStub($internalName, $block, $logicalName ?? $internalName);
        }
        if ($this->isSkippedCompilerHotPathName($skipName)
            || $this->isSkippedWebBootstrapHotPathName($skipName)
            || $this->isSkippedLibSpineSmokeHotPathName($skipName)
            || $this->isSkippedSelfHostEntryName($skipName)
            || $this->isSkippedBootstrapInterpreterHotPathName($skipName)
        ) {
            return $this->compileSkippedCompilerSplitCfgStub($internalName, $block, $logicalName ?? $internalName);
        }
        if (
            $this->shouldUseSelfHostJitStubs()
            && null !== $logicalName
            && str_ends_with(strtolower($logicalName), '\\runtime::__construct')
            && !$this->shouldUseM3CompileDriverRealLowering()
        ) {
            return $this->emitM3EmitTuRuntimeConstructNativeFunction($internalName, $logicalName, $block);
        }
        // Emit TU: stub bundled lib/ except M3 compile-driver Compiler/Web CFG (#2540, #2633).
        if ($this->shouldUseM3EmitTuNativeBridge() && null !== $logicalName) {
            $emitLc = strtolower($logicalName);
            if ($this->shouldUseM3CompileDriverRealLowering()
                && (
                    $this->isM3EmitTuCompilerCompileChainLoweringName($emitLc)
                    || $this->isLiteralIncludeDiscoveryRealLoweringMethod($emitLc)
                    || $this->isDeployRootRealLoweringMethod($emitLc)
                    || $this->isSourceBundlerRealLoweringMethod($emitLc)
                    || $this->isConstStringFolderRealLoweringMethod($emitLc)
                    || $this->isSuperglobalsRealLoweringMethod($emitLc)
                    || $this->isIncludePathResolverRealLoweringMethod($emitLc)
                    || $this->isM3EmitTuRuntimeCompileDriverSpineLoweringName($emitLc)
                    || $this->isM3CompileDriverBlockPhpLoweringName($emitLc)
                )
            ) {
                return $this->compileBlockPhpLowering($internalName, $block, $logicalName, $funcName);
            }

            return $this->compileSkippedCompilerSplitCfgStub($internalName, $block, $logicalName ?? $internalName);
        }

        return $this->compileBlockPhpLowering($internalName, $block, $logicalName, $funcName);
    }
}
