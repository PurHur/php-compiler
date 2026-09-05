<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Block;
use PHPLLVM;

/**
 * VM smoke + Runtime M3 native stub lowering (#36387).
 *
 * Extracted from {@see \PHPCompiler\JIT}: {@code compileVmRunSmokeNative}
 * through {@code emitM5ArgvResolveSidecarIdentityStub} so the hub shrinks toward
 * split-TU iterability under the size-budget ratchet.
 *
 * php-src: Zend/zend_execute.c / Zend/zend_compile.c — native entry stubs for
 * Runtime::loadJit* / init* / parseAndCompile and thin VM smoke probes; move-only
 * Concern extract; no new C ABI and no opcode/IR shape change. Prior #1846 / #26756.
 */
trait VmSmokeAndRuntimeM3NativeStubs
{
    /** Native vm_run_smoke for M2 lib spine VM -r gate (#1846). */
    private function compileVmRunSmokeNative(
        string $internalName,
        Block $block,
        string $logicalName
    ): PHPLLVM\Value {
        $paramTypes = [];
        if (null !== $block->func) {
            foreach ($block->func->params as $idx => $param) {
                $paramTypes[] = $this->llvmTypeForCfgParam($param, $block, $idx);
            }
        }

        return JIT\VmSpineSmokeNative::compileVmRunSmokeNative(
            $this->context,
            $internalName,
            $logicalName,
            $paramTypes
        );
    }

    /** Native vm_unit_probe_run for M3 VM unit probe execute gate (#2619). */
    private function compileVmUnitProbeRunNative(
        string $internalName,
        Block $block,
        string $logicalName
    ): PHPLLVM\Value {
        $paramTypes = [];
        if (null !== $block->func) {
            foreach ($block->func->params as $idx => $param) {
                $paramTypes[] = $this->llvmTypeForCfgParam($param, $block, $idx);
            }
        }

        return JIT\VmUnitProbeExecuteNative::compileVmUnitProbeRunNative(
            $this->context,
            $internalName,
            $logicalName,
            $paramTypes
        );
    }

    /** Native bin/vm.php run() for M2 VM driver execute gate (#2201). */
    private function compileBinVmRunNative(
        string $internalName,
        Block $block,
        string $logicalName
    ): PHPLLVM\Value {
        $paramTypes = [];
        if (null !== $block->func) {
            foreach ($block->func->params as $idx => $param) {
                $paramTypes[] = $this->llvmTypeForCfgParam($param, $block, $idx);
            }
        }

        return JIT\VmDriverExecuteNative::compileBinVmRunNative(
            $this->context,
            $internalName,
            $logicalName,
            $paramTypes
        );
    }

    /** Native __compiler_is_superglobal_name for self-host AOT (issue #1056). */
    private function compileSuperglobalNameNative(
        string $internalName,
        Block $block,
        string $logicalName
    ): PHPLLVM\Value {
        $lcname = strtolower($logicalName);
        if (isset($this->context->functions[$lcname])) {
            return $this->context->functions[$lcname];
        }
        $strPtr = $this->context->getTypeFromString('__string__*');
        $boolTy = $this->context->getTypeFromString('bool');
        $func = $this->context->module->addFunction(
            $this->llvmInternalName($internalName),
            $this->context->context->functionType($boolTy, false, $strPtr)
        );
        $bb = $func->appendBasicBlock('entry');
        $saved = $this->context->builder;
        $this->context->builder = $this->context->context->builderCreate();
        $this->context->builder->positionAtEnd($bb);
        \PHPCompiler\JIT\Builtin\StringSuperglobalName::ensureLinked($this->context);
        $raw = $this->context->builder->call(
            $this->context->lookupFunction('__compiler_is_superglobal_name'),
            $func->getParam(0)
        );
        $this->context->builder->returnValue(
            $this->context->builder->icmp(
                \PHPLLVM\Builder::INT_NE,
                $raw,
                $raw->typeOf()->constInt(0, false)
            )
        );
        $this->context->builder->clearInsertionPosition();
        $this->context->builder = $saved;
        $this->context->functions[$lcname] = $func;
        $this->context->functionReturnType[$lcname] = 'bool';
        $this->context->functionProxies[$lcname] = new JIT\Call\Native(
            $func,
            $logicalName,
            [$strPtr],
            $this->collectParamDefaults($block)
        );

        return $func;
    }

    /**
     * Retired (#8707): inventory emit-helper now real-lowers JIT spine methods; deny-list only via
     * isM3CompileDriverSpineDenyName() for proven LLVM 9 crashers.
     */
    private function shouldStubM3InventoryEmitJitSpineMethods(): bool
    {
        return false;
    }

    private function compileRuntimeLoadJitM3Native(
        string $internalName,
        Block $block,
        string $logicalName
    ): PHPLLVM\Value {
        if ($this->shouldStubM3InventoryEmitJitSpineMethods()) {
            return $this->emitM3EmitTuRuntimeInitVoidStub($internalName, $logicalName, $block);
        }

        return $this->compileRuntimeSpinePhpLowering($internalName, $block, $logicalName);
    }

    /**
     * M3 compile-driver loadJitContext (#1402, #2846): separate FUNCDEF from loadJit to avoid LLVM 9 inlining crash.
     */
    private function compileRuntimeLoadJitContextM3Native(
        string $internalName,
        Block $block,
        string $logicalName
    ): PHPLLVM\Value {
        if ($this->shouldStubM3InventoryEmitJitSpineMethods()) {
            return $this->emitM3EmitTuRuntimeInitVoidStub($internalName, $logicalName, $block);
        }

        return $this->compileRuntimeSpinePhpLowering($internalName, $block, $logicalName);
    }

    /** M3 compile-driver createJit (#1402, #2847): `new JIT` separate from loadJit. */
    private function compileRuntimeCreateJitM3Native(
        string $internalName,
        Block $block,
        string $logicalName
    ): PHPLLVM\Value {
        if ($this->shouldStubM3InventoryEmitJitSpineMethods()) {
            return $this->emitM3EmitTuRuntimeInitVoidStub($internalName, $logicalName, $block);
        }

        return $this->compileRuntimeSpinePhpLowering($internalName, $block, $logicalName);
    }

    /** M3 compile-driver jitContextForLoadJit (#1402, #2847): thin wrapper — separate FUNCDEF from loadJit. */
    private function compileRuntimeJitContextForLoadJitM3Native(
        string $internalName,
        Block $block,
        string $logicalName
    ): PHPLLVM\Value {
        if ($this->shouldStubM3InventoryEmitJitSpineMethods()) {
            return $this->emitM3EmitTuRuntimeInitVoidStub($internalName, $logicalName, $block);
        }

        return $this->compileRuntimeSpinePhpLowering($internalName, $block, $logicalName);
    }

    /** M3 compile-driver loadJitCompileModuleFuncs (#1402, #2847): nested foreach — separate FUNCDEF from loadJit. */
    private function compileRuntimeLoadJitCompileModuleFuncsM3Native(
        string $internalName,
        Block $block,
        string $logicalName
    ): PHPLLVM\Value {
        if ($this->shouldStubM3InventoryEmitJitSpineMethods()) {
            return $this->emitM3EmitTuRuntimeInitVoidStub($internalName, $logicalName, $block);
        }

        return $this->compileRuntimeSpinePhpLowering($internalName, $block, $logicalName);
    }

    /** M3 compile-driver Runtime::__construct (#1494): C-floor vmContext — not full PHP CFG (LLVM 9; #2600). */
    private function compileRuntimeConstructM3Native(
        string $internalName,
        Block $block,
        string $logicalName
    ): PHPLLVM\Value {
        if ($this->shouldUseM3CompileDriverRealLowering()
            || $this->shouldUseM3EmitTuRuntimeMethodStub('__construct')
        ) {
            return $this->emitM3EmitTuRuntimeConstructNativeFunction($internalName, $logicalName, $block);
        }

        return $this->compileRuntimeSpinePhpLowering($internalName, $block, $logicalName);
    }

    /**
     * M3 compile-driver Runtime::__destruct (#2867): void no-op — module shutdown not required at AOT link.
     * PHP CFG foreach over $this->modules LLVM 9-crashed when deny-listed (#1402).
     */
    private function compileRuntimeDestructM3Native(
        string $internalName,
        Block $block,
        string $logicalName
    ): PHPLLVM\Value {
        return $this->emitM3EmitTuRuntimeInitVoidStub($internalName, $logicalName, $block);
    }

    /**
     * Emit-helper link without full compile_driver: real-lower Runtime parse spine (#2559).
     *
     * Uses host parse of lib/Runtime.php at link time; avoids LLVM 9 global ctor from bundling PHPTypes in emit TU.
     */
    private function shouldUseM3EmitTuEmitHelperSpineRealLowering(): bool
    {
        if (!$this->shouldUseM3EmitTuNativeBridge() || !$this->shouldUseEmitHelperLinkStubs()) {
            return false;
        }
        if ($this->shouldUseM3CompileDriverRealLowering()) {
            return false;
        }
        $flag = Config::getenv('PHP_COMPILER_M3_EMIT_HELPER_SPINE');

        return '1' === $flag || 'true' === strtolower((string) $flag);
    }

    /** Emit TU null-returning stubs unless M3 real-lowering is enabled (#2512, #2542). */
    private function shouldUseM3EmitTuRuntimeMethodStub(string $methodLc): bool
    {
        // Inventory argv driver (bin/compile.php re-link) — not emit-helper inventory link (#2540).
        if ($this->shouldUseM3InventoryEmitDriver() && !$this->shouldUseEmitHelperLinkStubs()) {
            static $inventoryEmitSpine = [
                '__construct',
                'initparsepipeline',
                'initcompiler',
                'initvmcontext',
                'loadcoremodules',
                'standalone',
            ];
            if (in_array($methodLc, $inventoryEmitSpine, true)) {
                // Real argv parse spine needs ctor/init; standalone stays stubbed (#15597).
                // Do not void-stub initParsePipeline under M5 — seed would lack $parser (#26756).
                if ($this->shouldUseM5DriverHostCompile() && 'initparsepipeline' === $methodLc) {
                    return false;
                }
                if ($this->shouldRealLowerInventoryArgvParseSpine() && 'standalone' !== $methodLc) {
                    return false;
                }

                return true;
            }
        }
        if (!$this->shouldUseM3EmitTuNativeBridge() && !$this->shouldUseM3InventoryEmitDriver()) {
            return false;
        }
        if (!$this->shouldUseM3CompileDriverRealLowering()) {
            if ($this->shouldUseM3EmitTuEmitHelperSpineRealLowering()) {
                $emitHelperSpineReal = [
                    'preprocesssourceforparse',
                    'rewritesourcebeforeparser',
                    'preparesourceforparser',
                    'parse',
                    'compileemitsmoke',
                ];
                if ($this->shouldUseVendorPrelinkExecutableEmit()
                    || $this->shouldUseSelfHostExecutableEmit()) {
                    $emitHelperSpineReal = ['parse', 'compile', 'standalone'];
                }

                return !in_array($methodLc, $emitHelperSpineReal, true);
            }

            return true;
        }

        return !$this->isM3CompileDriverRealLoweringName('phpcompiler\\runtime::'.$methodLc);
    }

    /**
     * Native parseAndCompile for M3 emit TU — avoids LLVM 9 crash lowering full Runtime::compile (#2516).
     */
    private function compileRuntimeParseAndCompileM3Native(
        string $internalName,
        Block $block,
        string $logicalName
    ): PHPLLVM\Value {
        if ($this->shouldUseM3EmitTuNativeBridge() || $this->shouldUseM3InventoryEmitDriver()) {
            $targetLc = str_ends_with(strtolower($logicalName), '\\runtime::parseandcompileemitsmoke')
                ? 'parseandcompileemitsmoke'
                : 'parseandcompile';

            return \PHPCompiler\JIT\BootstrapCompileSmokeM3Emit::declareRuntimeParseAndCompileViaParseEmitSmoke(
                $this->context,
                $this->llvmInternalName($internalName),
                $logicalName,
                $targetLc
            );
        }

        return $this->compileRuntimeSpinePhpLowering($internalName, $block, $logicalName);
    }

    /**
     * Native Runtime::__construct for emit TU — C-floor vmContext when real-lowering (#2513, #2550).
     */
    private function emitM3EmitTuRuntimeConstructNativeFunction(
        string $internalName,
        string $logicalName,
        Block $block
    ): PHPLLVM\Value {
        $lcname = strtolower($logicalName);
        if (isset($this->context->functions[$lcname])) {
            return $this->context->functions[$lcname];
        }
        $objectPtr = $this->context->getTypeFromString('__object__*');
        $i64 = $this->context->getTypeFromString('int64');
        $voidTy = $this->context->getTypeFromString('void');
        $func = $this->context->module->addFunction(
            $this->llvmInternalName($internalName),
            $this->context->context->functionType($voidTy, false, $objectPtr, $i64)
        );
        $bb = $func->appendBasicBlock('entry');
        $saved = $this->context->builder;
        $this->context->builder = $this->context->context->builderCreate();
        $this->context->builder->positionAtEnd($bb);
        if ($this->shouldUseM3CompileDriverRealLowering() || $this->shouldUseSelfHostJitStubs()) {
            \PHPCompiler\JIT\RuntimeInitVmContext::emit(
                $this->context,
                $this->context->type->object,
                $func->getParam(0)
            );
            $modeSlot = $this->context->type->object->propertyFetch(
                $func->getParam(0),
                'PHPCompiler\\Runtime',
                'mode'
            );
            $modeVar = new JIT\Variable(
                $this->context,
                JIT\Variable::TYPE_NATIVE_LONG,
                JIT\Variable::KIND_VALUE,
                $func->getParam(1)
            );
            $this->context->type->object->propertyStore(
                $modeSlot->objectPropertySlot,
                $modeVar,
                JIT\Variable::TYPE_NATIVE_LONG
            );
        }
        $this->context->builder->returnVoid();
        $this->context->builder->clearInsertionPosition();
        $this->context->builder = $saved;
        $this->context->functions[$lcname] = $func;
        $this->context->functionReturnType[$lcname] = 'void';
        $this->context->functionProxies[$lcname] = new JIT\Call\Native(
            $func,
            $logicalName,
            [$objectPtr, $i64],
            $this->collectParamDefaults($block)
        );

        return $func;
    }

    private function compileRuntimeInitParsePipelineM3Native(
        string $internalName,
        Block $block,
        string $logicalName
    ): PHPLLVM\Value {
        $lcname = strtolower($logicalName);
        // M5 argv / inventory argv: C-floor BEFORE void-stub checks — inventory emit otherwise
        // registers a 1-byte `ret` and leaves $parser null (#26756 / re-#23468, #36144).
        if ($this->shouldUseM5ParseSpineCFloor()) {
            if (isset($this->context->functions[$lcname])) {
                return $this->context->functions[$lcname];
            }
            $objectPtr = $this->context->getTypeFromString('__object__*');
            $func = $this->context->module->addFunction(
                $this->llvmInternalName($internalName),
                $this->context->context->functionType(
                    $this->context->getTypeFromString('void'),
                    false,
                    $objectPtr
                )
            );
            $bb = $func->appendBasicBlock('entry');
            $saved = $this->context->builder;
            $this->context->builder = $this->context->context->builderCreate();
            $this->context->builder->positionAtEnd($bb);
            JIT\RuntimeInitParsePipeline::emit(
                $this->context,
                $this->context->type->object,
                $func->getParam(0)
            );
            $this->context->builder->returnVoid();
            $this->context->builder->clearInsertionPosition();
            $this->context->builder = $saved;
            $this->context->functions[$lcname] = $func;
            $this->context->functionReturnType[$lcname] = 'void';
            $this->context->functionProxies[$lcname] = new JIT\Call\Native(
                $func,
                $logicalName,
                [$objectPtr],
                $this->collectParamDefaults($block)
            );

            return $func;
        }
        if ($this->shouldUseM3EmitTuRuntimeMethodStub('initparsepipeline')) {
            return $this->emitM3EmitTuRuntimeInitVoidStub($internalName, $logicalName, $block);
        }
        if (isset($this->context->functions[$lcname])) {
            return $this->context->functions[$lcname];
        }
        if ($this->shouldUseM3EmitTuNativeBridge()
            || $this->shouldUseM3CompileDriverRealLowering()
            || $this->shouldRealLowerInventoryArgvParseSpine()
        ) {
            $this->compileM3EmitTuRuntimeMethodFromDeclareClassBlocks(['initparsepipeline']);
            if (!isset($this->context->functions[$lcname])) {
                $this->compileM3EmitTuRuntimeMethodFromRuntimePhpFile('initparsepipeline', $logicalName, $lcname);
            }
            if (isset($this->context->functions[$lcname])) {
                return $this->context->functions[$lcname];
            }

            return $this->emitM3EmitTuRuntimeInitVoidStub($internalName, $logicalName, $block);
        }

        return $this->compileRuntimeSpinePhpLowering($internalName, $block, $logicalName);
    }

    private function compileRuntimeInitCompilerM3Native(
        string $internalName,
        Block $block,
        string $logicalName
    ): PHPLLVM\Value {
        if ($this->shouldUseM3EmitTuRuntimeMethodStub('initcompiler')) {
            return $this->emitM3EmitTuRuntimeInitVoidStub($internalName, $logicalName, $block);
        }
        // Emit TU and M3 compile_driver share C-floor initCompiler (#2568); PHP CFG LLVM 9 crash on ctor spine.
        if ($this->shouldUseM3EmitTuNativeBridge() || $this->shouldUseM3CompileDriverRealLowering()) {
            $lcname = strtolower($logicalName);
            if (isset($this->context->functions[$lcname])) {
                return $this->context->functions[$lcname];
            }
            $objectPtr = $this->context->getTypeFromString('__object__*');
            $func = $this->context->module->addFunction(
                $this->llvmInternalName($internalName),
                $this->context->context->functionType(
                    $this->context->getTypeFromString('void'),
                    false,
                    $objectPtr
                )
            );
            $bb = $func->appendBasicBlock('entry');
            $saved = $this->context->builder;
            $this->context->builder = $this->context->context->builderCreate();
            $this->context->builder->positionAtEnd($bb);
            \PHPCompiler\JIT\RuntimeInitCompiler::emit(
                $this->context,
                $this->context->type->object,
                $func->getParam(0)
            );
            $this->context->builder->returnVoid();
            $this->context->builder->clearInsertionPosition();
            $this->context->builder = $saved;
            $this->context->functions[$lcname] = $func;
            $this->context->functionReturnType[$lcname] = 'void';
            $this->context->functionProxies[$lcname] = new JIT\Call\Native(
                $func,
                $logicalName,
                [$objectPtr],
                $this->collectParamDefaults($block)
            );

            return $func;
        }

        return $this->compileRuntimeSpinePhpLowering($internalName, $block, $logicalName);
    }

    private function compileRuntimeInitVmContextM3Native(
        string $internalName,
        Block $block,
        string $logicalName
    ): PHPLLVM\Value {
        // Emit TU and compile_driver share C-floor initVmContext (#2513, #2540).
        $lcname = strtolower($logicalName);
        if (isset($this->context->functions[$lcname])) {
            return $this->context->functions[$lcname];
        }
        $objectPtr = $this->context->getTypeFromString('__object__*');
        $func = $this->context->module->addFunction(
            $this->llvmInternalName($internalName),
            $this->context->context->functionType(
                $this->context->getTypeFromString('void'),
                false,
                $objectPtr
            )
        );
        $bb = $func->appendBasicBlock('entry');
        $saved = $this->context->builder;
        $this->context->builder = $this->context->context->builderCreate();
        $this->context->builder->positionAtEnd($bb);
        \PHPCompiler\JIT\RuntimeInitVmContext::emit($this->context, $this->context->type->object, $func->getParam(0));
        $this->context->builder->returnVoid();
        $this->context->builder->clearInsertionPosition();
        $this->context->builder = $saved;
        $this->context->functions[$lcname] = $func;
        $this->context->functionReturnType[$lcname] = 'void';
        $this->context->functionProxies[$lcname] = new JIT\Call\Native(
            $func,
            $logicalName,
            [$objectPtr],
            $this->collectParamDefaults($block)
        );
        return $func;
    }

    private function compileRuntimeLoadCoreModulesM3Native(
        string $internalName,
        Block $block,
        string $logicalName
    ): PHPLLVM\Value {
        // Same 0-arg-vs-1-arg signature mismatch as initParsePipeline (#2967): the PHP-CFG lowering
        // drops the implicit $this (module load() calls are elided in the self-host spine) while
        // RuntimeEmitTuInit calls it as `void(__object__*)`. Emit the 1-arg void stub in every mode.
        return $this->emitM3EmitTuRuntimeInitVoidStub($internalName, $logicalName, $block);
    }

    /** No-op init helper for emit TU link — real init deferred to Batch A (#2516). */
    private function emitM3EmitTuRuntimeInitVoidStub(
        string $internalName,
        string $logicalName,
        Block $block
    ): PHPLLVM\Value {
        $lcname = strtolower($logicalName);
        if (isset($this->context->functions[$lcname])) {
            return $this->context->functions[$lcname];
        }
        $objectPtr = $this->context->getTypeFromString('__object__*');
        $voidTy = $this->context->getTypeFromString('void');
        $func = $this->context->module->addFunction(
            $this->llvmInternalName($internalName),
            $this->context->context->functionType($voidTy, false, $objectPtr)
        );
        $bb = $func->appendBasicBlock('entry');
        $saved = $this->context->builder;
        $this->context->builder = $this->context->context->builderCreate();
        $this->context->builder->positionAtEnd($bb);
        $this->context->builder->returnVoid();
        $this->context->builder->clearInsertionPosition();
        $this->context->builder = $saved;
        $this->context->functions[$lcname] = $func;
        $this->context->functionReturnType[$lcname] = 'void';
        $this->context->functionProxies[$lcname] = new JIT\Call\Native(
            $func,
            $logicalName,
            [$objectPtr],
            $this->collectParamDefaults($block)
        );

        return $func;
    }

    /**
     * ResolveSidecarJitHelper path remap — identity is enough for gen-0 argv functional smoke
     * (never-seen scripts use live paths). Avoids NestedJIT IR blow-up (#26756 / #23970).
     */
    private function isM5ArgvResolveSidecarIdentityStubName(string $lower): bool
    {
        return str_contains($lower, '\\resolvesidecarjithelper::');
    }

    private function emitM5ArgvResolveSidecarIdentityStub(
        string $internalName,
        string $logicalName,
        Block $block
    ): PHPLLVM\Value {
        $lcname = strtolower($logicalName);
        if (isset($this->context->functions[$lcname])) {
            return $this->context->functions[$lcname];
        }
        $args = $this->normalizeSelfHostNativeCallArgTypes(
            $this->collectStubFunctionArgTypes($block),
            $logicalName
        );
        $callbackType = $this->cfgFunctionReturnCallbackType($block->func) ?? '__value__';
        $returnType = $this->context->getTypeFromString($callbackType);
        $func = $this->context->module->addFunction(
            $this->llvmInternalName($internalName),
            $this->context->context->functionType($returnType, false, ...$args)
        );
        $bb = $func->appendBasicBlock('m5_argv_resolve_sidecar_identity');
        $saved = $this->context->builder;
        $savedActive = $this->context->activeFunction;
        $this->context->builder = $this->context->context->builderCreate();
        $this->context->builder->positionAtEnd($bb);
        $this->context->functions[$lcname] = $func;
        $this->context->activeFunction = $lcname;
        $defaultArgs = $this->collectParamDefaults($block);
        if ($func->countParams() > 0) {
            $this->context->builder->returnValue($func->getParam(0));
        } else {
            $this->emitSelfHostStubReturn($callbackType, $func);
        }
        $this->context->builder->clearInsertionPosition();
        $this->context->builder = $saved;
        $this->context->activeFunction = $savedActive;
        $this->context->functionReturnType[$lcname] = $callbackType;
        $this->context->functionProxies[$lcname] = new JIT\Call\Native(
            $func,
            $logicalName,
            $args,
            $defaultArgs
        );

        return $func;
    }
}
