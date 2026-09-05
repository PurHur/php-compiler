<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\JIT\Variable;
use PHPLLVM;

/**
 * NULLSAFE (?->) opcode lowering for JIT/AOT (#36387).
 *
 * Extracted from {@see CompileBlockInternal}: {@code TYPE_NULLSAFE}.
 * Returns null when the caller should {@code break} (inline-include continue);
 * otherwise returns the basic block {@see compileBlockInternal} should return.
 *
 * php-src: Zend/zend_vm_def.h (ZEND_JMP_NULL / ZEND_NULLSAFE_PROP / ZEND_NULLSAFE_METHODCALL),
 * Zend/zend_execute.c — move-only Concern extract; no new C ABI.
 */
trait CompileNullsafe
{
    /**
     * @param Variable ...$args
     */
    private function compileNullsafeOp(
        Block $opBlock,
        OpCode $op,
        PHPLLVM\Value $func,
        PHPLLVM\Builder $builder,
        PHPLLVM\BasicBlock $origBasicBlock,
        Variable ...$args
    ): ?PHPLLVM\BasicBlock {
        $block = $opBlock;
        $branchBlock = \PHPCompiler\JIT\BasicBlockHelper::tryGetInsertBlock($this->context);
        if (null === $branchBlock) {
            \PHPCompiler\JIT\BasicBlockHelper::ensureOpenInsertBlock($this->context, 'nullsafe_branch');
            $branchBlock = \PHPCompiler\JIT\BasicBlockHelper::tryGetInsertBlock($this->context) ?? $origBasicBlock;
        }
        $builder->positionAtEnd($branchBlock);
        $nullsafeResult = $block->getOperand($op->arg1);
        $this->context->coalesceAssignTargets[$nullsafeResult] = true;
        // Pre-allocate one stack slot before branches so null/fetch/merge all write
        // the same alloca — otherwise AOT coalesce/assign reads an untouched temp (#26818).
        $this->ensureCoalesceMergeStackSlot($nullsafeResult);
        $nullsafeMergeSlot = $block->slotForOperand($nullsafeResult);
        if (null !== $nullsafeMergeSlot) {
            $this->context->coalesceMergeSlotOperands[$nullsafeMergeSlot] = $nullsafeResult;
        }
        $receiver = $this->context->getVariableFromOp($block->getOperand($op->arg2));
        // Compile-time null: only lower the null arm. Compiling the fetch arm for
        // `$o?->m()` still runs METHODCALL_INIT and fatals with
        // "Call to undefined method object::m()" under AOT (#34713 /
        // ZEND_NULLSAFE_METHODCALL).
        $knownNullReceiver = Variable::TYPE_NULL === $receiver->type
            || $receiver->isNullConstant;
        $isNull = $knownNullReceiver
            ? $this->context->getTypeFromString('int1')->constInt(1, false)
            : \PHPCompiler\JIT\NullsafeHelper::isReceiverNull(
                $this,
                $receiver,
                $op->nullsafeMethodCall
            );
        // Mirror ?? lowering: branchIf targets entry blocks; merge from branch tails (#3219).
        $nullTail = \PHPCompiler\JIT\NullsafeHelper::compileBranch($this, $func, $op->block1);
        $fetchTail = null;
        if (!$knownNullReceiver) {
            $fetchTail = \PHPCompiler\JIT\NullsafeHelper::compileBranch($this, $func, $op->block2);
        }
        if ($this->context->hasVariableOp($nullsafeResult)) {
            $nullsafeVar = $this->context->getVariableFromOp($nullsafeResult);
            $nullsafeVar->compileTimeString = null;
            $nullsafeVar->compileTimeConstantName = null;
            $nullsafeVar->compileTimeEnumCase = null;
            // Fetch-arm propertySlotPtr (void**) does not dominate the merge block —
            // later loads (var_dump / ARG_SEND) must use the coalesce __value__ slot (#32988).
            $nullsafeVar->objectPropertySlot = null;
            $nullsafeVar->objectPropertyType = null;
            $nullsafeVar->objectPropertyReceiver = null;
            $nullsafeVar->objectPropertyName = null;
            $nullsafeVar->objectPropertyClassName = null;
            $nullsafeVar->objectPropertyDnfArms = null;
        }
        $nullEntry = $this->jitBranchEntryBlock($op->block1, $func);
        $builder->positionAtEnd($branchBlock);
        // Do not free php-cfg "dead" operands here; ?-> temps are used on branch/merge blocks (#3219).
        if ($knownNullReceiver) {
            $builder->branch($nullEntry);
        } else {
            $fetchEntry = $this->jitBranchEntryBlock($op->block2, $func);
            $builder->branchIf($isNull, $nullEntry, $fetchEntry);
        }
        if (null !== $op->block3) {
            // Fetch arm may have rebound the result to a property-backed Variable;
            // reseat on a plain merge alloca before merge-block uses (#32988).
            $this->ensureCoalesceMergeStackSlot($nullsafeResult);
            if ($this->context->hasVariableOp($nullsafeResult)) {
                $mergeSeat = $this->context->getVariableFromOp($nullsafeResult);
                $mergeSeat->objectPropertySlot = null;
                $mergeSeat->objectPropertyType = null;
                $mergeSeat->objectPropertyReceiver = null;
                $mergeSeat->objectPropertyName = null;
                $mergeSeat->objectPropertyClassName = null;
                $mergeSeat->objectPropertyDnfArms = null;
                $mergeSeat->compileTimeString = null;
                $mergeSeat->compileTimeConstantName = null;
                $mergeSeat->compileTimeEnumCase = null;
            }
            $mergeBb = \PHPCompiler\JIT\BasicBlockHelper::append($this->context, 'nullsafe_merge');
            $builder->positionAtEnd($nullTail);
            if (null === $nullTail->getTerminator()) {
                $builder->branch($mergeBb);
            }
            if (null !== $fetchTail) {
                $builder->positionAtEnd($fetchTail);
                if (null === $fetchTail->getTerminator()) {
                    $builder->branch($mergeBb);
                }
            }
            $builder->positionAtEnd($mergeBb);
            // Mirror ?? : refresh inherited locals; skip in-flight assign targets (#20507).
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
            unset($this->context->coalesceAssignTargets[$nullsafeResult]);
            if ($this->context->inlineIncludeDepth > 0) {
                // Mirror ?? lowering: stay in the including TU (#866, #784, #15149).
                return null;
            }

            return $merged;
        }
        unset($this->context->coalesceAssignTargets[$nullsafeResult]);
        if ($this->context->inlineIncludeDepth > 0) {
            return null;
        }

        return $origBasicBlock;
    }
}
