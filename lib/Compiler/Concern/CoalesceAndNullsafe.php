<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler\Concern;

use PHPCfg\Op;
use PHPCfg\Operand;
use PHPTypes\Type;
use PHPCompiler\Block;
use PHPCompiler\OpCode;

/**
 * Coalesce (`??`) lowering (#36230 step 3 / #36387).
 *
 * Nullsafe (`?->`) helpers live in {@see NullsafePropertyAndMethodCompile}.
 * Extracted from {@see \PHPCompiler\Compiler} behind the opcode-corpus-md5 gate.
 * Visibility stays protected where LintCompiler / call sites require it.
 */
trait CoalesceAndNullsafe
{
    /**
     * ?? right branch: evaluate RHS expr ops only when the left is null (#3462, #3798).
     *
     * @return array{0: ?int, 1: Block} value slot and block that must receive the outer ?? assign
     */
    private function compileCoalesceRhsValue(Operand $rhs, Block $targetBlock, Block $entryBlock): array
    {
        $exprOp = $this->findOrigExprOpForOperand($rhs, $entryBlock);
        if ($exprOp instanceof Op\Expr\BinaryOp\Coalesce) {
            $afterCoalesce = $this->compileCoalesce($exprOp, $targetBlock);

            return [$this->compileCoalesceRhsResultSlot($exprOp, $afterCoalesce), $afterCoalesce];
        }
        if (null !== $exprOp) {
            if ($exprOp instanceof Op\Expr\Throw_) {
                foreach ($this->compileThrowExpression($exprOp, $targetBlock, $entryBlock) as $op) {
                    $targetBlock->addOpCode($op);
                }

                return [null, $targetBlock];
            }
            $afterExpr = $this->compileDeferredCoalesceBranchExpr($exprOp, $targetBlock);

            return [$afterExpr[1], $afterExpr[0]];
        }

        return [$this->compileOperand($rhs, $targetBlock, true), $targetBlock];
    }

    /**
     * Stmt-deferred ?? RHS ops are lowered with compileOperand(..., isRead: false); read the same slot (#11801).
     */
    private function compileCoalesceRhsResultSlot(Op\Expr $exprOp, Block $block): ?int
    {
        return $this->compileOperand($exprOp->result, $block, false);
    }

    /**
     * Lower stmt-deferred expr ops on a ?? branch (#3462, #5263).
     *
     * @return array{0: Block, 1: ?int} block and result slot for the deferred expr
     */
    private function compileDeferredCoalesceBranchExpr(Op\Expr $exprOp, Block $targetBlock): array
    {
        if ($exprOp instanceof Op\Expr\NullsafePropertyFetch) {
            $after = $this->compileNullsafePropertyFetch($exprOp, $targetBlock);

            return [$after, $this->compileCoalesceRhsResultSlot($exprOp, $after)];
        }
        if ($exprOp instanceof Op\Expr\NullsafeMethodCall) {
            $after = $this->compileNullsafeMethodCall($exprOp, $targetBlock);

            return [$after, $this->compileCoalesceRhsResultSlot($exprOp, $after)];
        }
        // Lower expr on the ?? branch; read the producer dest from emitted opcodes (#11801, #16206).
        $ops = $this->compileExpr($exprOp, $targetBlock);
        $resultSlot = null;
        foreach ($ops as $op) {
            $targetBlock->addOpCode($op);
            if (null !== $op->arg1) {
                $resultSlot = $op->arg1;
            }
        }
        if (null === $resultSlot) {
            $resultSlot = $this->compileCoalesceRhsResultSlot($exprOp, $targetBlock);
        }

        return [$targetBlock, $resultSlot];
    }

    /**
     * php-cfg emits RHS expr ops (New_, Throw_, …) before Coalesce; lower them on the ?? branch (#3462).
     *
     * @param Op[] $ops
     */
    private function isLoweredByFollowingCoalesce(Op $op, array $ops, int $index): bool
    {
        if (!$op instanceof Op\Expr) {
            return false;
        }
        $count = count($ops);
        for ($j = $index + 1; $j < $count; ++$j) {
            $next = $ops[$j];
            if ($next instanceof Op\Expr\BinaryOp\Coalesce) {
                return $this->exprOpFeedsCoalesceRhs($op, $next);
            }
            if (!$next instanceof Op\Expr\Throw_) {
                return false;
            }
        }

        return false;
    }

    private function exprOpFeedsCoalesceRhs(Op\Expr $op, Op\Expr\BinaryOp\Coalesce $coalesce): bool
    {
        if ($this->operandsChainEqual($op->result, $coalesce->right)) {
            return true;
        }
        $rhsRoot = $this->unwrapOperandChain($coalesce->right);
        if ($rhsRoot instanceof Op\Expr\Throw_ && $this->operandsChainEqual($op->result, $rhsRoot->expr)) {
            return true;
        }

        return false;
    }

    /**
     * php-cfg emits `Coalesce` then `Throw_(coalesce.result)` for `throw $lhs ?? $rhs` (#15315).
     *
     * @param Op[] $ops
     */
    private function isCoalesceLoweredByFollowingThrow(array $ops, int $index): bool
    {
        $op = $ops[$index] ?? null;
        if (!$op instanceof Op\Expr\BinaryOp\Coalesce) {
            return false;
        }
        $count = count($ops);
        for ($j = $index + 1; $j < $count; ++$j) {
            $next = $ops[$j];
            if ($next instanceof Op\Expr\Throw_) {
                return $this->operandsChainEqual($next->expr, $op->result);
            }
            if (!$next instanceof Op\Expr) {
                return false;
            }
        }

        return false;
    }

    protected function compileCoalesce(
        Op\Expr\BinaryOp\Coalesce $expr,
        Block $block,
        ?Operand $resultOverride = null
    ): Block {
        $resultOperand = $resultOverride ?? $expr->result;
        $nullsafeChain = null !== $expr->left
            ? $this->collectNullsafePropertyFetchChain($expr->left, $block)
            : [];
        $preEvaluatedNullsafeChain = [] !== $nullsafeChain;
        $preEvaluatedNullsafeMethod = null;
        if ($preEvaluatedNullsafeChain) {
            $block = $this->compileNullsafePropertyFetchChainEval($nullsafeChain, $block, true);
        } else {
            $nullsafeMethod = null !== $expr->left
                ? $this->findNullsafeMethodCallProducing($expr->left, $block)
                : null;
            if (null !== $nullsafeMethod) {
                $nullsafeId = spl_object_id($nullsafeMethod);
                if (!isset($this->nullsafeResultSlots[$nullsafeId])) {
                    $block = $this->compileNullsafeMethodCall($nullsafeMethod, $block);
                } elseif (isset($this->nullsafeMergeBlocks[$nullsafeId])) {
                    $block = $this->nullsafeMergeBlocks[$nullsafeId];
                }
                $preEvaluatedNullsafeMethod = $nullsafeMethod;
            }
        }
        // php-cfg may mark the ?? result dead while it is still assigned on branch blocks (#99).
        if ($resultOperand instanceof Operand\Temporary && [] === $resultOperand->usages) {
            $resultOperand->usages[] = $resultOperand;
        }
        if (null === $expr->left || $preEvaluatedNullsafeChain || null !== $preEvaluatedNullsafeMethod) {
            $propFetch = null;
            $staticPropFetch = null;
            $dimFetch = null;
        } else {
            $propFetch = $this->findCoalescePropertyFetch($expr->left, $block);
            $staticPropFetch = null !== $propFetch
                ? null
                : $this->findCoalesceStaticPropertyFetch($expr->left, $block);
            $dimFetch = null !== $propFetch || null !== $staticPropFetch
                ? null
                : $this->findCoalesceArrayDimFetch($expr->left, $block);
        }
        $dimFetchChain = null !== $dimFetch
            ? $this->collectArrayDimFetchChain($dimFetch, $block)
            : [];
        if ([] !== $dimFetchChain) {
            foreach ($dimFetchChain as $chainFetch) {
                $this->rejectArrayEmptyOffsetRead($chainFetch, $block);
            }
        } elseif (null !== $dimFetch) {
            $this->rejectArrayEmptyOffsetRead($dimFetch, $block);
        }
        // ??= on $arr['key']: dim fetch temp is read on the left branch (#3792).
        if (
            null !== $dimFetch
            && $dimFetch->result instanceof Operand\Temporary
            && [] === $dimFetch->result->usages
        ) {
            $dimFetch->result->usages[] = $dimFetch->result;
        }
        $resultSlot = $this->compileOperand($resultOperand, $block, false);

        $checkSlot = $this->compileBoolTemporary($block);
        $nestedDimCoalesce = null !== $dimFetch && count($dimFetchChain) >= 2;
        $issetTarget = null !== $propFetch
            ? $this->resolveIssetTargetFromPropertyFetch($propFetch, $block)
            : (null !== $staticPropFetch
                ? $this->resolveIssetTargetFromStaticPropertyFetch($staticPropFetch, $block)
                : ($nestedDimCoalesce
                    ? null
                    : (null !== $dimFetch
                        ? $this->resolveIssetTargetFromArrayDimFetch($dimFetch, $block)
                        : (null !== $expr->left
                            ? $this->resolveCoalesceIssetTarget($expr->left, $block)
                            : null))));
        $useContainerIsset = null !== $issetTarget || $nestedDimCoalesce;
        if ($useContainerIsset && null !== $issetTarget) {
            [$containerSlot, $dimSlot] = $issetTarget;
            if (null === $containerSlot) {
                $useContainerIsset = false;
            }
        }
        $evaluatedLeftSlot = null;
        if ($useContainerIsset && $nestedDimCoalesce) {
            // Nested `$a['x']['y'] ??…` / `??=`: quiet FETCH_DIM_IS for intermediates (#28954).
            [$prefixOps, $containerSlot] = $this->emitQuietDimFetchChainPrefix($dimFetchChain, $block);
            foreach ($prefixOps as $prefixOp) {
                $block->addOpCode($prefixOp);
            }
            $lastFetch = $dimFetchChain[count($dimFetchChain) - 1];
            $dimSlot = null !== $lastFetch->dim
                ? $this->compileOperand($lastFetch->dim, $block, true)
                : null;
            $block->addOpCode($this->makeIssetOpCode($checkSlot, $containerSlot, $dimSlot, false));
        } elseif ($useContainerIsset && null !== $propFetch) {
            // Instance prop ?? / ??= : fetch once (hook backing / magic IS-mode), then isset on
            // the value. Container isset alone skips __get and treats null-as-set (#29228).
            $this->compilePropertyFetchRead($propFetch, $block, true);
            $evaluatedLeftSlot = $this->compileOperand($propFetch->result, $block, true);
            $block->addOpCode(new OpCode(
                OpCode::TYPE_ISSET,
                $checkSlot,
                $evaluatedLeftSlot,
                null
            ));
            $useContainerIsset = false;
        } elseif ($useContainerIsset) {
            $issetOp = $this->makeIssetOpCode(
                $checkSlot,
                $containerSlot,
                $dimSlot,
                false
            );
            if (null !== $staticPropFetch) {
                $issetOp->issetOnStaticProperty = true;
                $issetOp->issetForCoalesceAssign = true;
            }
            $block->addOpCode($issetOp);
        } elseif (null !== $expr->left) {
            if ($preEvaluatedNullsafeChain) {
                $lastFetch = $nullsafeChain[count($nullsafeChain) - 1];
                $evaluatedLeftSlot = $this->compileOperand($lastFetch->result, $block, false);
            } elseif (null !== $preEvaluatedNullsafeMethod) {
                $evaluatedLeftSlot = $this->compileOperand($preEvaluatedNullsafeMethod->result, $block, false);
            } else {
                $evaluatedLeftSlot = $this->compileOperand($expr->left, $block, true);
            }
            $block->addOpCode(new OpCode(
                OpCode::TYPE_ISSET,
                $checkSlot,
                $evaluatedLeftSlot,
                null
            ));
        } else {
            $block->addOpCode(new OpCode(
                OpCode::TYPE_ISSET,
                $checkSlot,
                $resultSlot,
                null
            ));
        }

        $endBlock = new Block($block->orig);
        $endBlock->inheritUndefinedLocals = true;
        $endBlock->inheritScopeFrom($block);

        $rightBlock = new Block($block->orig);
        $rightBlock->syntheticCfgBranch = true;
        $rightBlock->inheritUndefinedLocals = true;
        $rightBlock->inheritScopeFrom($block);
        [$rightSlot, $rightEmitBlock] = $this->compileCoalesceRhsValue($expr->right, $rightBlock, $block);
        $coalesceAssignTarget = $resultOverride ?? $expr->result;
        if (
            null !== $dimFetch
            && $this->operandsChainEqual($coalesceAssignTarget, $dimFetch->result)
        ) {
            // Nested ??= must FETCH_DIM_W the whole chain so intermediates auto-vivify (#28954).
            if (count($dimFetchChain) >= 2) {
                $this->compileArrayDimFetchWriteChain($dimFetchChain, $rightEmitBlock);
            } else {
                $this->compileArrayDimFetchWrite($dimFetch, $rightEmitBlock);
            }
        }
        if (
            null !== $propFetch
            && $this->operandsChainEqual($coalesceAssignTarget, $propFetch->result)
        ) {
            $this->compilePropertyFetchWrite($propFetch, $rightEmitBlock);
        }
        if (
            null !== $staticPropFetch
            && $this->operandsChainEqual($coalesceAssignTarget, $staticPropFetch->result)
        ) {
            $this->compileStaticPropertyFetchWrite($staticPropFetch, $rightEmitBlock);
        }
        if (null !== $rightSlot && $rightSlot !== $resultSlot) {
            $rightEmitBlock->addOpCode(new OpCode(
                OpCode::TYPE_ASSIGN,
                $resultSlot,
                $resultSlot,
                $rightSlot
            ));
        }

        $leftBlock = new Block($block->orig);
        $leftBlock->syntheticCfgBranch = true;
        $leftBlock->inheritUndefinedLocals = true;
        $leftBlock->inheritScopeFrom($block);
        if ($useContainerIsset) {
            if (null !== $dimFetch) {
                // Container isset already emitted float→int DEP; left read must not (#29664).
                if (count($dimFetchChain) >= 2) {
                    $this->compileArrayDimFetchReadChain($dimFetchChain, $leftBlock, true);
                } else {
                    $this->compileArrayDimFetchRead($dimFetch, $leftBlock, true);
                }
                $leftSlot = $this->compileOperand($dimFetch->result, $leftBlock, true);
                // ??= left branch: skip store when result is the assign lvalue (php-src: no write when set).
                if (null !== $expr->left && !$this->operandsChainEqual($resultOperand, $expr->left)) {
                    $leftBlock->addOpCode(new OpCode(
                        OpCode::TYPE_ASSIGN,
                        $resultSlot,
                        $resultSlot,
                        $leftSlot
                    ));
                }
            } elseif (null !== $propFetch) {
                $this->compilePropertyFetchRead($propFetch, $leftBlock, true);
                $leftSlot = $this->compileOperand($propFetch->result, $leftBlock, true);
                if (!$this->operandsChainEqual($resultOperand, $expr->left)) {
                    $leftBlock->addOpCode(new OpCode(
                        OpCode::TYPE_ASSIGN,
                        $resultSlot,
                        $resultSlot,
                        $leftSlot
                    ));
                }
            } elseif (null !== $staticPropFetch) {
                $this->compileStaticPropertyFetchRead($staticPropFetch, $leftBlock, true);
                $leftSlot = $this->compileOperand($staticPropFetch->result, $leftBlock, true);
                if (!$this->operandsChainEqual($resultOperand, $expr->left)) {
                    $leftBlock->addOpCode(new OpCode(
                        OpCode::TYPE_ASSIGN,
                        $resultSlot,
                        $resultSlot,
                        $leftSlot
                    ));
                }
            } elseif (null !== $expr->left) {
                $leftSlot = $this->compileOperand($expr->left, $leftBlock, true);
                if (!$this->operandsChainEqual($resultOperand, $expr->left)) {
                    $leftBlock->addOpCode(new OpCode(
                        OpCode::TYPE_ASSIGN,
                        $resultSlot,
                        $resultSlot,
                        $leftSlot
                    ));
                }
            }
        } elseif (null !== $evaluatedLeftSlot) {
            // Nullsafe and other pre-evaluated ?? left operands: reuse entry-block temp (#9744).
            if (!$this->operandsChainEqual($resultOperand, $expr->left)) {
                $leftBlock->addOpCode(new OpCode(
                    OpCode::TYPE_ASSIGN,
                    $resultSlot,
                    $resultSlot,
                    $evaluatedLeftSlot
                ));
            }
        }

        $leftJump = new OpCode(OpCode::TYPE_JUMP);
        $leftJump->block1 = $endBlock;
        $leftBlock->addOpCode($leftJump);
        $rightJump = new OpCode(OpCode::TYPE_JUMP);
        $rightJump->block1 = $endBlock;
        $rightEmitBlock->addOpCode($rightJump);
        $endBlock->parents[] = $leftBlock;
        $endBlock->parents[] = $rightEmitBlock;
        $endBlock->inheritScopeFrom($leftBlock);
        $endBlock->inheritScopeFrom($rightEmitBlock);

        $this->coalesceResultSlots[spl_object_id($expr)] = $resultSlot;
        $this->coalesceMergeBlocks[spl_object_id($expr)] = $endBlock;

        $coalesceOp = new OpCode(
            OpCode::TYPE_COALESCE,
            $resultSlot,
            $checkSlot
        );
        $coalesceOp->block1 = $leftBlock;
        $coalesceOp->block2 = $rightBlock;
        $coalesceOp->block3 = $endBlock;
        $block->addOpCode($coalesceOp);

        return $endBlock;
    }

}
