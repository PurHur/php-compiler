<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Block;
use PHPCompiler\JIT\Variable;

/**
 * VAR_FETCH / property-fetch dest lvalue and dim-context analysis (#36387).
 *
 * Extracted from {@see LocalReleaseUnsetAndVarFetchDest}:
 * {@code foldVarFetchNameFromAssign} through {@code varFetchDestUsedAsIncDec}.
 * Move-only; no IR shape change.
 *
 * php-src: Zend/zend_execute.c ZEND_FETCH_* / ZEND_FETCH_DIM_W / ZEND_UNSET_DIM
 * destination classification (BP_VAR_W / BP_VAR_RW / BP_VAR_IS) — move-only Concern
 * extract; no new C ABI.
 */
trait VarFetchDestLvalueContext
{
    /**
     * When php-cfg assigns through a named temporary with no downstream usages, the name slot
     * may still be skipped by assignOperand; fold from the matching TYPE_ASSIGN constant (#1226).
     */
    private function foldVarFetchNameFromAssign(Block $block, int $nameSlot, Variable $nameVar): void
    {
        if (null !== $nameVar->compileTimeString) {
            return;
        }
        if (isset($block->constants[$nameSlot])) {
            $nameVar->compileTimeString = $block->constants[$nameSlot]->toString();

            return;
        }
        foreach ($block->opCodes as $prior) {
            if (OpCode::TYPE_ASSIGN !== $prior->type) {
                continue;
            }
            if (!\in_array($prior->arg2, $this->jitNamedScopeSlotAliases($block, $nameSlot), true)) {
                continue;
            }
            if (!isset($block->constants[$prior->arg3])) {
                continue;
            }
            $nameVar->compileTimeString = $block->constants[$prior->arg3]->toString();

            return;
        }
    }

    private function varFetchDestUsedAsAssignLvalue(Block $block, int $opIndex, int $destSlot): bool
    {
        // Immediate next only — later ASSIGN is often dead-temp reuse, not a write (#23986).
        $next = $block->opCodes[$opIndex + 1] ?? null;
        if (null === $next) {
            return false;
        }
        if (!OpCode::destSlotUsedAsAssignLvalue($next, $destSlot)) {
            return false;
        }
        // php-cfg folds `($o->prop . '=')` into in-place CONCAT on the ?: echo phi slot.
        // That CONCAT writes the stack phi, not the property — a write-mode fetch empties
        // virtual DOM props (nodeName) and AOT prints "=" then after= is blank (#33849).
        if (
            OpCode::TYPE_CONCAT === $next->type
            && isset($this->context->coalesceMergeSlotOperands[$destSlot])
        ) {
            return false;
        }

        return true;
    }

    /**
     * True when fetch dest is the operand of an immediate TYPE_RETURN in a by-ref function
     * (`function &f(){ return C::$x; }` → ZEND_FETCH_STATIC_PROP_W, #34727).
     */
    private function varFetchDestUsedAsByRefReturn(Block $block, int $opIndex, int $destSlot): bool
    {
        if (!$this->cfgFunctionReturnsByRef($block->func)) {
            return false;
        }
        $next = $block->opCodes[$opIndex + 1] ?? null;
        if (null === $next || OpCode::TYPE_RETURN !== $next->type) {
            return false;
        }

        return (int) $next->arg1 === $destSlot;
    }

    /**
     * True when the fetch dest is the LHS of the immediately following TYPE_ASSIGN
     * (`$this->x = $rhs`). Skip the VALUE-slot load for those writes (#32349).
     */
    private function varFetchDestUsedAsPlainAssignStore(Block $block, int $opIndex, int $destSlot): bool
    {
        $next = $block->opCodes[$opIndex + 1] ?? null;
        if (null === $next || OpCode::TYPE_ASSIGN !== $next->type) {
            return false;
        }

        return OpCode::destSlotUsedAsAssignLvalue($next, $destSlot);
    }

    /** True when fetch dest is lhs of a following compound assign ($a[$k] += …, #31991). */
    private function varFetchDestUsedAsCompoundAssign(Block $block, int $opIndex, int $destSlot): bool
    {
        $next = $block->opCodes[$opIndex + 1] ?? null;
        if (null === $next) {
            return false;
        }

        return OpCode::destSlotUsedAsCompoundAssignRead($next, $destSlot)
            || OpCode::destSlotUsedAsInPlaceCompoundAssign($next, $destSlot);
    }

    /** True when fetch dest is lhs of a following compound read ($prop += …, #30077). */
    private function varFetchDestUsedAsCompoundAssignRead(Block $block, int $opIndex, int $destSlot): bool
    {
        $next = $block->opCodes[$opIndex + 1] ?? null;
        if (null === $next) {
            return false;
        }

        return OpCode::destSlotUsedAsCompoundAssignRead($next, $destSlot);
    }

    /**
     * True when the next meaningful use of the fetch dest is TYPE_ISSET (?? / isset).
     * Those are BP_VAR_IS and must not raise typed-uninit (#29688 / #33886).
     */
    private function propertyFetchResultUsedOnlyAsIsset(Block $block, int $opIndex, int $destSlot): bool
    {
        $ops = $block->opCodes;
        $n = \count($ops);
        for ($i = $opIndex + 1; $i < $n; ++$i) {
            $next = $ops[$i];
            if (OpCode::TYPE_ISSET === $next->type) {
                return (int) $next->arg2 === $destSlot || (int) $next->arg1 === $destSlot;
            }
            // Any other consumer of this slot (echo, assign, call, …) is BP_VAR_R.
            if (
                (int) $next->arg1 === $destSlot
                || (int) ($next->arg2 ?? -1) === $destSlot
                || (int) ($next->arg3 ?? -1) === $destSlot
            ) {
                return false;
            }
        }

        return false;
    }

    /**
     * True when fetch dest is the container for `$prop[]=` / `$prop[$k]=` / unset dim (#29748).
     */
    private function varFetchDestUsedAsDimWriteContainer(Block $block, int $opIndex, int $destSlot): bool
    {
        $ops = $block->opCodes;
        $n = \count($ops);
        for ($i = $opIndex + 1; $i < $n; ++$i) {
            $next = $ops[$i];
            if (OpCode::destSlotUsedAsDimWriteContainer($next, $destSlot)) {
                return true;
            }
            if (
                OpCode::TYPE_PROPERTY_FETCH === $next->type
                || OpCode::TYPE_PROPERTY_FETCH_WRITE === $next->type
            ) {
                if ((int) $next->arg1 === $destSlot) {
                    return false;
                }
                continue;
            }
            if (
                OpCode::TYPE_ARRAY_DIM_FETCH === $next->type
                || OpCode::TYPE_ARRAY_DIM_FETCH_WRITE === $next->type
            ) {
                continue;
            }
            if (OpCode::TYPE_UNSET === $next->type) {
                continue;
            }

            return false;
        }

        return false;
    }

    /**
     * True when fetch dest is the container of a later FETCH_DIM_W (`$a[i][j]` / #34745).
     *
     * @see php-src Zend/zend_execute.c ZEND_FETCH_DIM_W (nested dimension address)
     */
    private function varFetchDestUsedAsNestedDimWriteContainer(Block $block, int $opIndex, int $destSlot): bool
    {
        $ops = $block->opCodes;
        $n = \count($ops);
        for ($i = $opIndex + 1; $i < $n; ++$i) {
            $next = $ops[$i];
            if (
                OpCode::TYPE_ARRAY_DIM_FETCH_WRITE === $next->type
                && (int) $next->arg2 === $destSlot
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Expected type for dimFetch: force TYPE_ARRAY on nested FETCH_DIM_W intermediates (#24011 / #34745)
     * and on FETCH_DIM_W prefixes that feed unset($a[i][k]) (#36380).
     *
     * CFG often leaves `$a[0]` as mixed when `$a` is a by-ref formal; without TYPE_ARRAY the outer
     * write returns a prepareIndexWrite orphan and the inner write/unset mutates a detached HT.
     *
     * php-src: Zend/zend_execute.c ZEND_FETCH_DIM_W (nested dimension address) + ZEND_UNSET_DIM.
     */
    private function dimFetchExpectedType(
        Block $block,
        int $opIndex,
        int $destSlot,
        ?\PHPTypes\Type $resultType,
        bool $forWrite
    ): ?\PHPTypes\Type {
        if (
            $forWrite
            && (
                $this->varFetchDestUsedAsNestedDimWriteContainer($block, $opIndex, $destSlot)
                || $this->varFetchDestUsedAsDimWriteContainer($block, $opIndex, $destSlot)
            )
        ) {
            return \PHPTypes\Type::fromDecl('array');
        }

        return $resultType;
    }

    /**
     * True when property fetch feeds dim RW (++/--/+=) — Zend BP_VAR_RW (#31784).
     */
    private function varFetchDestUsedAsDimRwContainer(Block $block, int $opIndex, int $destSlot): bool
    {
        $ops = $block->opCodes;
        $n = \count($ops);
        for ($i = $opIndex + 1; $i < $n; ++$i) {
            $next = $ops[$i];
            if (
                OpCode::TYPE_ARRAY_DIM_FETCH_WRITE === $next->type
                && (int) $next->arg2 === $destSlot
            ) {
                $dimSlot = (int) $next->arg1;
                for ($j = $i + 1; $j < $n; ++$j) {
                    $consumer = $ops[$j];
                    if (OpCode::dimSlotUsedAsRwOp($consumer, $dimSlot)) {
                        return true;
                    }
                    if (
                        OpCode::TYPE_ASSIGN === $consumer->type
                        && (int) $consumer->arg2 === $dimSlot
                        && (int) $consumer->arg3 !== $dimSlot
                    ) {
                        return false;
                    }
                    if ((int) $consumer->arg1 === $dimSlot) {
                        if (
                            OpCode::TYPE_PROPERTY_FETCH === $consumer->type
                            || OpCode::TYPE_PROPERTY_FETCH_WRITE === $consumer->type
                            || OpCode::TYPE_ARRAY_DIM_FETCH === $consumer->type
                            || OpCode::TYPE_ARRAY_DIM_FETCH_WRITE === $consumer->type
                        ) {
                            return false;
                        }
                    }
                }

                return false;
            }
            if (
                OpCode::TYPE_PROPERTY_FETCH === $next->type
                || OpCode::TYPE_PROPERTY_FETCH_WRITE === $next->type
            ) {
                if ((int) $next->arg1 === $destSlot) {
                    return false;
                }
                continue;
            }
            if (
                OpCode::TYPE_ARRAY_DIM_FETCH === $next->type
                || OpCode::TYPE_ARRAY_DIM_FETCH_WRITE === $next->type
            ) {
                continue;
            }
            if (OpCode::TYPE_UNSET === $next->type) {
                continue;
            }

            return false;
        }

        return false;
    }

    private function varFetchDestUsedAsIncDec(Block $block, int $opIndex, int $destSlot): bool
    {
        $next = $block->opCodes[$opIndex + 1] ?? null;
        if (null === $next) {
            return false;
        }

        return \in_array($next->type, [
            OpCode::TYPE_PRE_INC,
            OpCode::TYPE_POST_INC,
            OpCode::TYPE_PRE_DEC,
            OpCode::TYPE_POST_DEC,
        ], true) && $next->arg3 === $destSlot;
    }
}
