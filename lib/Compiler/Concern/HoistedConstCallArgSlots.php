<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;

use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * Hoisted scalar/enum ClassConstFetch dead-prelude call-arg slots (#36387 / #36403).
 *
 * Extracted from {@see ExpressionPreludeDimFetchAndHoistedConstCallArgSlots} so
 * gen-0 split-TU can hollow a smaller Concern TU. Mirrors php-src Zend/zend_compile.c
 * class-constant fetch / call-arg compile edges used when php-cfg linearizes dead
 * `E::A; E::B; foo($a, $b)` stmts (#5933, #5858). Move-only; no behavior change intended.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; call-arg
 * slot wiring relies on coercion (same as ExpressionPreludeDimFetch…).
 */
trait HoistedConstCallArgSlots
{
    /**
     * php-cfg may linearize `E::A; E::B; foo($a, $b)` into dead ClassConstFetch stmts
     * plus distinct call-arg temporaries with no dataflow edge (#5933, #5858).
     *
     * @param list<Op> $cfgChildren
     *
     * @return list<Op\Expr\ClassConstFetch>
     */
    private function precedingClassConstFetchesBeforeCfgOp(array $cfgChildren, Op $callOp): array
    {
        $callIndex = $this->cfgCallOpIndexInChildren($cfgChildren, $callOp);
        if (null === $callIndex) {
            return [];
        }
        $fetches = [];
        for ($i = $callIndex - 1; $i >= 0; --$i) {
            $child = $cfgChildren[$i];
            if ($child instanceof Op\Expr\ClassConstFetch) {
                array_unshift($fetches, $child);

                continue;
            }
            if ($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall) {
                break;
            }
            if ($child instanceof Op\Expr && $this->isInlineExprCallArgProducer($child)) {
                continue;
            }
            break;
        }

        return $fetches;
    }

    /**
     * Call-arg slot mapping must skip enum case fetches that only feed `Case::class` (#9426).
     *
     * @param list<Op\Expr\ClassConstFetch> $fetches
     * @param list<Op> $cfgChildren
     *
     * @return list<Op\Expr\ClassConstFetch>
     */
    private function dropEnumCaseFetchesConsumedByCaseClassPseudoConst(
        array $fetches,
        array $cfgChildren,
        Op $beforeOp,
        Block $block
    ): array {
        if ([] === $fetches) {
            return $fetches;
        }
        $stopIndex = null;
        foreach ($cfgChildren as $i => $child) {
            if ($child === $beforeOp) {
                $stopIndex = $i;
                break;
            }
        }
        if (null === $stopIndex) {
            return $fetches;
        }
        $filtered = [];
        foreach ($fetches as $fetch) {
            if (!$this->isCompileTimeEnumCaseClassConstFetch($fetch, $block)) {
                $filtered[] = $fetch;
                continue;
            }
            $consumed = false;
            for ($i = 0; $i < $stopIndex; ++$i) {
                $child = $cfgChildren[$i];
                if (!$child instanceof Op\Expr\ClassConstFetch) {
                    continue;
                }
                $pseudoName = $this->staticNameFromOperand($child->name);
                if (null === $pseudoName || 'class' !== strtolower($pseudoName)) {
                    continue;
                }
                if ($this->operandsReferToSameVariable($child->class, $fetch->result)) {
                    $consumed = true;
                    break;
                }
            }
            if (!$consumed) {
                $filtered[] = $fetch;
            }
        }

        return $filtered;
    }

    /**
     * @return list<Op\Expr\ClassConstFetch>
     */
    private function precedingCallArgClassConstFetchesBeforeCfgOp(
        array $cfgChildren,
        Op $callOp,
        Block $block
    ): array {
        $fetches = $this->precedingClassConstFetchesBeforeCfgOp($cfgChildren, $callOp);

        return $this->dropEnumCaseFetchesConsumedByCaseClassPseudoConst($fetches, $cfgChildren, $callOp, $block);
    }

    /**
     * php-cfg may hoist `E::A; E::B; f(E::A); g(E::B)` to dead ClassConstFetch stmts before the
     * first call; later calls then lack a preceding fetch (#4260, #5933, ext/standard/type.c).
     */
    private function classConstFetchForHoistedDeadPrelude(
        Op $callOp,
        int $argIndex,
        Block $block
    ): ?Op\Expr\ClassConstFetch {
        if (null === $block->orig) {
            return null;
        }
        $children = $block->orig->children;
        $callIndex = null;
        foreach ($children as $i => $child) {
            if ($child === $callOp) {
                $callIndex = $i;
                break;
            }
        }
        if (null === $callIndex) {
            return null;
        }
        $firstCallIndex = null;
        foreach ($children as $i => $child) {
            if ($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall) {
                $firstCallIndex = $i;
                break;
            }
        }
        if (null === $firstCallIndex || $callIndex <= $firstCallIndex) {
            return null;
        }
        /** @var list<Op\Expr\ClassConstFetch> $hoistedFetches */
        $hoistedFetches = [];
        for ($i = 0; $i < $firstCallIndex; ++$i) {
            $child = $children[$i];
            if ($child instanceof Op\Expr\ClassConstFetch
                && !$this->hoistedEnumCaseFetchConsumedInCfg($child, $block)
            ) {
                $hoistedFetches[] = $child;
            }
        }
        if ([] === $hoistedFetches) {
            return null;
        }
        $callsBefore = 0;
        for ($i = 0; $i < $callIndex; ++$i) {
            $child = $children[$i];
            if ($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall) {
                ++$callsBefore;
            }
        }
        $slotOrdinal = $this->hoistedEnumPreludeSlotOrdinalForCallArg($callOp, $argIndex);
        if (null === $slotOrdinal) {
            return null;
        }
        $fetchIndex = $callsBefore + $slotOrdinal;

        return $hoistedFetches[$fetchIndex] ?? null;
    }

    /**
     * Map call ordinal + arg index to a ClassConstFetch when php-cfg linearizes fetches (#4260).
     */
    private function enumConstFetchForCallOrdinal(Block $block, int $callOrdinal, int $argIndex): ?Op\Expr\ClassConstFetch
    {
        if (null === $block->orig) {
            return null;
        }
        $children = $block->orig->children;
        $targetCall = null;
        $ordinal = 0;
        foreach ($children as $child) {
            if ($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall) {
                if ($ordinal === $callOrdinal) {
                    $targetCall = $child;
                    break;
                }
                ++$ordinal;
            }
        }
        if (null === $targetCall) {
            return null;
        }
        $fetches = $this->precedingCallArgClassConstFetchesBeforeCfgOp($children, $targetCall, $block);

        return $this->precedingClassConstFetchForCallArgIndex($targetCall, $argIndex, $fetches);
    }

    /**
     * @return array{0: Op, 1: int}|null
     */
    private function findCfgCallSiteForArg(array $cfgChildren, Operand $arg, ?Op $knownCallOp = null): ?array
    {
        $argRoot = Block::cfgVarRoot($arg);
        $argChain = $this->unwrapOperandChain($arg);
        if (
            null !== $knownCallOp
            && property_exists($knownCallOp, 'args')
            && is_array($knownCallOp->args)
        ) {
            foreach ($knownCallOp->args as $argIndex => $callArg) {
                if ($this->cfgCallArgOperandsMatch($callArg, $arg, $argChain, $argRoot)) {
                    return [$knownCallOp, $argIndex];
                }
            }
        }
        foreach ($cfgChildren as $child) {
            if (!property_exists($child, 'args') || !is_array($child->args)) {
                continue;
            }
            foreach ($child->args as $argIndex => $callArg) {
                if ($this->cfgCallArgOperandsMatch($callArg, $arg, $argChain, $argRoot)) {
                    return [$child, $argIndex];
                }
            }
        }

        return null;
    }

    private function cfgCallArgOperandsMatch(
        Operand $callArg,
        Operand $arg,
        Operand $argChain,
        ?Operand $argRoot
    ): bool {
        if ($callArg === $arg) {
            return true;
        }
        if ($this->unwrapOperandChain($callArg) === $argChain) {
            return true;
        }

        return null !== $argRoot && Block::cfgVarRoot($callArg) === $argRoot;
    }

    /**
     * php-cfg hoists null/false/true ConstFetch before FuncCall with dead arg temps (#9140, #15931, #16065).
     *
     * @param list<Op\Expr> $producers
     */
    private function matchHoistedScalarConstFetchInlineCallArgProducer(array $producers, ?Operand $callArg): ?Op\Expr\ConstFetch
    {
        if (null === $callArg || !$this->callArgIsDeadInlineTemporary($callArg)) {
            return null;
        }
        foreach ($producers as $producer) {
            if (!$producer instanceof Op\Expr\ConstFetch || null === $producer->result) {
                continue;
            }
            if (!$this->operandsReferToSameVariable($producer->result, $callArg)) {
                continue;
            }
            $name = $this->staticNameFromOperand($producer->name);
            if (null === $name || !\in_array(strtolower($name), ['true', 'false', 'null'], true)) {
                continue;
            }

            return $producer;
        }

        return null;
    }

    /** Stmt immediately before FuncCall is hoisted true/false/null for a trailing call arg (#11407). */
    private function isHoistedScalarConstFetchImmediatelyBeforeCall(?Op $expr): bool
    {
        if (!$expr instanceof Op\Expr\ConstFetch) {
            return false;
        }
        $name = $this->staticNameFromOperand($expr->name);

        return null !== $name && \in_array(strtolower($name), ['true', 'false', 'null'], true);
    }

    /**
     * php-cfg hoists ConstFetch/ClassConstFetch immediately before FuncCall for dead inline arg temps.
     * Defer eager compileOps so FUNCCALL_INIT runs first (php-src undefined-function before undefined-const, #17697).
     *
     * @param Op[] $ops
     */
    private function isDeferredHoistedConstFetchCallArgPrelude(
        Op\Expr $fetch,
        Op\Expr\FuncCall|Op\Expr\NsFuncCall $consumer,
        array $ops,
        int $fetchIndex
    ): bool {
        if (
            !$fetch instanceof Op\Expr\ConstFetch
            && !$fetch instanceof Op\Expr\ClassConstFetch
        ) {
            return false;
        }
        // Sibling comparison operands (false !== ini_get(...)) are not call args — compile eagerly (#17756, #17757).
        if ($this->hoistedConstFetchFeedsSiblingComparisonAfterCall($fetch, $consumer, $ops, $fetchIndex)) {
            return false;
        }
        if (!isset($fetch->result)) {
            return false;
        }
        if (!property_exists($consumer, 'args') || !\is_array($consumer->args)) {
            return false;
        }
        // php-cfg hoists call-arg ConstFetch as the stmt immediately before the consumer (#17697).
        foreach ($consumer->args as $arg) {
            if (null === $arg) {
                continue;
            }
            if ($arg === $fetch->result || $this->operandsReferToSameVariable($arg, $fetch->result)) {
                return true;
            }
        }
        // array_chunk(range(...), 2, true) — php-cfg dead temps may not share cfg roots (#11767).
        if (
            $fetch instanceof Op\Expr\ConstFetch
            && ($ops[$fetchIndex + 1] ?? null) === $consumer
        ) {
            $name = $this->staticNameFromOperand($fetch->name);
            if (null !== $name && \in_array(strtolower($name), ['true', 'false', 'null'], true)) {
                foreach ($consumer->args as $arg) {
                    if ($this->callArgIsDeadInlineTemporary($arg)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * True when a hoisted fetch supplies a comparison operand after the adjacent FuncCall, not a call arg.
     *
     * @param Op[] $ops
     */
    private function hoistedConstFetchFeedsSiblingComparisonAfterCall(
        Op\Expr $fetch,
        Op\Expr\FuncCall|Op\Expr\NsFuncCall $consumer,
        array $ops,
        int $fetchIndex
    ): bool {
        if (null === $fetch->result || ($ops[$fetchIndex + 1] ?? null) !== $consumer) {
            return false;
        }
        for ($j = $fetchIndex + 2, $n = \count($ops); $j < $n; ++$j) {
            $stmt = $ops[$j];
            if (!$this->isComparisonInlineCallArgProducer($stmt) || !$stmt instanceof Op\Expr\BinaryOp) {
                break;
            }
            if (
                $this->operandsReferToSameVariable($stmt->left, $fetch->result)
                || $this->operandsReferToSameVariable($stmt->right, $fetch->result)
            ) {
                return true;
            }
        }

        return false;
    }
}
