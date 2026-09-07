<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;

use PHPCfg\Op;
use PHPCfg\Operand;
use PHPTypes\Type;

/**
 * Expression-prelude and pending inline-call result call-arg slots (#36403 / #36387).
 *
 * Extracted from {@see \PHPCompiler\Compiler} so the hub keeps shrinking toward
 * host-CFG split-TU emit under SPINE_CHUNK (gen-0 <30m).
 *
 * Covers preceding expression-prelude result slots, pending FUNC_CALL / EVAL
 * call-arg wiring, and inline Array_ producer slots.
 * Chained ArrayDimFetch / pending dim-fetch slots live in {@see ArrayDimFetchCallArgSlots}.
 * Hoisted ClassConstFetch dead-prelude matching lives in {@see HoistedConstCallArgSlots}.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; call-arg
 * slot wiring relies on coercion (same as ExactHoistedAndInlineNewCallArgProducers).
 */
trait ExpressionPreludeDimFetchAndHoistedConstCallArgSlots
{
    /**
     * var_export($text->data) / var_export($expr instanceof T) — immediate PropertyFetch/compare prelude (#17540).
     */
    private function resolvePrecedingExpressionPreludeCallArgSlot(
        Operand $arg,
        Block $block,
        ?Op $cfgCallOp,
        int $argIndex
    ): ?string {
        if (null === $block->orig || null === $cfgCallOp || 0 !== $argIndex) {
            return null;
        }
        // The prelude read below is children[$callIndex - 1] — the TRAILING argument's producer.
        // Handing it to arg #0 of a multi-argument call is exactly backwards: f($x + 1, $r['k'])
        // printed "K|K" (#23354). Only valid when arg #0 IS the trailing non-embedded argument.
        if (0 !== $this->trailingNonEmbeddedCallArgIndex($cfgCallOp)) {
            return null;
        }
        $callArg = $cfgCallOp->args[$argIndex] ?? $arg;
        if (!$this->callArgIsDeadInlineTemporary($callArg)) {
            return null;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (null === $callIndex || $callIndex < 1) {
            return null;
        }
        $prelude = $block->orig->children[$callIndex - 1] ?? null;
        if (
            !$prelude instanceof Op\Expr
            || !$this->isImmediateVarExportExpressionPrelude($prelude)
            || null === $prelude->result
        ) {
            return null;
        }
        // Multi-arg ctor/call with trailing scalar/flag prelude — do not bind to arg #0 (#19735, #19738).
        // Covers BitwiseOr, Plus/Mul/shifts, UnaryMinus, Cast, etc. (isTrailingInlineNewCtorOptionPrelude).
        if (
            $this->isTrailingInlineNewCtorOptionPrelude($prelude)
            && 0 !== $this->trailingNonEmbeddedCallArgIndex($cfgCallOp)
        ) {
            return null;
        }
        $opcodeSlot = $this->compiledExpressionPreludeResultSlotBeforePendingFuncCall($block, $prelude);
        if (null === $opcodeSlot) {
            foreach ($this->compileExpr($prelude, $block) as $op) {
                $block->addOpCode($op);
            }
            $opcodeSlot = $this->compiledExpressionPreludeResultSlotBeforePendingFuncCall($block, $prelude);
        }
        if (null !== $opcodeSlot) {
            return (string) $opcodeSlot;
        }
        $slot = $block->slotForOperand($prelude->result);
        if (null !== $slot) {
            $opcodeSlot = $this->compiledExpressionPreludeResultSlotBeforePendingFuncCall($block, $prelude);
            if (null !== $opcodeSlot && $opcodeSlot !== $slot) {
                return (string) $opcodeSlot;
            }

            return (string) $slot;
        }

        return null;
    }

    /**
     * Operand slot map can lag TYPE_PROPERTY_FETCH / TYPE_INSTANCEOF when php-cfg reuses dead temps (#17540).
     */
    private function compiledExpressionPreludeResultSlotBeforePendingFuncCall(
        Block $block,
        Op\Expr $prelude
    ): ?int {
        $expectedTypes = match (true) {
            $prelude instanceof Op\Expr\PropertyFetch => [OpCode::TYPE_PROPERTY_FETCH],
            $prelude instanceof Op\Expr\NullsafePropertyFetch => [OpCode::TYPE_NULLSAFE],
            $prelude instanceof Op\Expr\StaticPropertyFetch => [OpCode::TYPE_STATIC_PROPERTY_FETCH],
            $prelude instanceof Op\Expr\ArrayDimFetch => [OpCode::TYPE_ARRAY_DIM_FETCH, OpCode::TYPE_ARRAY_DIM_FETCH_WRITE],
            $prelude instanceof Op\Expr\InstanceOf_ => [OpCode::TYPE_INSTANCEOF],
            $prelude instanceof Op\Expr\Cast => [
                OpCode::TYPE_CAST_ARRAY,
                OpCode::TYPE_CAST_BOOL,
                OpCode::TYPE_CAST_FLOAT,
                OpCode::TYPE_CAST_INT,
                OpCode::TYPE_CAST_OBJECT,
                OpCode::TYPE_CAST_STRING,
                OpCode::TYPE_CAST_UNSET,
                OpCode::TYPE_CAST_VOID,
            ],
            $prelude instanceof Op\Expr\BooleanNot => [OpCode::TYPE_BOOLEAN_NOT],
            $prelude instanceof Op\Expr\BitwiseNot => [OpCode::TYPE_BITWISE_NOT],
            $prelude instanceof Op\Expr\UnaryMinus => [OpCode::TYPE_UNARY_MINUS],
            $prelude instanceof Op\Expr\UnaryPlus => [OpCode::TYPE_UNARY_PLUS],
            // Typed property ++/-- inline call-arg (#26491 / re-#10123, zend_execute.c).
            $prelude instanceof Op\Expr\PostInc => [OpCode::TYPE_POST_INC],
            $prelude instanceof Op\Expr\PreInc => [OpCode::TYPE_PRE_INC],
            $prelude instanceof Op\Expr\PostDec => [OpCode::TYPE_POST_DEC],
            $prelude instanceof Op\Expr\PreDec => [OpCode::TYPE_PRE_DEC],
            $this->isComparisonInlineCallArgProducer($prelude) => [OpCode::TYPE_IDENTICAL, OpCode::TYPE_NOT_IDENTICAL, OpCode::TYPE_EQUAL, OpCode::TYPE_NOT_EQUAL, OpCode::TYPE_SPACESHIP, OpCode::TYPE_SMALLER, OpCode::TYPE_GREATER, OpCode::TYPE_SMALLER_OR_EQUAL, OpCode::TYPE_GREATER_OR_EQUAL, OpCode::TYPE_INSTANCEOF, OpCode::TYPE_IN],
            $this->isArithmeticInlineCallArgProducer($prelude) => [
                OpCode::TYPE_BITWISE_AND,
                OpCode::TYPE_BITWISE_OR,
                OpCode::TYPE_BITWISE_XOR,
                OpCode::TYPE_PLUS,
                OpCode::TYPE_MINUS,
                OpCode::TYPE_MUL,
                OpCode::TYPE_DIV,
                OpCode::TYPE_MODULO,
                OpCode::TYPE_POW,
                OpCode::TYPE_SHIFT_LEFT,
                OpCode::TYPE_SHIFT_RIGHT,
            ],
            default => [],
        };
        if ([] === $expectedTypes) {
            return null;
        }
        for ($i = \count($block->opCodes) - 1; $i >= 0; --$i) {
            $op = $block->opCodes[$i];
            if (\in_array($op->type, $expectedTypes, true)) {
                return $op->arg1;
            }
            if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                break;
            }
            // Pending callee INIT/ARG_SEND during compileCallArgSends — skip to hoisted prelude (#14467, #17540).
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type || OpCode::TYPE_ARG_SEND === $op->type) {
                continue;
            }
        }

        return null;
    }

    /**
     * Nested inline consumer — last FUNCCALL_EXEC_RETURN before trailing FUNCCALL_INIT (#14555).
     */
    private function slotForLastEmittedInlineCallResultBeforePendingFuncCall(Block $block): ?int
    {
        return $block->lastFunccallExecReturnSlot();
    }

    /**
     * Pending call-arg opcodes — nested FUNCCALL_EXEC_RETURN not yet on the block (#9292).
     *
     * @param list<OpCode> $opcodes
     */
    private function slotForLastPendingInlineCallResultBeforeFuncCallInit(array $opcodes): ?int
    {
        for ($i = \count($opcodes) - 1; $i >= 0; --$i) {
            if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $opcodes[$i]->type) {
                return (int) $opcodes[$i]->arg1;
            }
            if (OpCode::TYPE_FUNCCALL_INIT === $opcodes[$i]->type) {
                break;
            }
        }

        return null;
    }

    /**
     * Last FUNCCALL_EXEC_RETURN on block plus pending call-arg opcodes (#10474, is_array(file(..., FLAGS))).
     *
     * @param list<OpCode> $pendingOps
     */
    private function slotForLastInlineFuncCallExecReturn(Block $block, array $pendingOps = []): ?int
    {
        $last = $block->lastFunccallExecReturnSlot();
        foreach ($pendingOps as $op) {
            if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type && null !== $op->arg1) {
                $last = (int) $op->arg1;
            }
        }

        return $last;
    }

    /**
     * php-cfg dead call-arg temp for inline eval() — TYPE_EVAL producer slot (#10661, zif_eval).
     */
    private function resolvePrecedingEvalCallArgSlot(
        Operand $arg,
        Block $block,
        ?Op $cfgCallOp,
        int $argIndex
    ): ?string {
        if (null === $block->orig || null === $cfgCallOp) {
            return null;
        }
        $callSite = $this->findCfgCallSiteForArg($block->orig->children, $arg, $cfgCallOp);
        if (null === $callSite) {
            return null;
        }
        [$callOp, $matchedIndex] = $callSite;
        if ($matchedIndex !== $argIndex) {
            return null;
        }
        $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $callOp);
        $matched = $this->matchInlineCallArgProducer($producers, $callOp->args ?? [], $argIndex, $callOp);
        if (!$matched instanceof Op\Expr\Eval_) {
            return null;
        }
        for ($i = \count($block->opCodes) - 1; $i >= 0; --$i) {
            $op = $block->opCodes[$i];
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                break;
            }
            if (OpCode::TYPE_EVAL === $op->type) {
                return (string) $op->arg1;
            }
        }
        if (null === $block->slotForOperand($matched->result)) {
            foreach ($this->compileExpr($matched, $block) as $op) {
                $block->addOpCode($op);
            }
        }
        $slot = $block->slotForOperand($matched->result);

        return null !== $slot ? (string) $slot : null;
    }

    protected function operandHasObjectType(Operand $operand): bool
    {
        $operand = $this->unwrapOperandChain($operand);

        return null !== $operand->type && Type::TYPE_OBJECT === $operand->type->type;
    }


    private function slotForInlineArrayExpr(Block $block, ?Op\Expr\Array_ $arrayExpr): ?string
    {
        if (!$arrayExpr instanceof Op\Expr\Array_) {
            return null;
        }
        if (null === $block->slotForOperand($arrayExpr->result)) {
            foreach ($this->compileArrayLiteral($arrayExpr, $block) as $op) {
                $block->addOpCode($op);
            }
        }
        $slot = $block->slotForOperand($arrayExpr->result);

        return null !== $slot ? (string) $slot : null;
    }

    /** Outermost hoisted Array_ stmt immediately before a cfg FuncCall (#11485). */
    private function resolveInlineArrayProducerSlotBeforeCfgCall(Op $callOp, Block $block): ?string
    {
        $arrayExpr = $this->inlineArrayProducerImmediatelyBeforeCfgCall($callOp, $block);
        if (!$arrayExpr instanceof Op\Expr\Array_) {
            return null;
        }
        if (null === $block->slotForOperand($arrayExpr->result)) {
            foreach ($this->compileExpr($arrayExpr, $block) as $op) {
                $block->addOpCode($op);
            }
        }
        $slot = $block->slotForOperand($arrayExpr->result);

        return null !== $slot ? (string) $slot : null;
    }
}
