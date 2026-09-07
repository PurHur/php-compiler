<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler\Concern;

use PHPCfg\Op;
use PHPCfg\Operand;
use PHPTypes\Type;
use PHPCompiler\Block;
use PHPCompiler\OpCode;

/**
 * Nullsafe (`?->`) chain lowering — sync, property/dim/method compile (#36387 / #36403).
 *
 * Extracted from {@see NullsafePropertyAndMethodCompile} so gen-0 split-TU can
 * hollow a smaller Concern TU. Covers syncNullsafePropertyFetchResultToFollowingFuncCallArg
 * through compileNullsafeMethodCall. Mirrors php-src Zend/zend_compile.c nullsafe
 * compile / ZEND_AST_NULLSAFE_* lowering — move-only; no behavior change intended.
 *
 * Visibility stays protected/private where LintCompiler / call sites require it.
 */
trait NullsafeChainCompile
{
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

}
