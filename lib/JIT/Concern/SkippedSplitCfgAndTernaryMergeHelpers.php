<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Operand;
use PHPCompiler\Block;
use PHPCompiler\JIT\Variable;
use PHPCompiler\OpCode;
use PHPLLVM;

/**
 * Skipped Compiler split-CFG stubs, ?: JUMPIF shared-return merge, and list-unpack
 * assign-slot recording (#36387).
 *
 * Extracted from {@see \PHPCompiler\JIT}: {@code compileSkippedCompilerSplitCfgStub}
 * through {@code recordListUnpackAssignSlot} so the hub shrinks toward split-TU
 * iterability under the size-budget ratchet.
 *
 * php-src: Zend/zend_compile.c CFG / jump targets, Zend/zend_execute.c ternary
 * and list() destinations — move-only Concern extract; no new C ABI and no
 * opcode/IR shape change.
 */
trait SkippedSplitCfgAndTernaryMergeHelpers
{
    /** Stub Compiler CFG helpers that crash LLVM 9 during self-host AOT (#816). */
    private function compileSkippedCompilerSplitCfgStub(string $internalName, Block $block, string $logicalName): PHPLLVM\Value
    {
        $lcname = strtolower($logicalName);
        if (isset($this->context->functions[$lcname])) {
            return $this->context->functions[$lcname];
        }
        if ($this->shouldUseM3EmitTuNativeBridge() && $this->isBootstrapM3RuntimeEmitBridgeName($lcname)) {
            return $this->compileBootstrapCompileSmokeM3EmitNative($internalName, $block, $logicalName);
        }
        if ($this->isM3CompileDriverCompilerNativeLoweringName($lcname)) {
            return JIT\CompilerOperandChainNative::compile(
                $this->context,
                $this->llvmInternalName($internalName),
                $block,
                $logicalName
            );
        }
        if ($this->shouldUseM3CompileDriverRealLowering() && JIT\VariableTypeMapNative::isNativeLoweringName($lcname)) {
            return JIT\VariableTypeMapNative::compile(
                $this->context,
                $this->llvmInternalName($internalName),
                $block,
                $logicalName
            );
        }
        if (JIT\OperandNameNative::isNativeLoweringName($lcname)) {
            return JIT\OperandNameNative::compile(
                $this->context,
                $this->llvmInternalName($internalName),
                $block,
                $logicalName
            );
        }
        if ($this->isM3CompileDriverCompilerPhpLoweringName($lcname)) {
            return $this->compileRuntimeSpinePhpLowering($internalName, $block, $logicalName);
        }
        $args = $this->normalizeSelfHostNativeCallArgTypes(
            $this->collectStubFunctionArgTypes($block),
            $logicalName
        );
        $callbackType = $this->cfgFunctionReturnCallbackType($block->func) ?? '__object__*';
        $returnType = $this->context->getTypeFromString($callbackType);
        $func = $this->context->module->addFunction(
            $this->llvmInternalName($internalName),
            $this->context->context->functionType($returnType, false, ...$args)
        );
        $bb = $func->appendBasicBlock('stub');
        $saved = $this->context->builder;
        $savedActive = $this->context->activeFunction;
        $this->context->builder = $this->context->context->builderCreate();
        $this->context->builder->positionAtEnd($bb);
        // Register before defaults: string defaults need entryAlloca/parentFunction (#21972).
        // Collect while insert is live — after clearInsertionPosition main may be null.
        $this->context->functions[$lcname] = $func;
        $this->context->activeFunction = $lcname;
        $defaultArgs = $this->collectParamDefaults($block);
        $this->emitSelfHostStubReturn($callbackType, $func);
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
        if (null !== $logicalName) {
            JIT\NoDiscardCallGuard::registerCallee($this->context, $logicalName, $block);
            JIT\DeprecatedCallGuard::registerCallee($this->context, $logicalName, $block);
        }

        return $func;
    }

    /** Both ?: arms jump to a shared RETURN/THROW merge block (#4280, #8555). */
    private function findJumpIfSharedReturnOperand(?Block $ifBranch, ?Block $elseBranch): ?\PHPCfg\Operand
    {
        if (null === $ifBranch || null === $elseBranch) {
            return null;
        }
        $ifMerge = $this->jumpBlockTarget($ifBranch);
        $elseMerge = $this->jumpBlockTarget($elseBranch);
        if (null === $ifMerge || $ifMerge !== $elseMerge) {
            return null;
        }

        foreach ($ifMerge->opCodes as $mergeOp) {
            if (OpCode::TYPE_RETURN === $mergeOp->type) {
                return $ifMerge->getOperand($mergeOp->arg1);
            }
            if (OpCode::TYPE_THROW === $mergeOp->type) {
                return $ifMerge->getOperand($mergeOp->arg1);
            }
        }

        return null;
    }

    private function jumpBlockTarget(Block $branch): ?Block
    {
        $limit = min($branch->nOpCodes, \count($branch->opCodes));
        for ($i = $limit - 1; $i >= 0; --$i) {
            $branchOp = $branch->opCodes[$i] ?? null;
            if (null === $branchOp) {
                continue;
            }
            if (OpCode::TYPE_JUMP === $branchOp->type) {
                return $branchOp->block1;
            }
        }

        return null;
    }

    /** Last assign before a ?: branch JUMP targets the shared RETURN merge (#8555). */
    private function isTernaryBranchMergeAssign(Block $branch, OpCode $assignOp): bool
    {
        if (null === $this->context->ternarySharedReturnSlot) {
            return false;
        }
        $merge = $this->jumpBlockTarget($branch);
        if (null === $merge) {
            return false;
        }
        // Do not emitJitReturnFromValue when merge still has PLUS/etc. (#33719).
        if (!$this->mergeBlockIsPureTernaryReturn($merge)) {
            return false;
        }
        $limit = min($branch->nOpCodes, \count($branch->opCodes));
        if ($limit < 2 || OpCode::TYPE_JUMP !== $branch->opCodes[$limit - 1]->type) {
            return false;
        }
        $prior = $branch->opCodes[$limit - 2];

        return OpCode::TYPE_ASSIGN === $prior->type && $prior === $assignOp;
    }

    private function recordListUnpackAssignSlot(Operand $resultOp, Variable $slot): void
    {
        if (null === $this->context->listUnpackAssignRootBlock) {
            return;
        }
        $name = JIT\OperandName::resolve($resultOp);
        if (null === $name || '' === $name) {
            return;
        }
        if (
            Variable::KIND_VARIABLE !== $slot->kind
            || (Variable::TYPE_VALUE !== $slot->type && Variable::TYPE_STRING !== $slot->type)
        ) {
            $lvalue = $this->resolveAssignLvalue($resultOp);
            if (
                Variable::KIND_VARIABLE !== $lvalue->kind
                || (Variable::TYPE_VALUE !== $lvalue->type && Variable::TYPE_STRING !== $lvalue->type)
            ) {
                return;
            }
            $slot = $lvalue;
        }
        $this->context->listUnpackAssignSlots[
            $this->context->resolveRefAliasName($name)
        ] = $slot;
    }
}
