<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\JIT\Variable;
use PHPLLVM;

/**
 * COALESCE (??) opcode lowering for JIT/AOT (#36387).
 *
 * Extracted from {@see CompileBlockInternal}: {@code TYPE_COALESCE}.
 * Returns null when the caller should {@code break} (inline-include continue);
 * otherwise returns the basic block {@see compileBlockInternal} should return.
 *
 * php-src: Zend/zend_vm_def.h (ZEND_COALESCE / ZEND_ASSIGN_COALESCE),
 * Zend/zend_execute.c — move-only Concern extract; no new C ABI.
 */
trait CompileCoalesce
{
    /**
     * @param Variable ...$args
     */
    private function compileCoalesceOp(
        Block $opBlock,
        OpCode $op,
        PHPLLVM\Value $func,
        PHPLLVM\Builder $builder,
        PHPLLVM\BasicBlock $origBasicBlock,
        Variable ...$args
    ): ?PHPLLVM\BasicBlock {
        $block = $opBlock;
        // Match TYPE_NULLSAFE: NestedJIT CoalesceJitHelper / script-global
        // init can clear insert; a parentless load of phpc_script_global_*
        // fails module verify for `echo $undef ?? 'd'` (#32445).
        $branchBlock = \PHPCompiler\JIT\BasicBlockHelper::tryGetInsertBlock($this->context);
        if (null === $branchBlock) {
            \PHPCompiler\JIT\BasicBlockHelper::ensureOpenInsertBlock($this->context, 'coalesce_branch');
            $branchBlock = \PHPCompiler\JIT\BasicBlockHelper::tryGetInsertBlock($this->context) ?? $origBasicBlock;
        }
        $builder->positionAtEnd($branchBlock);
        $coalesceResult = $block->getOperand($op->arg1);
        $this->context->coalesceAssignTargets[$coalesceResult] = true;
        // Pre-allocate one stack slot before branches so left/right/merge all write
        // the same alloca — otherwise AOT ?? + === / == on the merge reads a bad
        // temp and MiniWebApp AOT exits with empty stdout (#31101 / #26818).
        $this->ensureCoalesceMergeStackSlot($coalesceResult);
        \PHPCompiler\JIT\BasicBlockHelper::ensureOpenInsertBlock($this->context, 'coalesce_after_slot');
        $mergeSlot = $block->slotForOperand($coalesceResult);
        if (null !== $mergeSlot) {
            $this->context->coalesceMergeSlotOperands[$mergeSlot] = $coalesceResult;
        }
        $condition = \PHPCompiler\JIT\CoalesceHelper::isTakeLeftBranch(
            $this,
            $this->context->getVariableFromOp($block->getOperand($op->arg2))
        );
        // Branch from the block that defined $condition (e.g. sg_sk_done after $_SERVER['key']).
        // Repositioning to $branchBlock caused invalid LLVM when ?? left uses multi-block reads (#866).
        $coalesceTestBlock = $builder->getInsertBlock();
        // Seal the test BB before lowering arms (#32880). Compiling
        // PROPERTY_FETCH_WRITE first left prop_value_done open after `new`;
        // NestedJIT ensureOpenInsertBlock resumed there and planted a second br.
        if (!$func instanceof PHPLLVM\Value\Function_) {
            throw new \LogicException('TYPE_COALESCE expects an LLVM function');
        }
        self::$blockNumber++;
        $leftEntry = $func->appendBasicBlock('coalesce_left_' . self::$blockNumber);
        self::$blockNumber++;
        $rightEntry = $func->appendBasicBlock('coalesce_right_' . self::$blockNumber);
        $builder->positionAtEnd($coalesceTestBlock);
        if (null !== $coalesceTestBlock->getTerminator()) {
            \PHPCompiler\JIT\BasicBlockHelper::ensureOpenInsertBlock($this->context, 'coalesce_test_resume');
            $coalesceTestBlock = $builder->getInsertBlock() ?? $coalesceTestBlock;
            $builder->positionAtEnd($coalesceTestBlock);
        }
        // Do not free php-cfg "dead" operands here; ?? temps are used on branch/merge blocks (#99).
        $builder->branchIf($condition, $leftEntry, $rightEntry);
        $leftTail = \PHPCompiler\JIT\CoalesceHelper::compileBranch($this, $func, $op->block1, $leftEntry);
        $rightTail = \PHPCompiler\JIT\CoalesceHelper::compileBranch($this, $func, $op->block2, $rightEntry);
        // Both branches compile; right-side literal metadata must not fold builtins (#764).
        if ($this->context->hasVariableOp($coalesceResult)) {
            $coalesceVar = $this->context->getVariableFromOp($coalesceResult);
            $coalesceVar->compileTimeString = null;
            $coalesceVar->compileTimeConstantName = null;
            $coalesceVar->compileTimeEnumCase = null;
        }
        // ??= arms persist the object store then copy fetch-arm objectPropertySlot
        // onto the merge temp (#33748). That GEP does not dominate coalesce_merge
        // or a nested outer ?? — module verify "Instruction does not dominate
        // all uses" for `$a->p ??= $b->q ??= 9` (#33760, peer TYPE_NULLSAFE #32988).
        $this->reseatCoalesceResultAfterPropertyArms($coalesceResult);
        if (null !== $op->block3) {
            $mergeBb = \PHPCompiler\JIT\BasicBlockHelper::append($this->context, 'coalesce_merge');
            $builder->positionAtEnd($leftTail);
            if (null === $leftTail->getTerminator()) {
                $builder->branch($mergeBb);
            }
            $builder->positionAtEnd($rightTail);
            if (null === $rightTail->getTerminator()) {
                $builder->branch($mergeBb);
            }
            $builder->positionAtEnd($mergeBb);
            // Refresh inherited locals after ?? (#866). IncludeBindingEmitHelper skips
            // in-flight coalesceAssignTargets (e.g. $scriptBase) so MiniWebApp AOT
            // does not munmap (#20507).
            if ($this->context->inlineIncludeDepth > 0) {
                \PHPCompiler\JIT\IncludeHelper::refreshInlineIncludeBindings($this->context);
            }
            $mergeLimit = \PHPCompiler\JIT\CoalesceHelper::mergeBlockOpcodeLimit($op->block3);
            $savedSynthetic = $op->block3->syntheticCfgBranch ?? false;
            if (null !== $mergeLimit && $mergeLimit < $op->block3->nOpCodes) {
                $op->block3->syntheticCfgBranch = true;
            }
            try {
                $merged = $this->compileBlockInternal($func, $op->block3, $mergeLimit, $mergeBb, 0, false, ...$args);
            } finally {
                $op->block3->syntheticCfgBranch = $savedSynthetic;
            }
            unset($this->context->coalesceAssignTargets[$coalesceResult]);
            $this->releaseCoalesceMergeSlotMapping($block, $coalesceResult);
            $this->clearCoalesceFetchArmPropertySlotsInScope();
            if ($this->context->inlineIncludeDepth > 0) {
                // Do not set inlineIncludeExitBlock to the ?? merge block (#866, #784).
                return null;
            }

            return $merged;
        }
        unset($this->context->coalesceAssignTargets[$coalesceResult]);
        $this->releaseCoalesceMergeSlotMapping($block, $coalesceResult);
        $this->clearCoalesceFetchArmPropertySlotsInScope();
        if ($this->context->inlineIncludeDepth > 0) {
            // Two-branch ?? without merge: continue in the including TU (#866).
            return null;
        }

        return $origBasicBlock;
    }
}
