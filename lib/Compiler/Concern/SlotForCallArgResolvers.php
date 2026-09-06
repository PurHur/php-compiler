<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;

use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * Remaining inline call-arg slot resolvers after Property/ClassConst/ClosureBind + Closure/FCC + InitArray extracts (#36387).
 *
 * Peer extracts:
 * - {@see SlotForPropertyClassConstAndClosureBindCallArgResolvers} — hoisted property / ClassConstFetch /
 *   Closure::bind New_ this / inline-new enum-case args
 * - {@see SlotForInlineClosureAndFirstClassCallableCallArgResolvers} — Closure / first-class-callable tail
 * - {@see InitArraySpreadArithmeticAndNestedInlineCallArgResolvers} — INIT_ARRAY / array-spread /
 *   inline-arithmetic / nested-subject peers
 *
 * This hub keeps assign-local helpers only.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; call-arg
 * slot wiring relies on coercion (same as FindInlineCallArgProducerSlot).
 */
trait SlotForCallArgResolvers
{
    /** `$cmp = fn(...); f(..., $cmp)` — bind named locals when php-cfg uses assign-var temps (#5644). */
    private function slotForNamedLocalFromAssignVarOperand(Operand $arg, Block $block): ?int
    {
        if (null !== $block->orig) {
            foreach ($block->orig->children as $child) {
                if (!$child instanceof Op\Expr\Assign) {
                    continue;
                }
                if (!$this->operandsReferToSameVariable($child->var, $arg)) {
                    continue;
                }
                $registered = $block->slotForNamedAssignDest($arg);
                if (null !== $registered) {
                    return $registered;
                }
                if (null !== $child->result) {
                    $namedSlot = $block->slotForOperand($child->result);
                    if (null === $namedSlot) {
                        $namedSlot = $this->slotForEmittedAssignResultSlot($block, $child);
                    }
                    if (null !== $namedSlot) {
                        return (int) $namedSlot;
                    }
                }
            }
        }
        $name = Block::resolveVariableName($arg);
        if (null !== $name && '' !== $name) {
            $paramSlot = $block->paramSlotForName($name);
            if (null !== $paramSlot) {
                return $paramSlot;
            }
            $namedBySlot = $block->slotIndexForVariableName($name);
            if (null !== $namedBySlot) {
                return (int) $namedBySlot;
            }
        }

        return null;
    }

    /** TYPE_ASSIGN arg2 for a registered assign.result temp — the live CV for by-ref sends (#12690). */
    private function slotForAssignLvalueFromResultSlot(Block $block, int $resultSlot): ?int
    {
        $mapped = $block->lvalueSlotForAssignResult($resultSlot);
        if (null !== $mapped) {
            return $mapped;
        }
        // Nested call blocks have no ASSIGN / flagged ITER_VALUE — skip O(opcodes)
        // full scans on every operand read (#36387).
        if (!$block->hasAssignResultScanCandidates()) {
            return null;
        }
        foreach ($block->opCodes as $op) {
            if (
                OpCode::TYPE_ITER_VALUE === $op->type
                && 1 === (int) ($op->arg3 ?? 0)
                && (int) $op->arg1 === $resultSlot
            ) {
                return (int) $op->arg1;
            }
            if (OpCode::TYPE_ASSIGN === $op->type && (int) $op->arg1 === $resultSlot) {
                return (int) $op->arg2;
            }
        }

        return null;
    }

    /**
     * Assign.result temps diverge from the CV after by-ref builtins; bind the lvalue (#12690, #12712, #12713).
     */
    private function resolveNamedAssignCallArgSlot(
        Block $block,
        int $namedAssignDestSlot,
        ?string $calleeName,
        int $argIndex,
        ?Operand $argProbe
    ): string {
        $lvalue = $this->slotForAssignLvalueFromResultSlot($block, $namedAssignDestSlot);
        if (null !== $lvalue) {
            return (string) $lvalue;
        }

        return (string) $namedAssignDestSlot;
    }

}
