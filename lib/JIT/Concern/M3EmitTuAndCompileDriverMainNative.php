<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Block;
use PHPLLVM;

/**
 * M3 emit-TU / compile-driver native `{main}` bridges (#36387).
 *
 * Extracted from {@see \PHPCompiler\JIT}: {@code compileBootstrapCompileSmokeM3EmitNative}
 * through {@code compileM3CompileDriverMainNative} so the hub shrinks toward
 * split-TU iterability under the size-budget ratchet.
 *
 * php-src: Zend/zend_execute_API.c / sapi CLI argv + compile pipeline entry —
 * move-only Concern extract; no new C ABI and no opcode/IR shape change.
 */
trait M3EmitTuAndCompileDriverMainNative
{
    /** Thin native LLVM bridge for compile_smoke_m3_emit when emit-helper link is active (#1983). */
    private function compileBootstrapCompileSmokeM3EmitNative(
        string $internalName,
        Block $block,
        string $logicalName
    ): PHPLLVM\Value {
        $this->compileM3EmitTuRuntimeSpineDecls();
        $lcname = strtolower($logicalName);
        if (isset($this->context->functions[$lcname])) {
            return $this->context->functions[$lcname];
        }
        $strPtr = $this->context->getTypeFromString('__string__*');
        $i64 = $this->context->getTypeFromString('int64');
        $func = $this->context->module->addFunction(
            $this->llvmInternalName($internalName),
            $this->context->context->functionType($i64, false, $strPtr, $strPtr)
        );
        $bb = $func->appendBasicBlock('entry');
        $saved = $this->context->builder;
        $this->context->builder = $this->context->context->builderCreate();
        $this->context->builder->positionAtEnd($bb);
        $logPrefix = Config::getenv('PHP_COMPILER_M3_EMIT_LOG_PREFIX');
        if (!is_string($logPrefix) || '' === $logPrefix) {
            $logPrefix = str_contains($lcname, 'runtime_compile_smoke_m3_emit')
                ? 'runtime_compile_smoke_m3_emit'
                : 'compile_smoke_m3_emit';
        }
        \PHPCompiler\JIT\BootstrapCompileSmokeM3Emit::emit(
            $this->context,
            $func->getParam(0),
            $func->getParam(1),
            $logPrefix
        );
        $this->context->builder->clearInsertionPosition();
        $this->context->builder = $saved;
        $this->context->functions[$lcname] = $func;
        $this->context->functionReturnType[$lcname] = 'int64';
        $this->context->functionProxies[$lcname] = new JIT\Call\Native(
            $func,
            $logicalName,
            [$strPtr, $strPtr],
            $this->collectParamDefaults($block)
        );

        return $func;
    }

    /** Native {main} for M3 emit TU — libc getenv + emit bridge after spine pre-lower (#1937, #2550). */
    private function compileM3EmitTuMainNative(string $internalName, Block $block, ?string $logicalName): PHPLLVM\Value
    {
        $lcname = strtolower($logicalName ?? '{main}');
        if (isset($this->context->functions[$lcname])) {
            return $this->context->functions[$lcname];
        }
        if (!$this->m3EmitTuRuntimeSpineLowered) {
            $this->m3EmitTuRuntimeSpineLowered = true;
            $stubBlock = $this->m3EmitTuMainBlock ?? $this->m3CompileDriverMainBlock ?? $block;
            $this->compileM3EmitTuRuntimeSpineDecls($stubBlock);
            $sidecar = $this->isM3EmitTuTrivialEchoSidecarActive();
            $inventoryEmit = $this->shouldUseM3InventoryEmitForCompileDriverBlock($block);
            foreach (['parse', 'compileemitsmoke', 'standalone'] as $methodLc) {
                if ('standalone' === $methodLc && ($sidecar || $inventoryEmit)) {
                    continue;
                }
                $this->compileM3EmitTuRuntimeMethodFromQueue($methodLc);
            }
            $this->runQueue();
        }
        $i64 = $this->context->getTypeFromString('int64');
        $func = $this->context->module->addFunction(
            $this->llvmInternalName($internalName),
            $this->context->context->functionType($i64, false)
        );
        $bb = $func->appendBasicBlock('entry');
        $saved = $this->context->builder;
        $this->context->builder = $this->context->context->builderCreate();
        $this->context->builder->positionAtEnd($bb);
        JIT\Builtin\CliArgvRuntime::ensureLinked($this->context);
        $diagStubBlock = $this->m3EmitTuMainBlock ?? $this->m3CompileDriverMainBlock ?? $block;
        $constructLogical = 'PHPCompiler\\Runtime::__construct';
        $constructLc = strtolower($constructLogical);
        if (!isset($this->context->functions[$constructLc])) {
            $this->emitM3EmitTuRuntimeConstructNativeFunction(
                $this->llvmInternalName($constructLogical),
                $constructLogical,
                $diagStubBlock
            );
        }
        if ($this->shouldRealLowerInventoryArgvParseSpine()) {
            $peekLogical = 'PHPCompiler\\Runtime::peeklastparsefailure';
            $peekLc = strtolower($peekLogical);
            unset(
                $this->context->functions[$peekLc],
                $this->context->functionReturnType[$peekLc],
                $this->context->functionProxies[$peekLc]
            );
            $this->emitM3EmitTuCompilerNullStringGetterStub(
                $this->llvmInternalName($peekLogical),
                $peekLogical,
                $diagStubBlock
            );
        }
        $logPrefix = Config::getenv('PHP_COMPILER_M3_EMIT_LOG_PREFIX');
        if (!is_string($logPrefix) || '' === $logPrefix) {
            $logPrefix = 'compile_smoke_m3_emit';
        }
        \PHPCompiler\JIT\BootstrapCompileSmokeM3Emit::emitMainEntry($this->context, $logPrefix);
        $this->context->builder->clearInsertionPosition();
        $this->context->builder = $saved;
        $this->context->functions[$lcname] = $func;
        $this->context->functionReturnType[$lcname] = 'int64';
        $this->context->functionProxies[$lcname] = new JIT\Call\Native($func, $logicalName ?? '{main}', [], []);

        return $func;
    }

    /** Native {main} for M3 compile_driver bundles — avoids LLVM 9 crash lowering Runtime ctor in PHP CFG (#1768). */
    private function compileM3CompileDriverMainNative(string $internalName, Block $block, ?string $logicalName): PHPLLVM\Value
    {
        $lcname = strtolower($logicalName ?? $internalName);
        if ($this->isM4BinCompileScriptMain($block)
            && ($this->shouldUseM4BinCompileArgvMainNative() || $this->shouldUseHelloworldBinCompileInventoryArgvLink())
        ) {
            unset(
                $this->context->functions[$lcname],
                $this->context->functionReturnType[$lcname],
                $this->context->functionProxies[$lcname]
            );
        }
        if (isset($this->context->functions[$lcname])) {
            return $this->context->functions[$lcname];
        }
        if ($this->isM4BinCompileScriptMain($block)
            && ($this->shouldUseM4BinCompileArgvMainNative() || $this->shouldUseHelloworldBinCompileInventoryArgvLink())
        ) {
            $internalName = 'm4_inventory_argv_main';
        }
        $i64 = $this->context->getTypeFromString('int64');
        $func = $this->context->module->addFunction(
            $this->llvmInternalName($internalName),
            $this->context->context->functionType($i64, false)
        );
        $bb = $func->appendBasicBlock('entry');
        $saved = $this->context->builder;
        $m4BinCompileArgv = $this->isM4BinCompileScriptMain($block) && $this->shouldUseM4BinCompileArgvMainNative();
        $m4NativeRebuild = $m4BinCompileArgv && $this->shouldUseM4InventoryArgvNativeEmitRebuild($block);
        $logPrefix = Config::getenv('PHP_COMPILER_M3_EMIT_LOG_PREFIX');
        if (!is_string($logPrefix) || '' === $logPrefix) {
            $logPrefix = 'helloworld_compile_smoke';
        }
        if ($m4NativeRebuild) {
            $this->context->builder = $this->context->context->builderCreate();
            $this->context->builder->positionAtEnd($bb);
            \PHPCompiler\JIT\BootstrapCompileSmokeM3Emit::emitMainEntry($this->context, $logPrefix);
            $this->context->builder->clearInsertionPosition();
            $this->context->builder = $saved;
            $this->context->functions[$lcname] = $func;
            $this->context->functionReturnType[$lcname] = 'int64';
            $this->m3CompileDriverRuntimeSpineLowered = true;
            $this->filterM4InventoryArgvMainFromQueue();
            $this->ensureM3EmitTuEmitBridgeSpineSymbols();
            $this->compileM3EmitTuRuntimeParseAndCompileNativeDecl([
                'parseandcompile' => true,
                'parseandcompileemitsmoke' => true,
            ]);
            $this->context->functionProxies[$lcname] = new JIT\Call\Native($func, $logicalName ?? '{main}', [], []);

            return $func;
        }
        if ($this->shouldUseM3InventoryEmitForCompileDriverBlock($block) || $m4BinCompileArgv) {
            $this->context->functions[$lcname] = $func;
            $this->context->functionReturnType[$lcname] = 'int64';
            if (!$this->m3CompileDriverRuntimeSpineLowered) {
                $this->m3CompileDriverRuntimeSpineLowered = true;
                $this->context->builder->clearInsertionPosition();
                $this->filterM4InventoryArgvMainFromQueue();
                $this->compileM3EmitTuRuntimeSpineDecls($this->m3CompileDriverMainBlock);
                $sidecar = $this->isM3EmitTuTrivialEchoSidecarActive();
                $inventoryEmit = $this->shouldUseM3InventoryEmitForCompileDriverBlock($block);
                $inventoryArgvParseHelper = $this->shouldEnsureInventoryArgvParseHelperStubs()
                    && !$this->shouldRealLowerInventoryArgvParseSpine();
                if (!$inventoryArgvParseHelper) {
                    foreach (['parse', 'compileemitsmoke', 'standalone'] as $methodLc) {
                        if ('standalone' === $methodLc && ($sidecar || $inventoryEmit)) {
                            continue;
                        }
                        $this->compileM3EmitTuRuntimeMethodFromQueue($methodLc);
                    }
                    if (!$m4BinCompileArgv) {
                        $this->runQueue();
                    }
                }
            }
            if (null !== $this->m3CompileDriverMainBlock) {
                $standaloneLc = strtolower('PHPCompiler\\Runtime::standalone');
                unset(
                    $this->context->functions[$standaloneLc],
                    $this->context->functionReturnType[$standaloneLc],
                    $this->context->functionProxies[$standaloneLc]
                );
                if (!$this->shouldUseM4InventoryArgvNativeEmitRebuild($this->m3CompileDriverMainBlock)
                    || \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::isRegistered($this->context)) {
                    $this->emitM3EmitTuRuntimeStandaloneStubNative(
                        $this->llvmInternalName('PHPCompiler\\Runtime::standalone'),
                        'PHPCompiler\\Runtime::standalone',
                        $this->m3CompileDriverMainBlock
                    );
                }
            }
            $this->context->builder = $this->context->context->builderCreate();
            $this->context->builder->positionAtEnd($bb);
            JIT\Builtin\CliArgvRuntime::ensureLinked($this->context);
            $diagStubBlock = $this->m3CompileDriverMainBlock ?? $this->m3EmitTuMainBlock;
            if (null !== $diagStubBlock) {
                $constructLogical = 'PHPCompiler\\Runtime::__construct';
                $constructLc = strtolower($constructLogical);
                if (!isset($this->context->functions[$constructLc])) {
                    $this->emitM3EmitTuRuntimeConstructNativeFunction(
                        $this->llvmInternalName($constructLogical),
                        $constructLogical,
                        $diagStubBlock
                    );
                }
                if ($this->shouldRealLowerInventoryArgvParseSpine()) {
                    $peekLogical = 'PHPCompiler\\Runtime::peeklastparsefailure';
                    $peekLc = strtolower($peekLogical);
                    unset(
                        $this->context->functions[$peekLc],
                        $this->context->functionReturnType[$peekLc],
                        $this->context->functionProxies[$peekLc]
                    );
                    $this->emitM3EmitTuCompilerNullStringGetterStub(
                        $this->llvmInternalName($peekLogical),
                        $peekLogical,
                        $diagStubBlock
                    );
                }
            }
            \PHPCompiler\JIT\BootstrapCompileSmokeM3Emit::emitMainEntry($this->context, $logPrefix);
        } else {
            $this->context->builder = $this->context->context->builderCreate();
            $this->context->builder->positionAtEnd($bb);
            \PHPCompiler\JIT\ValueEchoHelper::echoLiteral($this->context, "compiler_helloworld_compile_driver ready\n");
            $this->context->builder->returnValue($i64->constInt(0, false));
        }

        $this->context->builder->clearInsertionPosition();
        $this->context->builder = $saved;
        if (!isset($this->context->functions[$lcname])) {
            $this->context->functions[$lcname] = $func;
            $this->context->functionReturnType[$lcname] = 'int64';
        }
        $this->context->functionProxies[$lcname] = new JIT\Call\Native($func, $logicalName ?? '{main}', [], []);

        return $func;
    }

}
