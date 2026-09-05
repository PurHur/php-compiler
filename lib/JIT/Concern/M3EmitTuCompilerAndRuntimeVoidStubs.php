<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Block;
use PHPLLVM;

/**
 * M3 emit-TU Compiler/Runtime link-only void/null stubs (#36387).
 *
 * Extracted from {@see \PHPCompiler\JIT}: {@code emitM3EmitTuCompilerCompileNullStubNative}
 * through {@code emitM3EmitTuRuntimeTwoObjectVoidStub} so the hub shrinks toward
 * split-TU iterability under the size-budget ratchet.
 *
 * php-src: Zend/zend_compile.c function/method stubs and Zend/zend_API.c
 * null/void return placeholders — move-only Concern extract; no new C ABI and
 * no opcode/IR shape change. Prior ticket #1492 / #11809 / #12036.
 */
trait M3EmitTuCompilerAndRuntimeVoidStubs
{
    /** Inventory emit spine: Compiler::compile link stub — emit path uses compileEmitSmoke (#1492). */
    private function emitM3EmitTuCompilerCompileNullStubNative(string $internalName, string $logical): PHPLLVM\Value
    {
        $lc = strtolower($logical);
        if (isset($this->context->functions[$lc])) {
            return $this->context->functions[$lc];
        }
        $objPtr = $this->context->getTypeFromString('__object__*');
        $func = $this->context->module->addFunction(
            $internalName,
            $this->context->context->functionType($objPtr, false, $objPtr, $objPtr)
        );
        $bb = $func->appendBasicBlock('entry');
        $saved = $this->context->builder;
        $this->context->builder = $this->context->context->builderCreate();
        $this->context->builder->positionAtEnd($bb);
        $this->context->builder->returnValue($objPtr->constNull());
        $this->context->builder->clearInsertionPosition();
        $this->context->builder = $saved;
        $this->context->functions[$lc] = $func;
        $this->context->functionReturnType[$lc] = '__object__*';
        $this->context->functionProxies[$lc] = new JIT\Call\Native($func, $logical, [$objPtr, $objPtr], []);

        return $func;
    }

    /** No-op array setter for Compiler spine — LLVM link only; real bodies deferred (#1492). */
    private function emitM3EmitTuCompilerArrayPropertySetterVoidStub(
        string $internalName,
        string $logicalName,
        ?Block $block
    ): PHPLLVM\Value {
        $lcname = strtolower($logicalName);
        if (isset($this->context->functions[$lcname])) {
            return $this->context->functions[$lcname];
        }
        $objectPtr = $this->context->getTypeFromString('__object__*');
        $htPtr = $this->context->getTypeFromString('__hashtable__*');
        $voidTy = $this->context->getTypeFromString('void');
        $func = $this->context->module->addFunction(
            $internalName,
            $this->context->context->functionType($voidTy, false, $objectPtr, $htPtr)
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
            [$objectPtr, $htPtr],
            null !== $block ? $this->collectParamDefaults($block) : []
        );

        return $func;
    }

    /** No-op string setter for Compiler spine — LLVM link only (#11809). */
    private function emitM3EmitTuCompilerStringSetterVoidStub(
        string $internalName,
        string $logicalName,
        ?Block $block
    ): PHPLLVM\Value {
        $lcname = strtolower($logicalName);
        if (isset($this->context->functions[$lcname])) {
            return $this->context->functions[$lcname];
        }
        $objectPtr = $this->context->getTypeFromString('__object__*');
        $strPtr = $this->context->getTypeFromString('__string__*');
        $voidTy = $this->context->getTypeFromString('void');
        $func = $this->context->module->addFunction(
            $internalName,
            $this->context->context->functionType($voidTy, false, $objectPtr, $strPtr)
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
            [$objectPtr, $strPtr],
            null !== $block ? $this->collectParamDefaults($block) : []
        );

        return $func;
    }

    /** No-op void Compiler spine method — LLVM link only (#11809). */
    private function emitM3EmitTuCompilerVoidStub(
        string $internalName,
        string $logicalName,
        ?Block $block
    ): PHPLLVM\Value {
        $lcname = strtolower($logicalName);
        if (isset($this->context->functions[$lcname])) {
            return $this->context->functions[$lcname];
        }
        $objectPtr = $this->context->getTypeFromString('__object__*');
        $voidTy = $this->context->getTypeFromString('void');
        $func = $this->context->module->addFunction(
            $internalName,
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
            null !== $block ? $this->collectParamDefaults($block) : []
        );

        return $func;
    }

    /** Null string getter for Compiler spine — LLVM link only (#11809). */
    private function emitM3EmitTuCompilerNullStringGetterStub(
        string $internalName,
        string $logicalName,
        ?Block $block
    ): PHPLLVM\Value {
        $lcname = strtolower($logicalName);
        if (isset($this->context->functions[$lcname])) {
            return $this->context->functions[$lcname];
        }
        $objectPtr = $this->context->getTypeFromString('__object__*');
        $strPtr = $this->context->getTypeFromString('__string__*');
        $func = $this->context->module->addFunction(
            $internalName,
            $this->context->context->functionType($strPtr, false, $objectPtr)
        );
        $bb = $func->appendBasicBlock('entry');
        $saved = $this->context->builder;
        $this->context->builder = $this->context->context->builderCreate();
        $this->context->builder->positionAtEnd($bb);
        $this->context->builder->returnValue($strPtr->constNull());
        $this->context->builder->clearInsertionPosition();
        $this->context->builder = $saved;
        $this->context->functions[$lcname] = $func;
        $this->context->functionReturnType[$lcname] = '__string__*';
        $this->context->functionProxies[$lcname] = new JIT\Call\Native(
            $func,
            $logicalName,
            [$objectPtr],
            null !== $block ? $this->collectParamDefaults($block) : []
        );

        return $func;
    }

    /** void(Runtime $this, ?Script $script) — inventory argv parse-null recorder (#12036). */
    private function emitM3EmitTuRuntimeTwoObjectVoidStub(
        string $internalName,
        string $logicalName,
        ?Block $block
    ): PHPLLVM\Value {
        $lcname = strtolower($logicalName);
        if (isset($this->context->functions[$lcname])) {
            return $this->context->functions[$lcname];
        }
        $objectPtr = $this->context->getTypeFromString('__object__*');
        $voidTy = $this->context->getTypeFromString('void');
        $func = $this->context->module->addFunction(
            $internalName,
            $this->context->context->functionType($voidTy, false, $objectPtr, $objectPtr)
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
            [$objectPtr, $objectPtr],
            null !== $block ? $this->collectParamDefaults($block) : []
        );

        return $func;
    }
}
