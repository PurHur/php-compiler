<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler\Concern;

use PHPCfg\Op;
use PHPCfg\Operand;
use PHPTypes\Type;
use PHPCompiler\Block;
use PHPCompiler\OpCode;

/**
 * Coalesce (`??`) lowering and nullsafe (`?->`) prelude / chain machinery (#36230 step 3).
 *
 * Extracted from {@see \PHPCompiler\Compiler} behind the opcode-corpus-md5 gate.
 * Visibility stays protected where LintCompiler / call sites require it.
 */
trait CoalesceAndNullsafe
{
    /**
     * @return list<Op\Expr\NullsafePropertyFetch>
     */
    protected function collectNullsafePropertyFetchChain(?Operand $operand, Block $block): array
    {
        $innermost = $this->findNullsafePropertyFetch($operand, $block);
        if (null === $innermost) {
            return [];
        }
        $chain = [$innermost];
        $var = $innermost->var;
        while (true) {
            $prev = $this->findNullsafePropertyFetchProducing($var, $block);
            if (null === $prev) {
                break;
            }
            array_unshift($chain, $prev);
            $var = $prev->var;
        }

        return $chain;
    }

    /**
     * @return list<Op\Expr\NullsafePropertyFetch>
     */
    protected function collectNullsafePropertyFetchChainForEmpty(Op\Expr\Empty_ $expr, Block $block): array
    {
        $operand = $this->unaryExprOperandForRead($expr, $block);
        if (null === $operand) {
            return [];
        }

        return $this->collectNullsafePropertyFetchChain($operand, $block);
    }

    /**
     * @return ?Op\Expr\NullsafePropertyFetch
     */
    protected function findNullsafePropertyFetch(?Operand $operand, Block $block): ?Op\Expr\NullsafePropertyFetch
    {
        if (null === $operand) {
            return null;
        }
        $candidates = [$operand];
        $seen = [];
        while ([] !== $candidates) {
            $current = array_shift($candidates);
            if (isset($seen[spl_object_id($current)])) {
                continue;
            }
            $seen[spl_object_id($current)] = true;
            foreach ($block->orig->children as $child) {
                if ($child instanceof Op\Expr\NullsafePropertyFetch && $child->result === $current) {
                    return $child;
                }
            }
            if ($current instanceof Temporary && null !== $current->original) {
                $candidates[] = $current->original;
            }
        }

        return null;
    }

    /**
     * @return ?Op\Expr\NullsafePropertyFetch
     */
    protected function findNullsafePropertyFetchProducing(?Operand $operand, Block $block): ?Op\Expr\NullsafePropertyFetch
    {
        if (null === $operand) {
            return null;
        }
        foreach ($block->orig->children as $child) {
            if ($child instanceof Op\Expr\NullsafePropertyFetch && $child->result === $operand) {
                return $child;
            }
        }
        if ($operand instanceof Temporary && null !== $operand->original) {
            return $this->findNullsafePropertyFetchProducing($operand->original, $block);
        }

        return null;
    }

    /**
     * php-cfg Temporary.original is often null; locate NullsafeMethodCall by result operand (#19591).
     *
     * @return ?Op\Expr\NullsafeMethodCall
     */
    protected function findNullsafeMethodCallProducing(?Operand $operand, Block $block): ?Op\Expr\NullsafeMethodCall
    {
        if (null === $operand || null === $block->orig) {
            return null;
        }
        foreach ($block->orig->children as $child) {
            if (
                $child instanceof Op\Expr\NullsafeMethodCall
                && (
                    $child->result === $operand
                    || $this->operandsChainEqual($child->result, $operand)
                )
            ) {
                return $child;
            }
        }
        if ($operand instanceof Temporary && null !== $operand->original) {
            if ($operand->original instanceof Op\Expr\NullsafeMethodCall) {
                return $operand->original;
            }

            return $this->findNullsafeMethodCallProducing($operand->original, $block);
        }

        return null;
    }

    /**
     * @param Op[] $ops
     */
    protected function shouldSkipNullsafePropertyFetchForIssetOrEmpty(
        Op\Expr\NullsafePropertyFetch $fetch,
        array $ops,
        int $index,
        Block $block
    ): bool {
        for ($j = $index + 1, $count = count($ops); $j < $count; ++$j) {
            $next = $ops[$j];
            if ($next instanceof Op\Expr\NullsafePropertyFetch) {
                continue;
            }
            if ($next instanceof Op\Expr\Isset_ && 1 === count($next->vars)) {
                $chain = $this->collectNullsafePropertyFetchChain($next->vars[0], $block);

                return [] !== $chain && in_array($fetch, $chain, true);
            }
            if ($next instanceof Op\Expr\Empty_) {
                $chain = $this->collectNullsafePropertyFetchChainForEmpty($next, $block);

                return [] !== $chain && in_array($fetch, $chain, true);
            }

            return false;
        }

        return false;
    }

    /**
     * @param Op[] $ops
     */
    protected function shouldSkipNullsafePropertyFetchForCoalesce(
        Op\Expr\NullsafePropertyFetch $fetch,
        array $ops,
        int $index,
        Block $block
    ): bool {
        for ($j = $index + 1, $count = count($ops); $j < $count; ++$j) {
            $next = $ops[$j];
            if ($next instanceof Op\Expr\NullsafePropertyFetch) {
                continue;
            }
            if ($next instanceof Op\Expr\BinaryOp\Coalesce) {
                $chain = $this->collectNullsafePropertyFetchChain($next->left, $block);

                return [] !== $chain && in_array($fetch, $chain, true);
            }

            return false;
        }

        return false;
    }

    /**
     * Defer $a->b?->m() when it feeds a following ?? so coalesce can continue on the
     * nullsafe merge block (#19591).
     *
     * @param Op[] $ops
     */
    protected function shouldSkipNullsafeMethodCallForCoalesce(
        Op\Expr\NullsafeMethodCall $call,
        array $ops,
        int $index,
        Block $block
    ): bool {
        for ($j = $index + 1, $count = count($ops); $j < $count; ++$j) {
            $next = $ops[$j];
            if ($next instanceof Op\Expr\BinaryOp\Coalesce) {
                return $this->operandsChainEqual($next->left, $call->result)
                    || $next->left === $call->result;
            }
            if ($next instanceof Op\Expr\NullsafePropertyFetch || $next instanceof Op\Expr\NullsafeMethodCall) {
                continue;
            }

            return false;
        }

        return false;
    }

    /**
     * php-cfg lowers $a->b?->v as PropertyFetch then NullsafePropertyFetch — skip eager fetch (#16637).
     *
     * @param Op[] $ops
     */
    private function isPropertyFetchNullsafeReceiver(
        Op\Expr\PropertyFetch $fetch,
        array $ops,
        int $index
    ): bool {
        if ($index + 1 >= count($ops)) {
            return false;
        }
        $next = $ops[$index + 1];

        if (
            $next instanceof Op\Expr\NullsafePropertyFetch
            && $this->operandsChainEqual($next->var, $fetch->result)
        ) {
            return true;
        }

        // $a->b?->m() — receiver fetch is emitted inside compileNullsafeMethodCall / coalesce (#19591).
        return $next instanceof Op\Expr\NullsafeMethodCall
            && $this->operandsChainEqual($next->var, $fetch->result);
    }

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

    /**
     * var_export($o?->prop) — php-cfg hoists NullsafePropertyFetch before FuncCall (#18455).
     */
    private function syncNullsafePropertyFetchResultToFollowingFuncCallArg(
        Op\Expr\NullsafePropertyFetch $fetch,
        Block $block
    ): void {
        if (null === $block->orig) {
            return;
        }
        $fetchIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $fetch, $block->orig);
        if (!is_int($fetchIndex)) {
            return;
        }
        $next = $block->orig->children[$fetchIndex + 1] ?? null;
        if (!$next instanceof Op\Expr\FuncCall && !$next instanceof Op\Expr\NsFuncCall) {
            return;
        }
        $fetchSlot = $this->compiledExpressionPreludeResultSlotBeforePendingFuncCall($block, $fetch);
        if (null === $fetchSlot) {
            $fetchSlot = $this->slotForNullsafeResult($block, $fetch);
        }
        if (null === $fetchSlot) {
            $fetchSlot = $block->slotForOperand($fetch->result);
        }
        if (null === $fetchSlot) {
            return;
        }
        if (null !== $fetch->result) {
            $block->bindOperandScopeSlot($fetch->result, $fetchSlot);
        }
        if (!property_exists($next, 'args') || !is_array($next->args)) {
            return;
        }
        foreach ($next->args as $argIndex => $arg) {
            if (!$arg instanceof Operand) {
                continue;
            }
            if (null !== $fetch->result && $this->operandsReferToSameVariable($arg, $fetch->result)) {
                $block->bindOperandScopeSlot($arg, $fetchSlot);
                $this->registerSyncedCoalesceFuncCallArgSlot($arg, $fetchSlot);
                continue;
            }
            if (!$this->callArgIsDeadInlineTemporary($arg)) {
                continue;
            }
            $deadTempIndices = [];
            foreach ($next->args as $i => $candidate) {
                if (!$candidate instanceof Operand || $this->isEmbeddedCallLiteralArg($candidate)) {
                    continue;
                }
                if ($this->callArgIsDeadInlineTemporary($candidate)) {
                    $deadTempIndices[] = (int) $i;
                }
            }
            if (1 === \count($deadTempIndices) && (int) $argIndex === $deadTempIndices[0]) {
                $block->bindOperandScopeSlot($arg, $fetchSlot);
                $this->registerSyncedCoalesceFuncCallArgSlot($arg, $fetchSlot);
            }
        }
        if (null !== $fetch->result) {
            $this->registerSyncedCoalesceFuncCallArgSlot($fetch->result, $fetchSlot);
        }
    }

    /**
     * Array offset immediately after ?-> property/method fetch (issue #3516).
     *
     * @param Op[] $ops
     */
    private function isNullsafeChainArrayDimFetch(array $ops, int $index): bool
    {
        if ($index < 1) {
            return false;
        }
        $fetch = $ops[$index];
        if (!$fetch instanceof Op\Expr\ArrayDimFetch) {
            return false;
        }
        $prev = $ops[$index - 1];
        if (!$prev instanceof Op\Expr\NullsafePropertyFetch && !$prev instanceof Op\Expr\NullsafeMethodCall) {
            return false;
        }

        return $prev->result === $fetch->var;
    }

    protected function compileNullsafeArrayDimFetch(Op\Expr\ArrayDimFetch $expr, Block $block): Block
    {
        $this->rejectArrayEmptyOffsetRead($expr, $block);
        $resultSlot = $this->compileOperand($expr->result, $block, false);
        $containerSlot = $this->compileOperand($expr->var, $block, true);
        $dimSlot = null !== $expr->dim ? $this->compileOperand($expr->dim, $block, true) : null;

        $endBlock = new Block($block->orig);
        $endBlock->inheritUndefinedLocals = true;
        $endBlock->inheritScopeFrom($block);

        $nullBlock = new Block($block->orig);
        $nullBlock->inheritUndefinedLocals = true;
        $nullBlock->inheritScopeFrom($block);
        $nullLiteral = new Operand\Literal(null);
        $nullLiteral->type = Type::null();
        $nullValueSlot = $this->compileOperand($nullLiteral, $nullBlock, true);
        $nullBlock->addOpCode(new OpCode(
            OpCode::TYPE_ASSIGN,
            $resultSlot,
            $resultSlot,
            $nullValueSlot
        ));
        $nullJump = new OpCode(OpCode::TYPE_JUMP);
        $nullJump->block1 = $endBlock;
        $nullBlock->addOpCode($nullJump);

        $fetchBlock = new Block($block->orig);
        $fetchBlock->inheritUndefinedLocals = true;
        $fetchBlock->inheritScopeFrom($block);
        $fetchBlock->addOpCode(new OpCode(
            OpCode::TYPE_ARRAY_DIM_FETCH,
            $this->compileOperand($expr->result, $fetchBlock, false),
            $this->compileArrayDimFetchContainerSlot($expr, $fetchBlock),
            $dimSlot
        ));
        $fetchJump = new OpCode(OpCode::TYPE_JUMP);
        $fetchJump->block1 = $endBlock;
        $fetchBlock->addOpCode($fetchJump);
        $endBlock->parents[] = $nullBlock;
        $endBlock->parents[] = $fetchBlock;

        $nullsafeOp = new OpCode(
            OpCode::TYPE_NULLSAFE,
            $resultSlot,
            $containerSlot
        );
        $nullsafeOp->block1 = $nullBlock;
        $nullsafeOp->block2 = $fetchBlock;
        $nullsafeOp->block3 = $endBlock;
        $block->addOpCode($nullsafeOp);

        return $endBlock;
    }

    protected function compileNullsafePropertyFetch(
        Op\Expr\NullsafePropertyFetch $expr,
        Block $block,
        bool $allowUninitNullableShortCircuit = false
    ): Block {
        $resultSlot = $this->compileOperand($expr->result, $block, false);
        $receiverSlot = $this->compileNullsafeReceiverSlot($expr->var, $block, $allowUninitNullableShortCircuit);

        $endBlock = new Block($block->orig);
        $endBlock->inheritUndefinedLocals = true;
        $endBlock->inheritScopeFrom($block);

        $nullBlock = new Block($block->orig);
        $nullBlock->inheritUndefinedLocals = true;
        $nullBlock->inheritScopeFrom($block);
        $nullLiteral = new Operand\Literal(null);
        $nullLiteral->type = Type::null();
        $nullValueSlot = $this->compileOperand($nullLiteral, $nullBlock, true);
        $nullBlock->addOpCode(new OpCode(
            OpCode::TYPE_ASSIGN,
            $resultSlot,
            $resultSlot,
            $nullValueSlot
        ));
        $nullJump = new OpCode(OpCode::TYPE_JUMP);
        $nullJump->block1 = $endBlock;
        $nullBlock->addOpCode($nullJump);

        $fetchBlock = new Block($block->orig);
        $fetchBlock->inheritUndefinedLocals = true;
        $fetchBlock->inheritScopeFrom($block);
        // Use the same receiver slot NULLSAFE branched on — Temporary.original is often
        // null after type passes, so re-compileOperand($expr->var) can bind a dead slot (#19591).
        $nullsafePropertyFetch = new OpCode(
            OpCode::TYPE_PROPERTY_FETCH,
            $this->compileOperand($expr->result, $fetchBlock, false),
            $receiverSlot,
            $this->compileOperand($expr->name, $fetchBlock, true)
        );
        $nullsafePropertyFetch->nullsafeFetchPropertyRead = true;
        $nullsafePropertyFetch->nullsafeUninitNullableToNull = $allowUninitNullableShortCircuit;
        // ?? / isset-empty LHS: BP_VAR_IS quiet fetch (no Undefined property), like $obj->prop ?? (#30030).
        if ($allowUninitNullableShortCircuit) {
            $nullsafePropertyFetch->propertyHookCoalesceRead = true;
        }
        $fetchBlock->addOpCode($nullsafePropertyFetch);
        $fetchJump = new OpCode(OpCode::TYPE_JUMP);
        $fetchJump->block1 = $endBlock;
        $fetchBlock->addOpCode($fetchJump);
        $endBlock->parents[] = $nullBlock;
        $endBlock->parents[] = $fetchBlock;
        $nullBlock->parents[] = $block;
        $fetchBlock->parents[] = $block;

        $nullsafeOp = new OpCode(
            OpCode::TYPE_NULLSAFE,
            $resultSlot,
            $receiverSlot
        );
        $nullsafeOp->block1 = $nullBlock;
        $nullsafeOp->block2 = $fetchBlock;
        $nullsafeOp->block3 = $endBlock;
        $block->addOpCode($nullsafeOp);

        $this->nullsafeResultSlots[spl_object_id($expr)] = $resultSlot;
        $this->nullsafeMergeBlocks[spl_object_id($expr)] = $endBlock;

        return $endBlock;
    }

    /**
     * @param list<Op\Expr\NullsafePropertyFetch> $chain
     */
    protected function compileNullsafePropertyFetchChainEval(
        array $chain,
        Block $block,
        bool $allowUninitNullableShortCircuit
    ): Block {
        foreach ($chain as $fetch) {
            $block = $this->compileNullsafePropertyFetch($fetch, $block, $allowUninitNullableShortCircuit);
        }

        return $block;
    }

    /**
     * Bind a ?-> receiver without reading typed slots (#5220, $a->b?->v).
     *
     * php-cfg Temporary.original is often cleared by type reconstruction; when the preceding
     * PropertyFetch was skipped (#16637), recover it from the CFG block (#19591).
     */
    private function compileNullsafeReceiverSlot(
        ?Operand $var,
        Block $block,
        bool $allowUninitNullableShortCircuit = false
    ): int {
        if (null === $var) {
            throw new \LogicException('Nullsafe property fetch requires a receiver operand');
        }
        $propFetch = $this->unwrapPropertyFetch($var);
        if (null === $propFetch) {
            $propFetch = $this->findCoalescePropertyFetch($var, $block);
        }
        if (null !== $propFetch) {
            $receiverSlot = $this->compileOperand($propFetch->result, $block, false);
            $receiverFetch = new OpCode(
                OpCode::TYPE_PROPERTY_FETCH,
                $receiverSlot,
                $this->compileOperand($propFetch->var, $block, true),
                $this->compileOperand($propFetch->name, $block, true)
            );
            if ($allowUninitNullableShortCircuit) {
                $receiverFetch->nullsafeFetchPropertyRead = true;
                $receiverFetch->nullsafeUninitNullableToNull = true;
                // Intermediate $a->b under $a->b?->v ?? … is also FETCH_OBJ_IS (#30030).
                $receiverFetch->propertyHookCoalesceRead = true;
            }
            $block->addOpCode($receiverFetch);

            return $receiverSlot;
        }

        return $this->compileOperand($var, $block, true);
    }

    /**
     * @param list<Op\Expr\NullsafePropertyFetch> $chain
     */
    protected function compileIssetNullsafePropertyFetchChain(
        array $chain,
        Op\Expr\Isset_ $isset,
        Block $block
    ): Block {
        $resultSlot = $this->compileOperand($isset->result, $block, false);
        $endBlock = new Block($block->orig);
        $endBlock->inheritUndefinedLocals = true;
        $endBlock->inheritScopeFrom($block);
        $this->compileIssetNullsafeChainLink($chain, 0, $block, $resultSlot, $endBlock);

        return $endBlock;
    }

    /**
     * @param list<Op\Expr\NullsafePropertyFetch> $chain
     */
    protected function compileEmptyNullsafePropertyFetchChain(
        array $chain,
        Op\Expr\Empty_ $empty,
        Block $block
    ): Block {
        $resultSlot = $this->compileOperand($empty->result, $block, false);
        $endBlock = new Block($block->orig);
        $endBlock->inheritUndefinedLocals = true;
        $endBlock->inheritScopeFrom($block);
        $this->compileEmptyNullsafeChainLink($chain, 0, $block, $resultSlot, $endBlock);

        return $endBlock;
    }

    /**
     * @param list<Op\Expr\NullsafePropertyFetch> $chain
     */
    protected function compileIssetNullsafeChainLink(
        array $chain,
        int $index,
        Block $block,
        int $resultSlot,
        Block $endBlock
    ): void {
        $fetch = $chain[$index];
        $isLast = $index === count($chain) - 1;
        $receiverSlot = $this->compileNullsafeReceiverSlot($fetch->var, $block, true);

        $nullBlock = new Block($block->orig);
        $nullBlock->inheritUndefinedLocals = true;
        $nullBlock->inheritScopeFrom($block);
        $falseSlot = $this->compileBoolConstant($nullBlock, false);
        $nullBlock->addOpCode(new OpCode(
            OpCode::TYPE_ASSIGN,
            $resultSlot,
            $resultSlot,
            $falseSlot
        ));
        $nullJump = new OpCode(OpCode::TYPE_JUMP);
        $nullJump->block1 = $endBlock;
        $nullBlock->addOpCode($nullJump);

        $fetchBlock = new Block($block->orig);
        $fetchBlock->inheritUndefinedLocals = true;
        $fetchBlock->inheritScopeFrom($block);
        if ($isLast) {
            $fetchBlock->addOpCode($this->makeIssetOpCode(
                $resultSlot,
                $this->compileOperand($fetch->var, $fetchBlock, true),
                $this->compileOperand($fetch->name, $fetchBlock, true),
                true
            ));
            $fetchJump = new OpCode(OpCode::TYPE_JUMP);
            $fetchJump->block1 = $endBlock;
            $fetchBlock->addOpCode($fetchJump);
        } else {
            $intermediateSlot = $this->compileOperand($fetch->result, $fetchBlock, false);
            $propFetch = new OpCode(
                OpCode::TYPE_PROPERTY_FETCH,
                $intermediateSlot,
                $this->compileOperand($fetch->var, $fetchBlock, true),
                $this->compileOperand($fetch->name, $fetchBlock, true)
            );
            $propFetch->nullsafeFetchPropertyRead = true;
            $propFetch->nullsafeUninitNullableToNull = true;
            $propFetch->propertyHookCoalesceRead = true;
            $fetchBlock->addOpCode($propFetch);
            $this->compileIssetNullsafeChainLink($chain, $index + 1, $fetchBlock, $resultSlot, $endBlock);
        }

        $endBlock->parents[] = $nullBlock;
        $endBlock->parents[] = $fetchBlock;

        $nullsafeOp = new OpCode(
            OpCode::TYPE_NULLSAFE,
            $isLast ? $resultSlot : $this->compileOperand($fetch->result, $block, false),
            $receiverSlot
        );
        $nullsafeOp->block1 = $nullBlock;
        $nullsafeOp->block2 = $fetchBlock;
        $nullsafeOp->block3 = $endBlock;
        $block->addOpCode($nullsafeOp);
    }

    /**
     * @param list<Op\Expr\NullsafePropertyFetch> $chain
     */
    protected function compileEmptyNullsafeChainLink(
        array $chain,
        int $index,
        Block $block,
        int $resultSlot,
        Block $endBlock
    ): void {
        $fetch = $chain[$index];
        $isLast = $index === count($chain) - 1;
        $receiverSlot = $this->compileNullsafeReceiverSlot($fetch->var, $block, true);

        $nullBlock = new Block($block->orig);
        $nullBlock->inheritUndefinedLocals = true;
        $nullBlock->inheritScopeFrom($block);
        $trueSlot = $this->compileBoolConstant($nullBlock, true);
        $nullBlock->addOpCode(new OpCode(
            OpCode::TYPE_ASSIGN,
            $resultSlot,
            $resultSlot,
            $trueSlot
        ));
        $nullJump = new OpCode(OpCode::TYPE_JUMP);
        $nullJump->block1 = $endBlock;
        $nullBlock->addOpCode($nullJump);

        $fetchBlock = new Block($block->orig);
        $fetchBlock->inheritUndefinedLocals = true;
        $fetchBlock->inheritScopeFrom($block);
        if ($isLast) {
            $fetchBlock->addOpCode(new OpCode(
                OpCode::TYPE_EMPTY_OBJECT_PROPERTY,
                $resultSlot,
                $this->compileOperand($fetch->var, $fetchBlock, true),
                $this->compileOperand($fetch->name, $fetchBlock, true),
            ));
            $fetchJump = new OpCode(OpCode::TYPE_JUMP);
            $fetchJump->block1 = $endBlock;
            $fetchBlock->addOpCode($fetchJump);
        } else {
            $intermediateSlot = $this->compileOperand($fetch->result, $fetchBlock, false);
            $propFetch = new OpCode(
                OpCode::TYPE_PROPERTY_FETCH,
                $intermediateSlot,
                $this->compileOperand($fetch->var, $fetchBlock, true),
                $this->compileOperand($fetch->name, $fetchBlock, true)
            );
            $propFetch->nullsafeFetchPropertyRead = true;
            $propFetch->nullsafeUninitNullableToNull = true;
            $propFetch->propertyHookCoalesceRead = true;
            $fetchBlock->addOpCode($propFetch);
            $this->compileEmptyNullsafeChainLink($chain, $index + 1, $fetchBlock, $resultSlot, $endBlock);
        }

        $endBlock->parents[] = $nullBlock;
        $endBlock->parents[] = $fetchBlock;

        $nullsafeOp = new OpCode(
            OpCode::TYPE_NULLSAFE,
            $isLast ? $resultSlot : $this->compileOperand($fetch->result, $block, false),
            $receiverSlot
        );
        $nullsafeOp->block1 = $nullBlock;
        $nullsafeOp->block2 = $fetchBlock;
        $nullsafeOp->block3 = $endBlock;
        $block->addOpCode($nullsafeOp);
    }

    /**
     * @param list<Op> $deferredPreludeOps
     */
    protected function compileNullsafeMethodCall(
        Op\Expr\NullsafeMethodCall $expr,
        Block $block,
        array $deferredPreludeOps = []
    ): Block
    {
        $resultSlot = $this->compileOperand($expr->result, $block, false);
        // Same receiver binding as nullsafe property — Temporary.original is often null (#19591).
        $receiverSlot = $this->compileNullsafeReceiverSlot($expr->var, $block, false);

        $endBlock = new Block($block->orig);
        $endBlock->inheritUndefinedLocals = true;
        $endBlock->inheritScopeFrom($block);

        $nullBlock = new Block($block->orig);
        $nullBlock->inheritUndefinedLocals = true;
        $nullBlock->inheritScopeFrom($block);
        $nullLiteral = new Operand\Literal(null);
        $nullLiteral->type = Type::null();
        $nullValueSlot = $this->compileOperand($nullLiteral, $nullBlock, true);
        $nullBlock->addOpCode(new OpCode(
            OpCode::TYPE_ASSIGN,
            $resultSlot,
            $resultSlot,
            $nullValueSlot
        ));
        $nullJump = new OpCode(OpCode::TYPE_JUMP);
        $nullJump->block1 = $endBlock;
        $nullBlock->addOpCode($nullJump);

        $fetchBlock = new Block($block->orig);
        $fetchBlock->inheritUndefinedLocals = true;
        $fetchBlock->inheritScopeFrom($block);
        if (!empty($deferredPreludeOps)) {
            // parseArg clones leave producer->result usages empty and NullsafeMethodCall is
            // not in fetchBlock->orig, so bare compileOps would emit EXEC_NORETURN and
            // ARG_SEND would allocate a fresh empty slot for the clone (#22660 / #8560).
            foreach ($expr->args as $arg) {
                if (!$arg instanceof Operand\Temporary) {
                    continue;
                }
                foreach ($deferredPreludeOps as $preludeOp) {
                    if (
                        !$preludeOp instanceof Op\Expr
                        || null === $preludeOp->result
                        || !$this->nullsafeCallArgTempFedByProducer($arg, $preludeOp)
                    ) {
                        continue;
                    }
                    $sharedSlot = $fetchBlock->getVarSlot($preludeOp->result, false);
                    $fetchBlock->bindOperandScopeSlot($arg, $sharedSlot);
                    break;
                }
            }
            $prevForceReturn = $this->forceDeferredSiblingCallReturnSlot;
            $this->forceDeferredSiblingCallReturnSlot = true;
            try {
                $this->compileOps($deferredPreludeOps, $fetchBlock);
            } finally {
                $this->forceDeferredSiblingCallReturnSlot = $prevForceReturn;
            }
        }
        $fetchBlock->addOpCode(new OpCode(
            OpCode::TYPE_METHODCALL_INIT,
            $receiverSlot,
            $this->compileOperand($expr->name, $fetchBlock, true)
        ));
        foreach ($this->compileCallArgSends($expr->args, $fetchBlock, null, $expr) as $send) {
            $fetchBlock->addOpCode($send);
        }
        $fetchBlock->addOpCode($this->compileFuncCallExecOpcode(
            $expr->result,
            $fetchBlock,
            max(0, $expr->getLine())
        ));
        $fetchJump = new OpCode(OpCode::TYPE_JUMP);
        $fetchJump->block1 = $endBlock;
        $fetchBlock->addOpCode($fetchJump);
        $endBlock->parents[] = $nullBlock;
        $endBlock->parents[] = $fetchBlock;
        $nullBlock->parents[] = $block;
        $fetchBlock->parents[] = $block;

        $nullsafeOp = new OpCode(
            OpCode::TYPE_NULLSAFE,
            $resultSlot,
            $receiverSlot
        );
        $nullsafeOp->nullsafeMethodCall = true;
        $nullsafeOp->block1 = $nullBlock;
        $nullsafeOp->block2 = $fetchBlock;
        $nullsafeOp->block3 = $endBlock;
        $block->addOpCode($nullsafeOp);

        $this->nullsafeResultSlots[spl_object_id($expr)] = $resultSlot;
        $this->nullsafeMergeBlocks[spl_object_id($expr)] = $endBlock;

        return $endBlock;
    }

    /**
     * @return list<\PHPCfg\Operand>
     */
    private function nullsafePreludeOperandVars(Op\Expr $expr): array
    {
        // Minimal dependency extraction for nullsafe argument prelude sinking (#4394).
        // Extend carefully; keep conservative (only single-use temporaries are eligible).
        return match (get_class($expr)) {
            Op\Expr\FuncCall::class => array_merge([$expr->name], $expr->args),
            Op\Expr\Closure::class => [],
            default => [],
        };
    }

    /**
     * True when a nullsafe call-arg temporary is the producer result or a parseArg clone
     * whose ops still reference that producer (#8560, #22660).
     */
    private function nullsafeCallArgTempFedByProducer(Operand $argTemp, Op\Expr $producer): bool
    {
        if ($argTemp === $producer->result || $this->operandsReferToSameVariable($argTemp, $producer->result)) {
            return true;
        }
        if (!$argTemp instanceof Operand\Temporary) {
            return false;
        }
        foreach ($argTemp->ops ?? [] as $embedded) {
            if ($embedded === $producer) {
                return true;
            }
        }

        return false;
    }

    private function isNullsafeMethodCallArgPreludeProducer(Op\Expr $expr): bool
    {
        return $expr instanceof Op\Expr\FuncCall
            || $expr instanceof Op\Expr\NsFuncCall
            || $expr instanceof Op\Expr\Closure
            || $expr instanceof Op\Expr\New_
            || $expr instanceof Op\Expr\MethodCall
            || $expr instanceof Op\Expr\StaticCall;
    }

    /**
     * Zend zend_compile.c: nullsafe ?-> in l-value position is a compile-time fatal (#5323).
     *
     * @param Op[] $ops
     */
    private function isNullsafePropertyFetchInWriteContext(array $ops, int $index): bool
    {
        $fetch = $ops[$index] ?? null;
        if (!$fetch instanceof Op\Expr\NullsafePropertyFetch) {
            return false;
        }

        return $this->operandUsedInWriteContext($ops, $index + 1, $fetch->result);
    }

    /**
     * Zend zend_compile.c: &$nullsafeChain is a distinct compile fatal from write-context (#26638).
     *
     * php-cfg hoists NullsafePropertyFetch / NullsafeMethodCall before AssignRef; the result may
     * feed AssignRef.expr directly or via PropertyFetch / ArrayDimFetch / further nullsafe hops.
     *
     * @param Op[] $ops
     */
    private function isNullsafeOperandUsedAsAssignRefRhs(array $ops, int $startIndex, Operand $operand): bool
    {
        for ($j = $startIndex, $count = count($ops); $j < $count; ++$j) {
            $op = $ops[$j];
            if ($op instanceof Op\Expr\AssignRef && $this->operandsChainEqual($op->expr, $operand)) {
                return true;
            }
            if ($op instanceof Op\Expr\NullsafePropertyFetch
                && $this->operandsChainEqual($op->var, $operand)) {
                return $this->isNullsafeOperandUsedAsAssignRefRhs($ops, $j + 1, $op->result);
            }
            if ($op instanceof Op\Expr\PropertyFetch
                && $this->operandsChainEqual($op->var, $operand)) {
                return $this->isNullsafeOperandUsedAsAssignRefRhs($ops, $j + 1, $op->result);
            }
            if ($op instanceof Op\Expr\ArrayDimFetch
                && $this->operandsChainEqual($op->var, $operand)) {
                return $this->isNullsafeOperandUsedAsAssignRefRhs($ops, $j + 1, $op->result);
            }
        }

        return false;
    }

    /**
     * Zend zend_compile.c: Cannot take reference of a nullsafe chain (#26638).
     */
    protected function rejectNullsafeReferenceAcquisition(?Operand $expr, ?Block $block = null): void
    {
        if (null === $expr) {
            return;
        }
        if ($this->rvalueContainsNullsafeChain($expr, $block)) {
            $this->throwCompileError('Cannot take reference of a nullsafe chain');
        }
    }

    /**
     * True when AssignRef RHS resolves to a ?-> property/method (or chain thereof).
     */
    protected function rvalueContainsNullsafeChain(?Operand $operand, ?Block $block = null): bool
    {
        if (null === $operand) {
            return false;
        }
        while ($operand instanceof Temporary) {
            if ($operand->original instanceof Op\Expr\NullsafePropertyFetch
                || $operand->original instanceof Op\Expr\NullsafeMethodCall) {
                return true;
            }
            if ($operand->original instanceof Op\Expr\ArrayDimFetch) {
                return $this->rvalueContainsNullsafeChain($operand->original->var, $block);
            }
            if ($operand->original instanceof Op\Expr\PropertyFetch) {
                return $this->rvalueContainsNullsafeChain($operand->original->var, $block);
            }
            if (null === $operand->original) {
                break;
            }
            $operand = $operand->original;
        }
        if ($operand instanceof Op\Expr\NullsafePropertyFetch
            || $operand instanceof Op\Expr\NullsafeMethodCall) {
            return true;
        }
        if ($operand instanceof Op\Expr\PropertyFetch || $operand instanceof Op\Expr\ArrayDimFetch) {
            return $this->rvalueContainsNullsafeChain($operand->var, $block);
        }
        if (null !== $block && null !== $block->orig) {
            if ($this->operandIsNullsafePropertyFetchResult($operand, $block->orig->children)
                || $this->operandIsNullsafeMethodCallResult($operand, $block->orig->children)) {
                return true;
            }
            $propFetch = $this->findPropertyFetchForResult($operand, $block);
            if (null !== $propFetch) {
                return $this->rvalueContainsNullsafeChain($propFetch->var, $block);
            }
        }

        return false;
    }

    /**
     * @param Op[] $ops
     */
    private function operandIsNullsafeMethodCallResult(?Operand $operand, array $ops): bool
    {
        if (null === $operand) {
            return false;
        }
        foreach ($ops as $child) {
            if (!$child instanceof Op\Expr\NullsafeMethodCall) {
                continue;
            }
            if ($this->operandsChainEqual($child->result, $operand)) {
                return true;
            }
        }

        return false;
    }

    /**
     * php-cfg result temps for Expr ops do not chain `original` back to the producer (#5323).
     *
     * @param Op[] $ops
     */
    private function operandIsNullsafePropertyFetchResult(?Operand $operand, array $ops): bool
    {
        if (null === $operand) {
            return false;
        }
        foreach ($ops as $child) {
            if (!$child instanceof Op\Expr\NullsafePropertyFetch) {
                continue;
            }
            if ($this->operandsChainEqual($child->result, $operand)) {
                return true;
            }
        }

        return false;
    }

    protected function lvalueContainsNullsafePropertyFetch(?Operand $operand, ?Block $block = null): bool
    {
        if (null === $operand) {
            return false;
        }
        while ($operand instanceof Temporary) {
            if ($operand->original instanceof Op\Expr\NullsafePropertyFetch) {
                return true;
            }
            if ($operand->original instanceof Op\Expr\ArrayDimFetch) {
                return $this->lvalueContainsNullsafePropertyFetch($operand->original->var, $block);
            }
            if ($operand->original instanceof Op\Expr\PropertyFetch) {
                return $this->lvalueContainsNullsafePropertyFetch($operand->original->var, $block);
            }
            if (null === $operand->original) {
                break;
            }
            $operand = $operand->original;
        }
        if ($operand instanceof Op\Expr\NullsafePropertyFetch) {
            return true;
        }
        if ($operand instanceof Op\Expr\PropertyFetch) {
            return $this->lvalueContainsNullsafePropertyFetch($operand->var, $block);
        }
        if ($operand instanceof Op\Expr\ArrayDimFetch) {
            return $this->lvalueContainsNullsafePropertyFetch($operand->var, $block);
        }
        if (null !== $block && null !== $block->orig) {
            if ($this->operandIsNullsafePropertyFetchResult($operand, $block->orig->children)) {
                return true;
            }
            // php-cfg result temps omit `original`; resolve PropertyFetch producer for chains (#25560).
            $propFetch = $this->findPropertyFetchForResult($operand, $block);
            if (null !== $propFetch) {
                return $this->lvalueContainsNullsafePropertyFetch($propFetch->var, $block);
            }
        }

        return false;
    }

    /**
     * Zend zend_compile.c: nullsafe ?-> in l-value position is a compile-time fatal (#5323).
     *
     * @return never
     */
    protected function rejectNullsafeInWriteContext(?Operand $var, ?Block $block = null): void
    {
        if ($this->lvalueContainsNullsafePropertyFetch($var, $block)) {
            $this->throwCompileError("Can't use nullsafe operator in write context");
        }
        if (null !== $block && null !== $block->orig && null !== $var) {
            $dimFetch = $this->unwrapArrayDimFetch($var);
            if (null !== $dimFetch
                && $this->operandIsNullsafePropertyFetchResult($dimFetch->var, $block->orig->children)) {
                $this->throwCompileError("Can't use nullsafe operator in write context");
            }
        }
    }
}
