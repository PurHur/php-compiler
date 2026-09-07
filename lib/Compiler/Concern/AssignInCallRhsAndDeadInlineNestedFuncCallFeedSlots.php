<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;

use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * Assign-in-call RHS wiring + dead-inline nested FuncCall feed / distinct-temp
 * helpers (#36387 / #36403).
 *
 * Extracted from {@see AdjacentNestedCallArgSlots} so gen-0 split-TU can hollow a
 * smaller Concern TU. Parent keeps resolveAdjacentNestedFuncCallArgSlot and the
 * adjacent-producer / prelude-separator peers.
 *
 * Covers {@see resolveAdjacentAssignExprCallArgSlot},
 * {@see resolveAssignInCallRhsCallArgSlot},
 * {@see isAssignInCallFromPrecedingProducer},
 * {@see slotForEmittedAssignRhsSlot},
 * {@see nestedFuncCallFeedsDeadInlineCallArgZero},
 * {@see nestedFuncCallFeedsDeadInlineCallArg},
 * {@see callArgsAreDistinctInlineTemporaries},
 * {@see hoistedCallArgsAreDistinctInlineTemporaries}, and
 * {@see callArgUsesInlineArrayNotInHoistedProducers}.
 *
 * Mirrors php-src Zend/zend_execute.c ZEND_SEND_* / assign-in-call operand
 * wiring (ext/standard/pack.c strlen(($q=pack(...))) shape) — move-only.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; call-arg
 * slot wiring relies on coercion (same as RewireInlineCallArgSendSlots).
 */
trait AssignInCallRhsAndDeadInlineNestedFuncCallFeedSlots
{
    /**
     * strlen(($q = pack(...))) — php-cfg dead arg temp vs assign.result (#11365, ext/standard/pack.c).
     */
    private function resolveAdjacentAssignExprCallArgSlot(
        Block $block,
        Op $cfgCallOp,
        int $argIndex
    ): ?string {
        return $this->resolveAssignInCallRhsCallArgSlot($block, $cfgCallOp, $argIndex);
    }

    /**
     * Hoisted assign-in-call before a by-ref builtin — wire the RHS value, not the named lvalue (#15151).
     *
     * `array_multisort([..], $labels = [..])` is lowered as Array_, Array_, Assign, FuncCall; Zend couples
     * sort using the literal arrays but does not write sorted order back through assign-in-call operands.
     */
    private function resolveAssignInCallRhsCallArgSlot(
        Block $block,
        Op $cfgCallOp,
        int $argIndex,
        ?Operand $arg = null
    ): ?string {
        if (null === $block->orig || !property_exists($cfgCallOp, 'args') || !is_array($cfgCallOp->args)) {
            return null;
        }
        $callArg = $cfgCallOp->args[$argIndex] ?? $arg;
        if (
            !$this->callArgIsDeadInlineTemporary($callArg)
            && !$this->callArgIsAssignInCallOperand($callArg)
        ) {
            return null;
        }
        $callIndex = $this->cfgCallOpIndex($block, $cfgCallOp);
        if (!\is_int($callIndex) || $callIndex < 1) {
            return null;
        }
        $prev = $block->orig->children[$callIndex - 1] ?? null;
        if (!$prev instanceof Op\Expr\Assign || null === $prev->result) {
            return null;
        }
        if (
            0 === $argIndex
            && !$this->callArgIsAssignInCallOperand($callArg)
            && !$this->operandsReferToSameVariable($callArg, $prev->result)
            && !$this->isAssignInCallFromPrecedingProducer($block, $prev)
        ) {
            return null;
        }
        $rhsExpr = $prev->expr;
        if (
            $callIndex > 1
            && ($block->orig->children[$callIndex - 2] ?? null) instanceof Op\Expr\BinaryOp\BitwiseOr
        ) {
            $rhsExpr = $block->orig->children[$callIndex - 2];
        } elseif (
            $callIndex > 1
            && ($block->orig->children[$callIndex - 2] ?? null) instanceof Op\Expr\BinaryOp\BitwiseAnd
        ) {
            $rhsExpr = $block->orig->children[$callIndex - 2];
        } elseif (
            $callIndex > 1
            && ($block->orig->children[$callIndex - 2] ?? null) instanceof Op\Expr\BinaryOp\BitwiseXor
        ) {
            $rhsExpr = $block->orig->children[$callIndex - 2];
        }
        if ($rhsExpr instanceof Operand) {
            $rhsOperand = $rhsExpr;
        } elseif (property_exists($rhsExpr, 'result') && $rhsExpr->result instanceof Operand) {
            $rhsOperand = $rhsExpr->result;
        } else {
            $slot = $this->slotForEmittedAssignRhsSlot($block, $prev);

            return null !== $slot ? (string) $slot : null;
        }
        if (null === $block->slotForOperand($rhsOperand)) {
            if ($rhsExpr instanceof Op) {
                foreach ($this->compileExpr($rhsExpr, $block) as $op) {
                    $block->addOpCode($op);
                }
            }
        }
        $slot = $block->slotForOperand($rhsOperand);
        if (null === $slot) {
            $slot = $this->slotForEmittedAssignRhsSlot($block, $prev);
        }
        if (null === $slot) {
            return null;
        }

        return (string) $slot;
    }

    /**
     * `strlen(($q = pack(...)))` — assign.expr references the FuncCall sibling immediately before Assign (#16273, re-#11365).
     */
    private function isAssignInCallFromPrecedingProducer(Block $block, Op\Expr\Assign $assign): bool
    {
        if (null === $block->orig) {
            return false;
        }
        $assignIndex = null;
        foreach ($block->orig->children as $i => $child) {
            if ($child === $assign) {
                $assignIndex = $i;
                break;
            }
        }
        if (null === $assignIndex || $assignIndex < 1) {
            return false;
        }
        $before = $block->orig->children[$assignIndex - 1] ?? null;
        if (
            !$before instanceof Op\Expr\FuncCall
            && !$before instanceof Op\Expr\NsFuncCall
            && !$before instanceof Op\Expr\StaticCall
            && !$before instanceof Op\Expr\MethodCall
            && !$before instanceof Op\Expr\New_
        ) {
            return false;
        }
        if (null === $before->result) {
            return false;
        }

        return $this->operandsReferToSameVariable($assign->expr, $before->result);
    }

    /** TYPE_ASSIGN arg3 for a registered assign.expr temp (#15151). */
    private function slotForEmittedAssignRhsSlot(Block $block, Op\Expr\Assign $assign): ?int
    {
        if (null === $block->orig) {
            return null;
        }
        $assignOrdinal = 0;
        $targetOrdinal = null;
        foreach ($block->orig->children as $child) {
            if ($child instanceof Op\Expr\Assign) {
                if ($child === $assign) {
                    $targetOrdinal = $assignOrdinal;
                    break;
                }
                ++$assignOrdinal;
            }
        }
        if (null === $targetOrdinal) {
            return null;
        }
        $seen = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ASSIGN !== $op->type) {
                continue;
            }
            if ($seen === $targetOrdinal) {
                return (int) $op->arg3;
            }
            ++$seen;
        }

        return null;
    }

    /**
     * tempnam(sys_get_temp_dir(), E::A) — nested FuncCall feeds arg #0; trailing enum is arg #1 (#10303, #16558).
     */
    private function nestedFuncCallFeedsDeadInlineCallArgZero(Block $block, Op $callOp, int $argIndex): bool
    {
        if (0 !== $argIndex || null === $block->orig) {
            return false;
        }
        $callIndex = $this->cfgCallOpIndex($block, $callOp);
        if (null === $callIndex || $callIndex < 2) {
            return false;
        }
        $priorProducer = $block->orig->children[$callIndex - 2] ?? null;
        // var_export(require_once $f, true) — Include_/Eval_ + hoisted true (#25852).
        if (
            ($priorProducer instanceof Op\Expr\Include_ || $priorProducer instanceof Op\Expr\Eval_)
            && $this->isHoistedScalarConstFetchImmediatelyBeforeCall(
                $block->orig->children[$callIndex - 1] ?? null
            )
            && 2 === \count($callOp->args ?? [])
        ) {
            return true;
        }
        if (
            !($priorProducer instanceof Op\Expr\FuncCall || $priorProducer instanceof Op\Expr\NsFuncCall)
            || !$this->nestedFuncCallProducerSeparatedBySkippablePreludesOnly(
                $callIndex - 2,
                $callIndex,
                $block->orig->children
            )
        ) {
            return false;
        }

        return 2 === \count($callOp->args ?? []);
    }

    /**
     * unpack('i', pack(...), E::A) — nested FuncCall feeds a middle dead-temp arg, not enum (#8866).
     */
    private function nestedFuncCallFeedsDeadInlineCallArg(Block $block, Op $callOp, int $argIndex): bool
    {
        if (null === $block->orig) {
            return false;
        }
        $callIndex = $this->cfgCallOpIndex($block, $callOp);
        if (null === $callIndex) {
            return false;
        }
        $nested = $this->nestedFuncCallProducerBeforeTrailingConstFetchPreludes(
            $callOp,
            $callIndex,
            $block->orig->children
        );
        if (
            !($nested instanceof Op\Expr\FuncCall || $nested instanceof Op\Expr\NsFuncCall)
        ) {
            return false;
        }
        $nestedIndex = array_search($nested, $block->orig->children, true);
        if (!\is_int($nestedIndex)) {
            return false;
        }
        $targetArg = $this->siblingMultiArgFuncCallProducerTargetArgIndex(
            $nestedIndex,
            $callIndex,
            $block->orig->children
        );

        return null !== $targetArg && $targetArg === $argIndex;
    }

    /** Stmt-level side-effect builtins — not hoisted multi-arg producers (#16451, #16480). */

    /**
     * php-cfg dead multi-arg temps with no dataflow to hoisted producers (#9463, #9351).
     *
     * @param list<Operand> $callArgs
     */
    private function callArgsAreDistinctInlineTemporaries(array $callArgs): bool
    {
        if (count($callArgs) < 2) {
            return false;
        }
        foreach ($callArgs as $callArg) {
            if (!$this->callArgIsDeadInlineTemporary($callArg)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Dead inline temps among hoisted call args only — trailing embedded literals allowed (#18613).
     *
     * file_get_contents('data://text/plain,'.$p, false, null, 3, 4) hoists Concat + ConstFetch siblings.
     *
     * @param list<Operand> $callArgs
     */
    private function hoistedCallArgsAreDistinctInlineTemporaries(array $callArgs): bool
    {
        $hoistedCount = 0;
        foreach ($callArgs as $callArg) {
            if ($this->isEmbeddedCallLiteralArg($callArg)) {
                continue;
            }
            if (!$this->callArgIsDeadInlineTemporary($callArg)) {
                return false;
            }
            ++$hoistedCount;
        }

        return $hoistedCount >= 2;
    }

    /**
     * Assign-in-arg Array_ is compiled via findInlineArrayProducerForCallArg but omitted from hoisted producers (#15154).
     *
     * @param list<Op\Expr> $producers
     */
    private function callArgUsesInlineArrayNotInHoistedProducers(
        Operand $arg,
        Block $block,
        Op $cfgCallOp,
        int $argIndex,
        array $producers
    ): bool {
        if (!$this->callArgIsDeadInlineTemporary($arg) || !$this->callArgOperandExpectsArrayProducer($arg)) {
            return false;
        }
        if (null === $block->orig) {
            return false;
        }
        foreach ($producers as $producer) {
            if (!$producer instanceof Op\Expr\Array_) {
                continue;
            }
            $callArg = $cfgCallOp->args[$argIndex] ?? $arg;
            if (
                null !== $producer->result
                && (
                    $this->operandsReferToSameVariable($producer->result, $callArg)
                    || $this->operandsReferToSameVariable($producer->result, $arg)
                )
            ) {
                return false;
            }
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (null === $callIndex) {
            return false;
        }
        for ($i = $callIndex - 1; $i >= 0; --$i) {
            $child = $block->orig->children[$i];
            if ($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall) {
                break;
            }
            if ($child instanceof Op\Expr\BinaryOp\Plus) {
                return false;
            }
            if ($child instanceof Op\Expr\Array_) {
                return !\in_array($child, $producers, true);
            }
            if ($child instanceof Op\Expr\Assign) {
                $prior = $block->orig->children[$i - 1] ?? null;
                if ($prior instanceof Op\Expr\Array_) {
                    return !\in_array($prior, $producers, true);
                }
                break;
            }
        }

        return false;
    }

}
