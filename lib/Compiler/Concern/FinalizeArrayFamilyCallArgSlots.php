<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;

use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * Finalize array-family / filter_input call-arg slots (#36387 / #36403).
 *
 * Extracted from {@see \PHPCompiler\Compiler} so the hub can shrink toward
 * host-CFG split-TU emit under SPINE_CHUNK (gen-0 <30m).
 *
 * Covers {@see finalizeFilterInputCallArgSlot}, {@see finalizeArrayMergeFamilyCallArgSlot},
 * and the INIT_ARRAY / FUNCCALL_EXEC_RETURN ordinal helpers they share.
 * array_combine / array_column last-chance slots live in
 * {@see FinalizeArrayCombineColumnCallArgSlots}.
 * Named-local preference + adjacent assign emit live in
 * {@see PreferNamedLocalAndAdjacentAssignCallArgSlots}.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; call-arg
 * slot wiring relies on coercion (same as CompileCallArgSends / EchoCoalesceCallArgCompile).
 */
trait FinalizeArrayFamilyCallArgSlots
{
    /**
     * Last-chance ARG_SEND slots for filter_input() hoisted ConstFetch / nested options (#15194).
     *
     * @param list<OpCode> $pendingSends
     */
    private function finalizeFilterInputCallArgSlot(
        Block $block,
        ?Op $cfgCallOp,
        int $argIndex,
        array &$pendingSends = []
    ): ?string {
        if (null === $cfgCallOp || null === $block->orig) {
            return null;
        }
        if ('filter_input' !== strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '')) {
            return null;
        }
        if (3 === $argIndex) {
            $optionsArg = $cfgCallOp->args[3] ?? null;
            if (
                $optionsArg instanceof Operand
                && $this->callArgIsDeadInlineTemporary($optionsArg)
                && $this->callArgOperandExpectsArrayProducer($optionsArg)
            ) {
                return $this->resolveOutermostInitArraySlotBeforePendingFuncCall($block, $pendingSends);
            }

            return null;
        }
        if (0 !== $argIndex && 2 !== $argIndex) {
            return null;
        }
        $hoisted = [];
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (null === $callIndex) {
            return null;
        }
        for ($i = $callIndex - 1; $i >= 0; --$i) {
            $child = $block->orig->children[$i];
            if ($child instanceof Op\Expr\ConstFetch) {
                array_unshift($hoisted, $child);
                continue;
            }
            if ($child instanceof Op\Expr\Array_) {
                continue;
            }
            if ($child instanceof Op\Expr\Assign) {
                break;
            }
            break;
        }
        $constFetches = array_values(array_filter(
            $hoisted,
            static fn (Op\Expr $producer): bool => $producer instanceof Op\Expr\ConstFetch
        ));
        $target = match ($argIndex) {
            0 => $constFetches[0] ?? null,
            2 => $constFetches[1] ?? ($constFetches[0] ?? null),
            default => null,
        };
        if (!$target instanceof Op\Expr\ConstFetch) {
            return null;
        }
        $folded = $this->tryFoldGlobalConstFetch($target);
        if (null !== $folded) {
            return (string) $block->registerConstant(new Operand\Temporary(), $folded);
        }
        $slot = $block->slotForOperand($target->result);
        if (null !== $slot) {
            return (string) $slot;
        }
        foreach ($this->compileExpr($target, $block) as $op) {
            $pendingSends[] = $op;
        }
        $slot = $block->slotForOperand($target->result);

        return null !== $slot ? (string) $slot : null;
    }

    /**
     * Last keyed INIT_ARRAY slot for nested inline array call args (#11485).
     *
     * @param list<OpCode> $pendingSends
     */
    private function resolveOutermostInitArraySlotBeforePendingFuncCall(
        Block $block,
        array $pendingSends = []
    ): ?string {
        $outerSlot = null;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_INIT_ARRAY === $op->type && null !== $op->arg1 && null !== $op->arg3) {
                $outerSlot = (string) $op->arg1;
            }
        }
        if (null !== $outerSlot) {
            return $outerSlot;
        }
        $scanOps = array_merge($block->opCodes, $pendingSends);
        for ($i = \count($scanOps) - 1; $i >= 0; --$i) {
            $op = $scanOps[$i];
            if (OpCode::TYPE_INIT_ARRAY === $op->type && null !== $op->arg1) {
                return (string) $op->arg1;
            }
        }

        return null;
    }

    /**
     * Nth FUNCCALL_EXEC_RETURN slot including pending call-arg producer ops (#16097).
     *
     * @param list<OpCode> $pendingOps
     */
    private function slotForFuncCallExecReturnOrdinal(
        Block $block,
        int $producerOrdinal,
        array $pendingOps = []
    ): ?string {
        if ($producerOrdinal < 0) {
            return null;
        }
        $execReturnSlots = $block->funccallExecReturnSlots();
        if ([] !== $pendingOps) {
            foreach ($pendingOps as $op) {
                if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type && null !== $op->arg1) {
                    $execReturnSlots[] = (int) $op->arg1;
                }
            }
        }

        return isset($execReturnSlots[$producerOrdinal])
            ? (string) $execReturnSlots[$producerOrdinal]
            : null;
    }

    /**
     * Last-chance ARG_SEND slot for array_merge*(array_keys(...), [...]) sibling producers (#12450, #13704, #17781).
     *
     * @param list<OpCode> $sends
     */
    private function finalizeArrayMergeFamilyCallArgSlot(
        Block $block,
        Op $cfgCallOp,
        int $argIndex,
        array &$sends
    ): ?string {
        $callee = strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '');
        if (!\in_array(
            $callee,
            ['array_merge', 'array_merge_recursive', 'array_replace', 'array_replace_recursive'],
            true
        )) {
            return null;
        }
        if (\count($cfgCallOp->args ?? []) < 2 || null === $block->orig) {
            return null;
        }
        $producers = $this->arrayMergeFamilyInlineProducersForCfgCall(
            $block->orig->children,
            $cfgCallOp
        );
        $matched = $this->matchArrayMergeFamilyFullInlineCallArgProducer(
            $producers,
            $argIndex,
            \count($cfgCallOp->args ?? []),
            $cfgCallOp->args ?? []
        );
        if (null === $matched) {
            $matched = $this->matchArrayMergeFuncCallAndArrayInlineProducers($producers, $argIndex);
        }
        if (!$matched instanceof Op\Expr) {
            return null;
        }
        if ($matched instanceof Op\Expr\FuncCall || $matched instanceof Op\Expr\NsFuncCall) {
            $funcOrdinal = 0;
            foreach ($producers as $producer) {
                if ($producer === $matched) {
                    break;
                }
                if ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall) {
                    ++$funcOrdinal;
                }
            }
            $execSlot = $this->slotForFuncCallExecReturnOrdinal($block, $funcOrdinal, $sends);
            if (null !== $execSlot) {
                return $execSlot;
            }
            if (null === $block->slotForOperand($matched->result)) {
                foreach ($this->compileExpr($matched, $block) as $op) {
                    $sends[] = $op;
                }
            }
            $execSlot = $this->slotForFuncCallExecReturnOrdinal($block, $funcOrdinal, $sends);
            if (null !== $execSlot) {
                return $execSlot;
            }
            $slot = $block->slotForOperand($matched->result);
            if (null !== $slot) {
                return (string) $slot;
            }

            return null;
        }
        if (null === $block->slotForOperand($matched->result)) {
            if ($matched instanceof Op\Expr\Array_) {
                foreach ($this->compileArrayLiteral($matched, $block) as $op) {
                    $sends[] = $op;
                }
            } else {
                foreach ($this->compileExpr($matched, $block) as $op) {
                    $sends[] = $op;
                }
            }
        }
        $slot = $block->slotForOperand($matched->result);
        if (null !== $slot) {
            return (string) $slot;
        }

        return null;
    }

}
