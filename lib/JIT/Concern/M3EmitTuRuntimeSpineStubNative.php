<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Block;
use PHPLLVM;

/**
 * M3 emit-TU Runtime spine stub natives (#36387).
 *
 * Extracted from {@see \PHPCompiler\JIT}: {@code compileM3EmitTuRuntimeSpineStub}
 * through {@code emitM3EmitTuRuntimeStandaloneStubNative} so the hub keeps
 * shrinking toward split-TU / compile-time targets under the size-budget ratchet.
 *
 * php-src: Zend/zend_compile.c / Zend/zend_execute.c — native LLVM stubs for
 * Runtime spine methods on the M3 emit-TU path (avoid PHPTypes global ctor);
 * move-only Concern extract; no new C ABI and no opcode/IR shape change. Prior #2540.
 */
trait M3EmitTuRuntimeSpineStubNative
{
    /**
     * Emit TU: native LLVM stubs for Runtime spine — avoid PHP CFG (PHPTypes global ctor; #2540).
     */
    private function compileM3EmitTuRuntimeSpineStub(
        string $internalName,
        Block $block,
        string $logicalName,
        string $lower
    ): PHPLLVM\Value {
        if (str_ends_with($lower, '\\runtime::__construct')) {
            return $this->emitM3EmitTuRuntimeConstructNativeFunction($internalName, $logicalName, $block);
        }
        if (str_ends_with($lower, '\\runtime::initvmcontext')) {
            return $this->compileRuntimeInitVmContextM3Native($internalName, $block, $logicalName);
        }
        if (
            str_ends_with($lower, '\\runtime::initparsepipeline')
            || str_ends_with($lower, '\\runtime::initcompiler')
            || str_ends_with($lower, '\\runtime::loadcoremodules')
            || str_ends_with($lower, '\\runtime::loadjit')
            || str_ends_with($lower, '\\runtime::jitcompileblock')
            || str_ends_with($lower, '\\runtime::jitemitinplace')
        ) {
            return $this->emitM3EmitTuRuntimeInitVoidStub($internalName, $logicalName, $block);
        }
        if (str_ends_with($lower, '\\runtime::parse')) {
            return $this->emitM3EmitTuRuntimeParseStubNative($internalName, $logicalName, $block);
        }
        if (str_ends_with($lower, '\\runtime::compileemitsmoke')) {
            return $this->emitM3EmitTuRuntimeCompileEmitSmokeNative($internalName, $logicalName, $block);
        }
        if (str_ends_with($lower, '\\runtime::standalone')) {
            return $this->emitM3EmitTuRuntimeStandaloneStubNative($internalName, $logicalName, $block);
        }
        if (
            str_ends_with($lower, '\\runtime::parseandcompile')
            || str_ends_with($lower, '\\runtime::parseandcompileemitsmoke')
        ) {
            return $this->compileRuntimeParseAndCompileM3Native($internalName, $block, $logicalName);
        }
        if (
            str_ends_with($lower, '\\runtime::compile')
            || str_ends_with($lower, '\\runtime::parseandcompilefile')
        ) {
            return $this->emitM3EmitTuRuntimeBlockPtrStubNative($internalName, $logicalName, $block);
        }
        // M5 argv diagnostics — void/null stubs (not PHP CFG) (#26756).
        if (str_ends_with($lower, '\\runtime::noteparsecompilenullforscript')) {
            return $this->emitM3EmitTuRuntimeTwoObjectVoidStub($internalName, $logicalName, $block);
        }
        if (str_ends_with($lower, '\\runtime::peeklastparsefailure')) {
            return $this->emitM3EmitTuCompilerNullStringGetterStub($internalName, $logicalName, $block);
        }

        throw new \LogicException('Unhandled M3 emit TU Runtime spine: '.$logicalName);
    }

    /** Stub Runtime::parse for emit TU link — Batch A replaces with real parser (#2516). */
    private function emitM3EmitTuRuntimeParseStubNative(
        string $internalName,
        string $logicalName,
        Block $block
    ): PHPLLVM\Value {
        $lcname = strtolower($logicalName);
        if (isset($this->context->functions[$lcname])) {
            return $this->context->functions[$lcname];
        }
        $objectPtr = $this->context->getTypeFromString('__object__*');
        $strPtr = $this->context->getTypeFromString('__string__*');
        $func = $this->context->module->addFunction(
            $internalName,
            $this->context->context->functionType($objectPtr, false, $objectPtr, $strPtr, $strPtr)
        );
        $bb = $func->appendBasicBlock('entry');
        $saved = $this->context->builder;
        $this->context->builder = $this->context->context->builderCreate();
        $this->context->builder->positionAtEnd($bb);
        $this->context->builder->returnValue($objectPtr->constNull());
        $this->context->builder->clearInsertionPosition();
        $this->context->builder = $saved;
        $this->context->functions[$lcname] = $func;
        $this->context->functionReturnType[$lcname] = '__object__*';
        $this->context->functionProxies[$lcname] = new JIT\Call\Native(
            $func,
            $logicalName,
            [$objectPtr, $strPtr, $strPtr],
            $this->collectParamDefaults($block)
        );

        return $func;
    }

    /** Stub Runtime methods returning ?Block for emit TU link (#2540). */
    private function emitM3EmitTuRuntimeBlockPtrStubNative(
        string $internalName,
        string $logicalName,
        Block $block
    ): PHPLLVM\Value {
        $lcname = strtolower($logicalName);
        if (isset($this->context->functions[$lcname])) {
            return $this->context->functions[$lcname];
        }
        $objectPtr = $this->context->getTypeFromString('__object__*');
        $args = $this->normalizeSelfHostNativeCallArgTypes(
            $this->collectStubFunctionArgTypes($block),
            $logicalName
        );
        $func = $this->context->module->addFunction(
            $internalName,
            $this->context->context->functionType($objectPtr, false, ...$args)
        );
        $bb = $func->appendBasicBlock('entry');
        $saved = $this->context->builder;
        $this->context->builder = $this->context->context->builderCreate();
        $this->context->builder->positionAtEnd($bb);
        $this->context->builder->returnValue($objectPtr->constNull());
        $this->context->builder->clearInsertionPosition();
        $this->context->builder = $saved;
        $this->context->functions[$lcname] = $func;
        $this->context->functionReturnType[$lcname] = '__object__*';
        $this->context->functionProxies[$lcname] = new JIT\Call\Native(
            $func,
            $logicalName,
            $args,
            $this->collectParamDefaults($block)
        );

        return $func;
    }

    /** Native Runtime::compileEmitSmoke — reuse Compiler emit-smoke block stub (#2442). */
    private function emitM3EmitTuRuntimeCompileEmitSmokeNative(
        string $internalName,
        string $logicalName,
        Block $block
    ): PHPLLVM\Value {
        $lcname = strtolower($logicalName);
        if (isset($this->context->functions[$lcname])) {
            return $this->context->functions[$lcname];
        }
        $objectPtr = $this->context->getTypeFromString('__object__*');
        $func = $this->context->module->addFunction(
            $internalName,
            $this->context->context->functionType($objectPtr, false, $objectPtr, $objectPtr)
        );
        $bb = $func->appendBasicBlock('entry');
        $saved = $this->context->builder;
        $this->context->builder = $this->context->context->builderCreate();
        $this->context->builder->positionAtEnd($bb);
        $this->context->builder->returnValue($objectPtr->constNull());
        $this->context->builder->clearInsertionPosition();
        $this->context->builder = $saved;
        $this->context->functions[$lcname] = $func;
        $this->context->functionReturnType[$lcname] = '__object__*';
        $this->context->functionProxies[$lcname] = new JIT\Call\Native(
            $func,
            $logicalName,
            [$objectPtr, $objectPtr],
            $this->collectParamDefaults($block)
        );

        return $func;
    }

    /**
     * Real Runtime::standalone for M5 vendor .o emit (separate symbol from sidecar stub — #3036).
     */
    private function ensureRuntimeStandaloneKeepObjectLoweringForLink(): ?PHPLLVM\Value
    {
        if (!$this->shouldPrelowerRuntimeStandaloneForKeepObjectEmit()) {
            return null;
        }
        $logical = 'PHPCompiler\\Runtime::standaloneKeepObject';
        $lc = strtolower($logical);
        if (isset($this->context->functions[$lc])) {
            return $this->context->functions[$lc];
        }
        $standaloneBlock = null;
        foreach ($this->queue as $item) {
            $func = $item[0];
            if (!$func instanceof CoreFunc\PHP) {
                continue;
            }
            if ('phpcompiler\\runtime::standalone' === strtolower($func->getName())) {
                $standaloneBlock = $func->block;
                break;
            }
        }
        if (null === $standaloneBlock) {
            $this->compileM3EmitTuRuntimeMethodFromModules('standalone');
            $this->runQueue();
            foreach ($this->queue as $item) {
                $func = $item[0];
                if (!$func instanceof CoreFunc\PHP) {
                    continue;
                }
                if ('phpcompiler\\runtime::standalone' === strtolower($func->getName())) {
                    $standaloneBlock = $func->block;
                    break;
                }
            }
            if (null === $standaloneBlock) {
                return null;
            }
        }

        return $this->compileRuntimeSpinePhpLowering(
            $this->llvmInternalName($logical),
            $standaloneBlock,
            $logical
        );
    }

    /** Stub Runtime::standalone for emit TU link — Batch A replaces (#2516). */
    private function emitM3EmitTuRuntimeStandaloneStubNative(
        string $internalName,
        string $logicalName,
        Block $block
    ): PHPLLVM\Value {
        $lcname = strtolower($logicalName);
        if (isset($this->context->functions[$lcname])) {
            return $this->context->functions[$lcname];
        }
        $keepObjectStandalone = $this->ensureRuntimeStandaloneKeepObjectLoweringForLink();
        if (\PHPCompiler\JIT\M3EmitTuTrivialEchoAot::isRegistered($this->context)) {
            \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::ensureSidecarCopyAbisForLink($this->context);
        }
        $objectPtr = $this->context->getTypeFromString('__object__*');
        $strPtr = $this->context->getTypeFromString('__string__*');
        $voidTy = $this->context->getTypeFromString('void');
        $func = $this->context->module->addFunction(
            $internalName,
            $this->context->context->functionType($voidTy, false, $objectPtr, $objectPtr, $strPtr)
        );
        $bb = $func->appendBasicBlock('entry');
        $saved = $this->context->builder;
        $this->context->builder = $this->context->context->builderCreate();
        $this->context->builder->positionAtEnd($bb);
        // M5 gen-0 never-seen echo: C-floor sentinel → cc ELF before sidecar/keepObject (#26756).
        if (\PHPCompiler\JIT\M5TrivialEchoNative::isRegistered($this->context)) {
            [$handled, $merge] = \PHPCompiler\JIT\M5TrivialEchoNative::emitStandaloneSentinelCheck(
                $this->context,
                $func->getParam(1),
                $func->getParam(2),
                'stub'
            );
            $cont = JIT\BasicBlockHelper::append($this->context, 'm5_te_stub_cont');
            $done = JIT\BasicBlockHelper::append($this->context, 'm5_te_stub_done');
            $this->context->builder->positionAtEnd($merge);
            $this->context->builder->branchIf($handled, $done, $cont);
            $this->context->builder->positionAtEnd($done);
            $this->context->builder->returnVoid();
            $this->context->builder->positionAtEnd($cont);
        }
        if (null !== $keepObjectStandalone) {
            \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::emitStandaloneWithKeepObjectDispatch(
                $this->context,
                $func->getParam(0),
                $func->getParam(1),
                $func->getParam(2),
                $keepObjectStandalone
            );
        } elseif (\PHPCompiler\JIT\M3EmitTuTrivialEchoAot::isRegistered($this->context)) {
            \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::emitStandaloneWriteCachedAot(
                $this->context,
                $func->getParam(1),
                $func->getParam(2)
            );
        } else {
            $this->context->builder->returnVoid();
        }
        $this->context->builder->clearInsertionPosition();
        $this->context->builder = $saved;
        $this->context->functions[$lcname] = $func;
        $this->context->functionReturnType[$lcname] = 'void';
        $this->context->functionProxies[$lcname] = new JIT\Call\Native(
            $func,
            $logicalName,
            [$objectPtr, $objectPtr, $strPtr],
            $this->collectParamDefaults($block)
        );

        return $func;
    }

}
