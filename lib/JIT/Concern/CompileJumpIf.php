<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\JIT\Variable;
use PHPLLVM;

/**
 * JUMPIF opcode lowering for JIT/AOT (#36387).
 *
 * Extracted from {@see CompileBlockInternal}: {@code TYPE_JUMPIF}.
 * Returns the original entry basic block (same as the inlined case) so
 * {@see compileBlockInternal} early-returns after branch arms are sealed.
 *
 * php-src: Zend/zend_vm_def.h (ZEND_JMPZ / ZEND_JMPNZ / ZEND_JMPZNZ),
 * Zend/zend_execute.c — move-only Concern extract; no new C ABI.
 */
trait CompileJumpIf
{
    /**
     * @param Variable ...$args
     */
    private function compileJumpIfOp(
        Block $block,
        OpCode $op,
        int $i,
        PHPLLVM\Value $func,
        PHPLLVM\BasicBlock $origBasicBlock,
        Variable ...$args
    ): PHPLLVM\BasicBlock {
        $builder = $this->context->builder;
        \PHPCompiler\JIT\BasicBlockHelper::ensureOpenInsertBlock($this->context, 'jumpif_cont');
        $branchBlock = \PHPCompiler\JIT\BasicBlockHelper::tryGetInsertBlock($this->context) ?? $origBasicBlock;
        $builder->positionAtEnd($branchBlock);
        $this->maybeRefreshIncludeBindingsBeforeUse();
        $ternaryMergeReturn = null;
        $ternaryMergeEcho = null;
        $ternaryMergeEchoSlot = null;
        $savedTernarySharedReturn = $this->context->ternarySharedReturnOperand;
        $savedTernarySharedReturnSlot = $this->context->ternarySharedReturnSlot;
        $isTernaryReturnMerge = 0 === $this->context->inlineIncludeDepth
            && $this->jumpIfTargetsReturnMerge($op->block1, $op->block2);
        $isTernaryEchoMerge = 0 === $this->context->inlineIncludeDepth
            && !$isTernaryReturnMerge
            && $this->jumpIfTargetsEchoMerge($op->block1, $op->block2, $block, $i);
        $isMatchEchoMerge = 0 === $this->context->inlineIncludeDepth
            && !$isTernaryReturnMerge
            && !$isTernaryEchoMerge
            && $this->jumpIfTargetsMatchEchoMerge($op->block1, $op->block2);
        // When merge CONCAT needs stack-phi, suppress #18784 literal-echo redirect
        // so ECHO prints the concat result (not the bare ?: arm) (#35095).
        $needsLiteralRedirect = false;
        if ($isTernaryReturnMerge) {
            $mergeBlock = $this->branchJumpMergeBlock($op->block1);
            assert(null !== $mergeBlock);
            $ternaryMergeReturn = $this->ternaryReturnPhiOperand($mergeBlock);
            if (null !== $ternaryMergeReturn) {
                $this->context->coalesceAssignTargets[$ternaryMergeReturn] = true;
                $this->context->ternarySharedReturnOperand = $ternaryMergeReturn;
                foreach ($mergeBlock->opCodes as $mergeOp) {
                    if (OpCode::TYPE_RETURN === $mergeOp->type && null !== $mergeOp->arg1) {
                        $this->context->ternarySharedReturnSlot = (int) $mergeOp->arg1;
                        break;
                    }
                }
            }
        } elseif ($isTernaryEchoMerge) {
            $mergeBlock = $this->branchJumpMergeBlock($op->block1);
            assert(null !== $mergeBlock);
            $ternaryMergeEcho = $this->ternaryEchoPhiOperand($mergeBlock, $op->block1, $op->block2);
            if (null !== $ternaryMergeEcho) {
                $this->context->coalesceAssignTargets[$ternaryMergeEcho] = true;
                $needsStackPhi = $this->ternaryEchoMergeNeedsStackPhi($mergeBlock, $op->block1, $op->block2);
                // Literal-arm condition redirect must not allocate a __value__ phi
                // slot — that pollutes merge ECHO for runtime-bridge i1 conditions (#19459, #18784).
                $needsLiteralRedirect = $this->ternaryEchoMergeNeedsLiteralArmRedirect(
                    $block,
                    $i,
                    $mergeBlock,
                    $op->block1,
                    $op->block2
                );
                // Map the slot the merge *consumes* (ECHO arg or CONCAT operand), not
                // only a trailing ECHO of a concat result (#32908).
                $resultSlot = $this->mergeTernaryResultSlot($mergeBlock, $op->block1, $op->block2);
                // Merge CONCAT must run against a real stack phi — literal-echo
                // redirect only rewrites ECHO and leaves CONCAT on an uninit SSA
                // temp (LHS drop / SIGSEGV; #35095 / re-#33094).
                if (
                    $needsStackPhi
                    && null !== $resultSlot
                    && $this->mergeConcatReadsTernaryResult($mergeBlock, $resultSlot)
                ) {
                    $needsLiteralRedirect = false;
                }
                if ($needsStackPhi || !$needsLiteralRedirect) {
                    $this->ensureCoalesceMergeStackSlot($ternaryMergeEcho);
                }
                if (null !== $resultSlot && $needsStackPhi) {
                    $this->context->coalesceMergeSlotOperands[$resultSlot] = $ternaryMergeEcho;
                    $ternaryMergeEchoSlot = $resultSlot;
                }
            }
        } elseif ($isMatchEchoMerge) {
            // Always stack-phi the shared echo alias — per-arm ASSIGN results differ (#24143).
            $mergeBlock = $this->matchEchoMergeBlock($op->block1, $op->block2);
            assert(null !== $mergeBlock);
            $ternaryMergeEcho = $this->mergeEchoOperand($mergeBlock);
            if (null !== $ternaryMergeEcho) {
                $this->context->coalesceAssignTargets[$ternaryMergeEcho] = true;
                $this->ensureCoalesceMergeStackSlot($ternaryMergeEcho);
                $echoVar = $this->context->getVariableFromOp($ternaryMergeEcho);
                // Pin so later arms keep writing this alloca even if the Temporary is
                // re-promoted while a trailing stmt shares the merge block (#24143).
                if (
                    Variable::TYPE_VALUE === $echoVar->type
                    && Variable::KIND_VARIABLE === $echoVar->kind
                    && null === $echoVar->valueBoxAliasPtr
                ) {
                    $echoVar->valueBoxAliasPtr = \PHPCompiler\JIT\JitValueBox::valuePtrFromVariable(
                        $this->context,
                        $echoVar
                    );
                }
                $echoSlot = $this->mergeEchoSlot($mergeBlock);
                if (null !== $echoSlot) {
                    $this->context->coalesceMergeSlotOperands[$echoSlot] = $ternaryMergeEcho;
                    $ternaryMergeEchoSlot = $echoSlot;
                }
            }
        }
        $condVar = $this->context->getVariableFromOp(
            $this->operandAt($block, $op->arg1, 'branch condition')
        );
        // {main} script-global strings are __value__ boxes; boxedTruthyScalar
        // historically lacked IS_STRING and treated them as falsy (#32919).
        // Prefer compile-time / native-string truthiness when available.
        $condition = $this->jitJumpIfConditionToBool($condVar);
        if ($isTernaryEchoMerge && $needsLiteralRedirect) {
            $mergeBlock = $this->branchJumpMergeBlock($op->block1);
            assert(null !== $mergeBlock);
            $ifLiteral = $this->ternaryEchoBranchLiteralString($op->block1, $mergeBlock);
            $elseLiteral = $this->ternaryEchoBranchLiteralString($op->block2, $mergeBlock);
            if (null !== $ifLiteral && null !== $elseLiteral) {
                // CONCAT(ternary, "\n") / ("x" . ternary): keep the literal
                // side when #18784 replaces ECHO of the concat result (#33094).
                [$prefix, $suffix] = $this->ternaryEchoConcatLiteralAffixes(
                    $mergeBlock,
                    $op->block1,
                    $op->block2
                );
                $condSlot = \PHPCompiler\JIT\BasicBlockHelper::entryAlloca(
                    $this->context,
                    $this->context->getTypeFromString('int1')
                );
                $this->context->builder->store($condition, $condSlot);
                $this->context->ternaryEchoLiteralConditionSlot = $condSlot;
                $this->context->ternaryEchoLiteralIf = $prefix.$ifLiteral.$suffix;
                $this->context->ternaryEchoLiteralElse = $prefix.$elseLiteral.$suffix;
            }
        }
        // If-branch JUMP may compile a shared merge RETURN_VOID before the else/elseif arm
        // runs; do not let inlineIncludeExitBlock leak across arms (#784, #846, #764).
        $savedIncludeExit = null;
        $exitAfterIfBranch = null;
        if ($this->context->inlineIncludeDepth > 0) {
            $savedIncludeExit = $this->context->inlineIncludeExitBlock;
            $this->context->inlineIncludeExitBlock = null;
        }
        if ($isTernaryReturnMerge) {
            $mergeBlock = $this->branchJumpMergeBlock($op->block1);
            assert(null !== $mergeBlock);
            $ifString = $this->branchAssignsStringToTernaryPhi($op->block1, $mergeBlock)
                || (
                    !$this->branchAssignsOnlyNullToTernaryPhi($op->block1, $mergeBlock)
                    && $this->branchAssignsOnlyNullToTernaryPhi($op->block2, $mergeBlock)
                );
            $elseString = $this->branchAssignsStringToTernaryPhi($op->block2, $mergeBlock);
            if ($ifString && !$elseString) {
                $returnOp = $this->ternaryReturnPhiOperand($mergeBlock);
                assert(null !== $returnOp);
                [$firstArm, $secondArm] = $this->ternaryReturnMergeCompileOrder(
                    $op->block1,
                    $op->block2,
                    $mergeBlock
                );
                $ifSource = $this->ternaryPhiAssignSourceOperand($op->block1, $mergeBlock);
                $ifDirectString = null !== $ifSource
                    && Variable::TYPE_STRING === Variable::getTypeFromType($ifSource->type);
                if ($ifDirectString) {
                    $stringArm = $op->block1;
                    $firstTail = $this->compileSubBlock($func, $firstArm, ...$args);
                    if ($firstArm === $stringArm) {
                        $this->emitCfgReturnOperand(
                            $func,
                            $firstArm,
                            $returnOp,
                            $firstTail,
                            $this->ternaryArmAssignSourceVariable($firstArm, $mergeBlock)
                        );
                    } else {
                        $this->emitCfgReturnOperand($func, $firstArm, $returnOp, $firstTail);
                    }
                    $secondTail = $this->compileSubBlock($func, $secondArm, ...$args);
                    if ($secondArm === $stringArm) {
                        $this->emitCfgReturnOperand(
                            $func,
                            $secondArm,
                            $returnOp,
                            $secondTail,
                            $this->ternaryArmAssignSourceVariable($secondArm, $mergeBlock)
                        );
                    } else {
                        $this->emitCfgReturnOperand($func, $secondArm, $returnOp, $secondTail);
                    }
                } else {
                    $firstTail = $this->compileSubBlock($func, $op->block1, ...$args);
                    $this->emitCfgReturnOperand($func, $op->block1, $returnOp, $firstTail);
                    $secondTail = $this->compileSubBlock($func, $op->block2, ...$args);
                    $this->emitCfgReturnOperand($func, $op->block2, $returnOp, $secondTail);
                }
            } else {
                [$firstArm, $secondArm] = $this->ternaryReturnMergeCompileOrder(
                    $op->block1,
                    $op->block2,
                    $mergeBlock
                );
                $this->compileBlockInternal($func, $firstArm, null, null, 0, false, ...$args);
                $this->compileBlockInternal($func, $secondArm, null, null, 0, false, ...$args);
            }
            $ifEntry = \PHPCompiler\JIT\TryCatchHelper::leaveBranchTarget(
                $this,
                $this->context,
                $func,
                $block,
                $op->block1,
                $args
            );
            $elseEntry = \PHPCompiler\JIT\TryCatchHelper::leaveBranchTarget(
                $this,
                $this->context,
                $func,
                $block,
                $op->block2,
                $args
            );
            $builder->positionAtEnd($branchBlock);
            if ($this->shouldFreeDeadVariablesBeforeBranch()) {
                $this->context->freeDeadVariables($func, $branchBlock, $block);
                $this->jitReleaseJumpIfAnonValueBoxes($block, $op);
                $this->jitReleasePendingWeakReferenceGetResult();
            }
            $builder->branchIf($condition, $ifEntry, $elseEntry);
            if (null !== $ternaryMergeReturn) {
                unset($this->context->coalesceAssignTargets[$ternaryMergeReturn]);
            }
            $this->clearTernaryEchoLiteralMergeState();
            $this->context->ternarySharedReturnOperand = $savedTernarySharedReturn;
            $this->context->ternarySharedReturnSlot = $savedTernarySharedReturnSlot;

            return $origBasicBlock;
        }
        // Seal JUMPIF on the BB that defined $condition (#32912 / peer #32880).
        // Property-fetch conditions leave insert on prop_value_done — using the
        // pre-condition $branchBlock orphans the real branchIf. Seal with self-br
        // stubs so lastOpenBasicBlock cannot resume into an open test BB while
        // arms lower; retarget via LLVMSetSuccessor after arms compile.
        if (!$func instanceof PHPLLVM\Value\Function_) {
            throw new \LogicException('TYPE_JUMPIF expects an LLVM function');
        }
        $jumpIfTestBlock = $builder->getInsertBlock() ?? $branchBlock;
        self::$blockNumber++;
        $tmpIf = $func->appendBasicBlock('jumpif_tmp_if_' . self::$blockNumber);
        self::$blockNumber++;
        $tmpElse = $func->appendBasicBlock('jumpif_tmp_else_' . self::$blockNumber);
        $builder->positionAtEnd($jumpIfTestBlock);
        $existingTerm = $jumpIfTestBlock->getTerminator();
        if (null !== $existingTerm) {
            if (\PHPCompiler\JIT\BasicBlockHelper::isPrematureVoidReturn($this->context, $existingTerm)) {
                try {
                    $existingTerm->eraseFromParent();
                } catch (\Throwable) {
                }
            } else {
                \PHPCompiler\JIT\BasicBlockHelper::ensureOpenInsertBlock($this->context, 'jumpif_test_resume');
                $jumpIfTestBlock = $builder->getInsertBlock() ?? $jumpIfTestBlock;
            }
            $builder->positionAtEnd($jumpIfTestBlock);
        }
        $builder->branchIf($condition, $tmpIf, $tmpElse);
        $builder->positionAtEnd($tmpIf);
        $builder->branch($tmpIf);
        $builder->positionAtEnd($tmpElse);
        $builder->branch($tmpElse);
        $builder->clearInsertionPosition();
        $this->compileBlockInternal($func, $op->block1, null, null, 0, false, ...$args);
        if ($this->context->inlineIncludeDepth > 0) {
            $exitAfterIfBranch = $this->context->inlineIncludeExitBlock;
            $this->context->inlineIncludeExitBlock = null;
        }
        $this->compileBlockInternal($func, $op->block2, null, null, 0, false, ...$args);
        if ($this->context->inlineIncludeDepth > 0) {
            $this->context->inlineIncludeExitBlock = $exitAfterIfBranch
                ?? $this->context->inlineIncludeExitBlock
                ?? $savedIncludeExit;
        }
        $ifEntry = \PHPCompiler\JIT\TryCatchHelper::leaveBranchTarget(
            $this,
            $this->context,
            $func,
            $block,
            $op->block1,
            $args
        );
        $elseEntry = \PHPCompiler\JIT\TryCatchHelper::leaveBranchTarget(
            $this,
            $this->context,
            $func,
            $block,
            $op->block2,
            $args
        );
        $tmpTerm = $jumpIfTestBlock->getTerminator();
        if (
            null !== $tmpTerm
            && $tmpTerm instanceof \PHPLLVM\LLVMAbstract\Value
            && $ifEntry instanceof \PHPLLVM\LLVMAbstract\BasicBlock
            && $elseEntry instanceof \PHPLLVM\LLVMAbstract\BasicBlock
        ) {
            $lib = $this->context->llvm->lib;
            $lib->LLVMSetSuccessor($tmpTerm->value, 0, $ifEntry->block);
            $lib->LLVMSetSuccessor($tmpTerm->value, 1, $elseEntry->block);
        } else {
            $builder->positionAtEnd($jumpIfTestBlock);
            if (null !== $tmpTerm) {
                try {
                    $tmpTerm->eraseFromParent();
                } catch (\Throwable) {
                }
            }
            $builder->positionAtEnd($jumpIfTestBlock);
            $builder->branchIf($condition, $ifEntry, $elseEntry);
        }
        if (null !== $ternaryMergeReturn) {
            unset($this->context->coalesceAssignTargets[$ternaryMergeReturn]);
        }
        if (null !== $ternaryMergeEcho) {
            unset($this->context->coalesceAssignTargets[$ternaryMergeEcho]);
        }
        if (null !== $ternaryMergeEchoSlot) {
            // Drop slot→phi so a later ?: CONCAT cannot resolve a stale alias (#33849).
            unset($this->context->coalesceMergeSlotOperands[$ternaryMergeEchoSlot]);
        }
        $this->context->ternaryEchoPhiByAliasSlot = [];
        $this->clearTernaryEchoLiteralMergeState();
        $this->context->ternarySharedReturnOperand = $savedTernarySharedReturn;
        $this->context->ternarySharedReturnSlot = $savedTernarySharedReturnSlot;

        return $origBasicBlock;
    }
}
