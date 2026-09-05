<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\JIT\Variable;
use PHPLLVM;

/**
 * EXIT / switch CASE / JUMP opcode lowering for JIT/AOT (#36387).
 *
 * Extracted from {@see CompileBlockInternal}: {@code TYPE_EXIT}, {@code TYPE_CASE},
 * and {@code TYPE_JUMP}. {@see compileJumpOp} returns the original entry basic block
 * so {@see compileBlockInternal} early-returns after the branch is sealed (same as
 * the prior inlined case). Move-only; no IR shape change.
 *
 * php-src: Zend/zend_vm_def.h (ZEND_EXIT / ZEND_CASE / ZEND_JMP),
 * Zend/zend_execute.c — move-only Concern extract; no new C ABI.
 */
trait CompileExitCaseAndJump
{
    private function compileExitOp(Block $block, OpCode $op): void
    {
        if (null === $op->arg2) {
            if (\PHPCompiler\JIT\Builtin::LOAD_TYPE_STANDALONE === $this->context->loadType) {
                \PHPCompiler\JIT\Builtin\PendingHeaders::emitFlushForStandalone($this->context);
            }
            $i32 = $this->context->getTypeFromString('int32');
            $this->context->builder->call(
                $this->context->lookupFunction('exit'),
                $i32->constInt(0, false)
            );

            return;
        }
        $exitArg = $this->context->getVariableFromOp($block->getOperand($op->arg2));
        $prevExitStrict = $this->context->callerStrictTypes;
        $this->context->callerStrictTypes = $block->strictTypes;
        try {
            if (null !== $op->exitMessageSlot) {
                $messageArg = $this->context->getVariableFromOp($block->getOperand($op->exitMessageSlot));
                \PHPCompiler\JIT\Builtin\ScriptExit::emitWithMessage($this->context, $exitArg, $messageArg);

                return;
            }
            \PHPCompiler\JIT\Builtin\ScriptExit::emit($this->context, $exitArg);
        } finally {
            $this->context->callerStrictTypes = $prevExitStrict;
        }
    }

    /**
     * @param Variable ...$args
     */
    private function compileCaseOp(
        Block $block,
        OpCode $op,
        PHPLLVM\Value $func,
        PHPLLVM\Builder $builder,
        Variable ...$args
    ): void {
        $branchBlock = $builder->getInsertBlock();
        $builder->positionAtEnd($branchBlock);
        $this->maybeRefreshIncludeBindingsBeforeUse();
        $switchVar = $this->context->getVariableFromOp($this->operandAt($block, $op->arg1, 'switch value'));
        $caseVar = $this->context->getVariableFromOp($this->operandAt($block, $op->arg2, 'switch case'));
        $equalOp = new OpCode(OpCode::TYPE_EQUAL);
        $matchVar = $this->context->helper->binaryOp($equalOp, $switchVar, $caseVar);
        $match = $this->context->castToBool(
            $this->context->helper->loadValue($matchVar)
        );
        $this->compileBlockInternal($func, $op->block1, null, null, 0, false, ...$args);
        $caseEntry = $this->jitBranchEntryBlock($op->block1, $func);
        $nextBb = \PHPCompiler\JIT\BasicBlockHelper::append($this->context, 'switch_next_case');
        $builder->positionAtEnd($branchBlock);
        if ($this->shouldFreeDeadVariablesBeforeBranch()) {
            $this->context->freeDeadVariables($func, $branchBlock, $block);
        }
        $builder->branchIf($match, $caseEntry, $nextBb);
        $builder->positionAtEnd($nextBb);
    }

    /**
     * @param Variable ...$args
     */
    private function compileJumpOp(
        Block $block,
        OpCode $op,
        PHPLLVM\Value $func,
        PHPLLVM\Builder $builder,
        PHPLLVM\BasicBlock $origBasicBlock,
        Variable ...$args
    ): PHPLLVM\BasicBlock {
        \PHPCompiler\JIT\BasicBlockHelper::ensureOpenInsertBlock($this->context, 'jump_cont');
        $branchBlock = $builder->getInsertBlock();
        $builder->positionAtEnd($branchBlock);
        $skippedListUnpackAssign = $this->context->listUnpackSkipAssignPath;
        $this->context->listUnpackSkipAssignPath = false;
        $mergeLlvm = null;
        $allowRecompile = false;
        if ($this->context->listUnpackMergeLlvmBlocks->contains($op->block1)) {
            $mergeLlvm = $this->context->listUnpackMergeLlvmBlocks[$op->block1];
            $allowRecompile = true;
            $this->context->listUnpackMergeLlvmBlocks->detach($op->block1);
            if ($skippedListUnpackAssign) {
                $mergeKey = spl_object_id($op->block1);
                $this->context->listUnpackMergeNullInitTargets[$mergeKey]
                    = $this->listUnpackAssignTargetsInBlock($block);
            }
        }
        $this->context->listUnpackAssignCallerBlock = $block;
        $this->compileBlockInternal($func, $op->block1, null, $mergeLlvm, 0, $allowRecompile, ...$args);
        $this->context->listUnpackAssignCallerBlock = null;
        $targetEntry = \PHPCompiler\JIT\TryCatchHelper::leaveBranchTarget(
            $this,
            $this->context,
            $func,
            $block,
            $op->block1,
            $args
        );
        if ($this->context->inlineIncludeDepth > 0) {
            // Use the merge block itself (not getInsertBlock — callee may be cached) (#846, #784).
            $this->context->inlineIncludeExitBlock = $targetEntry;
        }
        $builder->positionAtEnd($branchBlock);
        if (
            $this->shouldFreeDeadVariablesBeforeBranch()
            && !$this->mergeBlockInheritsCallerLocals($op->block1)
        ) {
            $this->context->freeDeadVariables($func, $branchBlock, $block);
        }
        $builder->branch($targetEntry);

        return $origBasicBlock;
    }
}
