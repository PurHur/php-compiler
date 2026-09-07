<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;

use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * ArrayDimFetch chain call-arg slots (#36387 / #36403).
 *
 * Extracted from {@see ExpressionPreludeDimFetchAndHoistedConstCallArgSlots} so
 * gen-0 split-TU can hollow a smaller Concern TU. Mirrors php-src Zend/zend_compile.c
 * FETCH_DIM_* / zend_execute.c dim-fetch edges used when php-cfg linearizes
 * `var_dump((['a'=>1])['a'])` and chained `$a[1][0]` before call-arg sends
 * (#16462, #15762, #10401). Move-only; no behavior change intended.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; call-arg
 * slot wiring relies on coercion (same as ExpressionPreludeDimFetch…).
 */
trait ArrayDimFetchCallArgSlots
{
    /**
     * var_dump((['a'=>1])['a']) — php-cfg dead arg temp; Array_ + ArrayDimFetch immediately precede call (#16462).
     */
    private function resolveInlineArrayLiteralDimFetchCallArgSlot(
        Block $block,
        Op $cfgCallOp,
        int $argIndex
    ): ?string {
        if (null === $block->orig || $argIndex < 0) {
            return null;
        }
        $callArg = property_exists($cfgCallOp, 'args') && is_array($cfgCallOp->args)
            ? ($cfgCallOp->args[$argIndex] ?? null)
            : null;
        // Embedded literals (e.g. call_user_func_array('fn', [&$x])) are not dim-fetch producers (#18015).
        if ($this->isEmbeddedCallLiteralArg($callArg)) {
            return null;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (null === $callIndex || $callIndex < 1) {
            return null;
        }
        // children[$callIndex - 1] is the TRAILING argument's fetch; handing it to every index made
        // f($x + 1, $r['k']) print "K|K" (#23354).
        if ($argIndex !== $this->trailingNonEmbeddedCallArgIndex($cfgCallOp)) {
            return null;
        }
        $fetch = $block->orig->children[$callIndex - 1] ?? null;
        if (!$fetch instanceof Op\Expr\ArrayDimFetch) {
            return null;
        }
        // Array-literal by-ref element setup (FETCH_DIM_W + ASSIGN_REF) is not a dim-read call arg (#18015).
        if ($this->isArrayDimFetchForWrite($fetch, $block)) {
            return null;
        }
        $array = $callIndex >= 2 ? ($block->orig->children[$callIndex - 2] ?? null) : null;
        if (
            !$array instanceof Op\Expr\Array_
            || !$this->operandsReferToSameVariable($fetch->var, $array->result)
        ) {
            return null;
        }
        if (null === $block->slotForOperand($fetch->result)) {
            foreach ($this->compileExpr($fetch, $block) as $op) {
                $block->addOpCode($op);
            }
        }
        $slot = $block->slotForOperand($fetch->result);

        return null !== $slot ? (string) $slot : null;
    }

    /**
     * Hoisted dim-fetch on a method-call receiver must not bind to call args (#9703).
     */
    private function arrayDimFetchFeedsMethodCallReceiver(
        Op\Expr\ArrayDimFetch $fetch,
        ?Operand $receiver
    ): bool {
        if (null === $receiver) {
            return false;
        }
        if (
            null !== $fetch->result
            && (
                $fetch->result === $receiver
                || $this->operandsReferToSameVariable($fetch->result, $receiver)
            )
        ) {
            return true;
        }
        $root = $this->unwrapOperandChain($receiver);
        if (!$root instanceof Op\Expr\ArrayDimFetch) {
            return false;
        }
        $current = $root;
        while ($current instanceof Op\Expr\ArrayDimFetch) {
            if (
                $current === $fetch
                || (
                    null !== $fetch->result
                    && null !== $current->result
                    && $this->operandsReferToSameVariable($fetch->result, $current->result)
                )
            ) {
                return true;
            }
            $current = $this->unwrapOperandChain($current->var);
        }

        return false;
    }

    /**
     * var_export($a[1][0], true) — chained hoisted dim-fetch tail feeds arg #0 only (#15762, #15945).
     *
     * @param list<Op\Expr> $producers
     */
    private function matchChainedArrayDimFetchInlineCallArgProducer(array $producers, int $argIndex): ?Op\Expr
    {
        // Nested dim chain before isset()/empty() is a quiet prelude, not the call arg (#21991).
        foreach ($producers as $producer) {
            if ($producer instanceof Op\Expr\Isset_ || $producer instanceof Op\Expr\Empty_) {
                return null;
            }
        }
        $dimFetches = array_values(array_filter(
            $producers,
            static fn (Op\Expr $producer): bool => $producer instanceof Op\Expr\ArrayDimFetch
        ));
        if (
            \count($dimFetches) < 2
            || !$this->arrayDimFetchesFormProducerChain($dimFetches)
        ) {
            return null;
        }
        if (0 === $argIndex) {
            return $dimFetches[\count($dimFetches) - 1];
        }
        $nonDimProducers = array_values(array_filter(
            $producers,
            static fn (Op\Expr $producer): bool => !$producer instanceof Op\Expr\ArrayDimFetch
        ));

        return $nonDimProducers[$argIndex - 1] ?? null;
    }

    /**
     * Consecutive hoisted dim-fetch preludes before one call arg — $a[0]['k'] (#14555).
     *
     * @param list<Op\Expr\ArrayDimFetch> $dimFetches
     */
    private function arrayDimFetchesFormProducerChain(array $dimFetches): bool
    {
        if (\count($dimFetches) < 2) {
            return false;
        }
        for ($i = 1; $i < \count($dimFetches); ++$i) {
            $inner = $dimFetches[$i];
            $outer = $dimFetches[$i - 1];
            if (
                null === $inner->var
                || null === $outer->result
                || !$this->operandsReferToSameVariable($inner->var, $outer->result)
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Operand slot map can lag TYPE_ARRAY_DIM_FETCH when php-cfg reuses result temps (#10401).
     *
     * @param list<OpCode> $opcodes
     *
     * @return int|null VM slot from the Nth dim-fetch opcode before the pending FUNCCALL_INIT
     */
    private function compiledArrayDimFetchResultSlotBeforePendingFuncCallFromOpcodes(array $opcodes, int $dimIndex = 0): ?int
    {
        $dimFetchOpcodes = [];
        for ($i = \count($opcodes) - 1; $i >= 0; --$i) {
            $op = $opcodes[$i];
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type || OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                break;
            }
            // Write lvalues (TYPE_ARRAYACCESS_OFFSET) are not dim-fetch read results (#10639).
            if (OpCode::TYPE_ARRAY_DIM_FETCH !== $op->type) {
                if ([] !== $dimFetchOpcodes) {
                    break;
                }
                continue;
            }
            array_unshift($dimFetchOpcodes, $op);
        }
        if (!isset($dimFetchOpcodes[$dimIndex])) {
            return null;
        }

        return $dimFetchOpcodes[$dimIndex]->arg1;
    }

    /**
     * @return int|null VM slot from the Nth dim-fetch opcode before the pending FUNCCALL_INIT
     */
    private function compiledArrayDimFetchResultSlotBeforePendingFuncCall(Block $block, int $dimIndex = 0): ?int
    {
        return $this->compiledArrayDimFetchResultSlotBeforePendingFuncCallFromOpcodes($block->opCodes, $dimIndex);
    }

    /**
     * Pending call-arg opcodes may hold the haystack dim-fetch before FUNCCALL_INIT lands on the block (#17000).
     *
     * @param list<OpCode> $pendingOps
     */
    private function pendingCallArgArrayDimFetchSlot(Block $block, array $pendingOps, int $dimIndex = 0): ?int
    {
        if ([] === $pendingOps) {
            return $this->compiledArrayDimFetchResultSlotBeforePendingFuncCall($block, $dimIndex);
        }

        return $this->compiledArrayDimFetchResultSlotBeforePendingFuncCallFromOpcodes(
            array_merge($block->opCodes, $pendingOps),
            $dimIndex
        );
    }

    /**
     * Last ARRAY_DIM_FETCH (read) before pending FUNCCALL_INIT — var_export($meta['k'], …) after earlier dim assigns (#18005).
     * Exclude TYPE_ARRAY_DIM_FETCH_WRITE: write lvalues must not feed call args (#10639).
     *
     * @param list<OpCode> $pendingOps
     */
    private function lastPendingCallArgArrayDimFetchSlot(Block $block, array $pendingOps): ?int
    {
        $dimFetchOpcodes = [];
        $merged = array_merge($block->opCodes, $pendingOps);
        for ($i = \count($merged) - 1; $i >= 0; --$i) {
            $op = $merged[$i];
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type || OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                break;
            }
            if (OpCode::TYPE_ARRAY_DIM_FETCH === $op->type) {
                array_unshift($dimFetchOpcodes, $op);
            }
        }
        if ([] === $dimFetchOpcodes) {
            return null;
        }
        $last = $dimFetchOpcodes[\count($dimFetchOpcodes) - 1];

        return null !== $last->arg1 ? (int) $last->arg1 : null;
    }
}
