<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;

use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Operand\Temporary;

/**
 * isset()/empty()/unset() quiet ArrayDimFetch skips and nested dim-chain
 * helpers (#36387 / #36403). Property/dim fetch compile + call-arg prelude
 * wiring lives in {@see PropertyAndDimFetchCompile}.
 *
 * Extracted from {@see \PHPCompiler\Compiler} so the hub can shrink toward
 * host-CFG split-TU emit under SPINE_CHUNK (gen-0 <30m).
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types;
 * quiet-dim chain and fetch slot wiring rely on coercion (same as other
 * Concern extracts).
 *
 * php-src: Zend/zend_compile.c (ZEND_ISSET_ISEMPTY_DIM_OBJ /
 * ZEND_FETCH_DIM_R / ZEND_FETCH_OBJ_*), Zend/zend_execute.c isset/empty.
 */
trait IssetEmptyUnsetAndDimFetchCompile
{
    /**
     * php-cfg emits ArrayDimFetch as its own stmt before Isset_; skip duplicate lowering.
     * Nested `$a['x']['y']` chains are skipped entirely and quiet-fetched from compileIsset (#21991).
     */
    private function isArrayDimFetchOnlyIssetVar(
        Op\Expr\ArrayDimFetch $fetch,
        Op $next
    ): bool {
        if (!$next instanceof Op\Expr\Isset_) {
            return false;
        }
        foreach ($next->vars as $var) {
            $target = $var;
            while ($target instanceof Temporary) {
                if ($target === $fetch->result) {
                    return true;
                }
                if (null === $target->original) {
                    break;
                }
                $target = $target->original;
            }
            if ($target === $fetch->result) {
                return true;
            }
        }

        return false;
    }

    /**
     * Skip ArrayDimFetch stmts consumed by isset()/empty()/unset(), including nested dim chains
     * (`$a['x']['y']`) where only the innermost is adjacent to isset/empty (#21991), and sibling
     * dims before multi-target unset (`unset($o->a[$k], $o->b[$k])`) (#24250).
     *
     * @param Op[] $ops
     */
    private function isArrayDimFetchSkippedForIssetEmptyOrUnset(
        Op\Expr\ArrayDimFetch $fetch,
        array $ops,
        int $i,
        Block $block
    ): bool {
        return $this->isArrayDimFetchSkippedForIssetOrEmpty($fetch, $ops, $i, $block)
            || $this->isArrayDimFetchSkippedForUnset($fetch, $ops, $i, $block);
    }

    /**
     * isset()/empty() consumers only — not unset (#31818).
     *
     * @param Op[] $ops
     */
    private function isArrayDimFetchSkippedForIssetOrEmpty(
        Op\Expr\ArrayDimFetch $fetch,
        array $ops,
        int $i,
        Block $block
    ): bool {
        $opCount = count($ops);
        for ($j = $i + 1; $j < $opCount; ++$j) {
            $next = $ops[$j];
            if (
                $this->isArrayDimFetchOnlyIssetVar($fetch, $next)
                || $this->isArrayDimFetchOnlyEmptyVar($fetch, $next, $block)
            ) {
                return true;
            }
            if ($next instanceof Op\Expr\ArrayDimFetch) {
                if ($this->arrayDimFetchConsumesPriorResult($next, $fetch)) {
                    return $this->isArrayDimFetchSkippedForIssetOrEmpty($next, $ops, $j, $block);
                }
                // Sibling dim fetch before multi-target isset/empty (#24250).
                continue;
            }
            if ($next instanceof Op\Expr\PropertyFetch) {
                continue;
            }

            return false;
        }

        return false;
    }

    /**
     * unset($container[$dim]) consumers — PropertyFetch must stay R-mode for live alias (#31818).
     *
     * @param Op[] $ops
     */
    private function isArrayDimFetchSkippedForUnset(
        Op\Expr\ArrayDimFetch $fetch,
        array $ops,
        int $i,
        Block $block
    ): bool {
        $opCount = count($ops);
        for ($j = $i + 1; $j < $opCount; ++$j) {
            $next = $ops[$j];
            if ($this->isArrayDimFetchOnlyUnsetVar($fetch, $next)) {
                return true;
            }
            if ($next instanceof Op\Expr\ArrayDimFetch) {
                if ($this->arrayDimFetchConsumesPriorResult($next, $fetch)) {
                    return $this->isArrayDimFetchSkippedForUnset($next, $ops, $j, $block);
                }
                // Sibling dim before multi-target unset (#24250).
                continue;
            }
            if ($next instanceof Op\Expr\PropertyFetch) {
                continue;
            }

            return false;
        }

        return false;
    }

    /**
     * Skip intermediate ArrayDimFetch stmts in a nested `$a['x']['y'] ??…` / `??=` chain (#28954).
     * The innermost dim is lowered via {@see findCoalesceUsingArrayDimFetchLeft}; intermediates must
     * not emit FETCH_DIM_R (Undefined array key) before coalesce quiet/write lowering.
     *
     * @param Op[] $ops
     */
    private function isArrayDimFetchSkippedForCoalesce(
        Op\Expr\ArrayDimFetch $fetch,
        array $ops,
        int $i,
        Block $block
    ): bool {
        $opCount = count($ops);
        for ($j = $i + 1; $j < $opCount; ++$j) {
            $next = $ops[$j];
            if ($next instanceof Op\Expr\ArrayDimFetch) {
                if (!$this->arrayDimFetchConsumesPriorResult($next, $fetch)) {
                    // Sibling dim before a later coalesce left — keep scanning.
                    continue;
                }
                if (null !== $this->findCoalesceUsingArrayDimFetchLeft($next, $ops, $j)) {
                    return true;
                }

                return $this->isArrayDimFetchSkippedForCoalesce($next, $ops, $j, $block);
            }
            if ($this->isLoweredByFollowingCoalesce($next, $ops, $j)) {
                continue;
            }

            return false;
        }

        return false;
    }

    private function arrayDimFetchConsumesPriorResult(
        Op\Expr\ArrayDimFetch $consumer,
        Op\Expr\ArrayDimFetch $producer
    ): bool {
        $var = $consumer->var;
        if ($var === $producer || $var === $producer->result) {
            return true;
        }
        while ($var instanceof Temporary) {
            if ($var === $producer->result) {
                return true;
            }
            if (null === $var->original) {
                break;
            }
            $var = $var->original;
        }

        return $var === $producer->result;
    }

    /**
     * Outermost-first ArrayDimFetch chain for isset()/empty() nested dims (#21991).
     *
     * @return list<Op\Expr\ArrayDimFetch>
     */
    protected function collectArrayDimFetchChain(Op\Expr\ArrayDimFetch $innermost, Block $block): array
    {
        $chain = [$innermost];
        $seen = [spl_object_id($innermost) => true];
        $var = $innermost->var;
        while (true) {
            $prev = $this->findCoalesceArrayDimFetch($var, $block);
            if (null === $prev || isset($seen[spl_object_id($prev)])) {
                break;
            }
            $seen[spl_object_id($prev)] = true;
            array_unshift($chain, $prev);
            $var = $prev->var;
        }

        return $chain;
    }

    /**
     * Quiet FETCH_DIM_IS for all but the last dim in an isset()/empty() chain (#21991).
     *
     * @param list<Op\Expr\ArrayDimFetch> $chain outermost first
     * @return array{0: list<OpCode>, 1: int} prefix opcodes + container slot for the final dim
     */
    private function emitQuietDimFetchChainPrefix(array $chain, Block $block): array
    {
        $first = $chain[0];
        $containerSlot = $this->compileOperand($first->var, $block, true);
        if (count($chain) < 2) {
            return [[], $containerSlot];
        }
        $opcodes = [];
        $prefixLen = count($chain) - 1;
        for ($i = 0; $i < $prefixLen; ++$i) {
            $fetch = $chain[$i];
            $this->rejectArrayEmptyOffsetRead($fetch, $block);
            $resultSlot = $this->compileOperand($fetch->result, $block, false);
            $dimSlot = null !== $fetch->dim
                ? $this->compileOperand($fetch->dim, $block, true)
                : null;
            $op = new OpCode(OpCode::TYPE_ARRAY_DIM_FETCH, $resultSlot, $containerSlot, $dimSlot);
            $op->arrayDimFetchIs = true;
            $this->assignSourceMetadata($op, $fetch);
            $opcodes[] = $op;
            $containerSlot = $resultSlot;
        }

        return [$opcodes, $containerSlot];
    }

    /**
     * FETCH_DIM_W for all but the last dim so {@code unset($a[0]['k'])} mutates the live element.
     *
     * php-cfg skips intermediate ArrayDimFetch stmts before Terminal_Unset; without a write
     * prefix the container slot is an unpopulated temp (or a FETCH_DIM_R copy) and the unset
     * is a no-op — Parsedown::li() then fails to strip {@code name=p} (#36380).
     *
     * php-src: Zend/zend_compile.c {@code ZEND_FETCH_DIM_W} + {@code ZEND_UNSET_DIM}.
     *
     * @param list<Op\Expr\ArrayDimFetch> $chain outermost first
     * @return array{0: list<OpCode>, 1: int} prefix opcodes + container slot for the final dim
     */
    private function emitUnsetDimWriteChainPrefix(array $chain, Block $block): array
    {
        $first = $chain[0];
        $containerSlot = $this->compileOperand($first->var, $block, true);
        if (count($chain) < 2) {
            return [[], $containerSlot];
        }
        $opcodes = [];
        $prefixLen = count($chain) - 1;
        for ($i = 0; $i < $prefixLen; ++$i) {
            $fetch = $chain[$i];
            $this->rejectGlobalsAppend($fetch, $block);
            $resultSlot = $this->compileOperand($fetch->result, $block, false);
            $dimSlot = null !== $fetch->dim
                ? $this->compileOperand($fetch->dim, $block, true)
                : null;
            $op = new OpCode(
                OpCode::TYPE_ARRAY_DIM_FETCH_WRITE,
                $resultSlot,
                $containerSlot,
                $dimSlot
            );
            $this->assignSourceMetadata($op, $fetch);
            $opcodes[] = $op;
            $containerSlot = $resultSlot;
        }

        return [$opcodes, $containerSlot];
    }

    /**
     * php-cfg emits ArrayDimFetch as its own stmt before Terminal_Unset; skip duplicate lowering.
     */
    private function isArrayDimFetchOnlyUnsetVar(
        Op\Expr\ArrayDimFetch $fetch,
        Op $next
    ): bool {
        if (!$next instanceof Op\Terminal\Unset_) {
            return false;
        }
        foreach ($next->exprs as $var) {
            if ($var === $fetch) {
                return true;
            }
            $target = $var;
            while ($target instanceof Temporary) {
                if ($target === $fetch->result) {
                    return true;
                }
                if (null === $target->original) {
                    break;
                }
                $target = $target->original;
            }
            if ($target === $fetch->result) {
                return true;
            }
        }

        return false;
    }

    /**
     * php-cfg emits PropertyFetch as its own stmt before Isset_; skip duplicate lowering.
     */
    private function isPropertyFetchOnlyIssetVar(
        Op\Expr\PropertyFetch $fetch,
        Op $next
    ): bool {
        if (!$next instanceof Op\Expr\Isset_) {
            return false;
        }
        foreach ($next->vars as $var) {
            if ($var === $fetch) {
                return true;
            }
            $target = $var;
            while ($target instanceof Temporary) {
                if ($target === $fetch->result) {
                    return true;
                }
                if (null === $target->original) {
                    break;
                }
                $target = $target->original;
            }
            if ($target === $fetch->result) {
                return true;
            }
        }

        return false;
    }

    /**
     * PropertyFetch → ArrayDimFetch → isset/empty/?? — quiet FETCH_OBJ_IS (#31783).
     */
    private function isPropertyFetchPreludeForDimIssetEmptyOrCoalesce(
        Op\Expr\PropertyFetch $fetch,
        Block $block
    ): bool {
        if (null === $block->orig) {
            return false;
        }
        $ops = $block->orig->children;
        $index = array_search($fetch, $ops, true);
        if (!is_int($index)) {
            return false;
        }

        return $this->propertyOrStaticFetchResultFeedsDimIssetEmptyOrCoalesce(
            $fetch->result,
            $ops,
            $index,
            $block
        );
    }

    /**
     * StaticPropertyFetch → ArrayDimFetch → isset/empty/?? — FETCH_STATIC_PROP_IS (#31783).
     */
    private function isStaticPropertyFetchPreludeForDimIssetEmptyOrCoalesce(
        Op\Expr\StaticPropertyFetch $fetch,
        Block $block
    ): bool {
        if (null === $block->orig) {
            return false;
        }
        $ops = $block->orig->children;
        $index = array_search($fetch, $ops, true);
        if (!is_int($index)) {
            return false;
        }

        return $this->propertyOrStaticFetchResultFeedsDimIssetEmptyOrCoalesce(
            $fetch->result,
            $ops,
            $index,
            $block
        );
    }

    /**
     * @param Op[] $ops
     */
    private function propertyOrStaticFetchResultFeedsDimIssetEmptyOrCoalesce(
        ?Operand $fetchResult,
        array $ops,
        int $index,
        Block $block
    ): bool {
        if (null === $fetchResult) {
            return false;
        }
        $opCount = count($ops);
        for ($j = $index + 1; $j < $opCount; ++$j) {
            $next = $ops[$j];
            if ($next instanceof Op\Expr\ArrayDimFetch) {
                if (!$this->operandsChainEqual($next->var, $fetchResult)) {
                    // Sibling dim before a later consumer — keep scanning (#24250).
                    continue;
                }
                // isset/empty/?? → FETCH_OBJ_IS (#31783). unset($obj->arr[$k]) must NOT —
                // that needs a live property alias (re-#24250 / #31818).
                if ($this->isArrayDimFetchSkippedForIssetOrEmpty($next, $ops, $j, $block)) {
                    return true;
                }
                if ($this->isArrayDimFetchSkippedForCoalesce($next, $ops, $j, $block)) {
                    return true;
                }
                if (null !== $this->findCoalesceUsingArrayDimFetchLeft($next, $ops, $j)) {
                    return true;
                }

                return false;
            }
            if (
                $next instanceof Op\Expr\PropertyFetch
                || $next instanceof Op\Expr\StaticPropertyFetch
                || $next instanceof Op\Expr\ArrayDimFetch
            ) {
                continue;
            }
            if ($this->isLoweredByFollowingCoalesce($next, $ops, $j)) {
                continue;
            }

            return false;
        }

        return false;
    }

    /**
     * php-cfg emits StaticPropertyFetch as its own stmt before Isset_; skip duplicate lowering (#15112).
     */
    private function isStaticPropertyFetchOnlyIssetVar(
        Op\Expr\StaticPropertyFetch $fetch,
        Op $next
    ): bool {
        if (!$next instanceof Op\Expr\Isset_) {
            return false;
        }
        foreach ($next->vars as $var) {
            if ($var === $fetch) {
                return true;
            }
            $target = $var;
            while ($target instanceof Temporary) {
                if ($target === $fetch->result) {
                    return true;
                }
                if (null === $target->original) {
                    break;
                }
                $target = $target->original;
            }
            if ($target === $fetch->result) {
                return true;
            }
        }

        return false;
    }

    /**
     * php-cfg isset()/empty() prelude stmts between hoisted sibling call-arg producers (#15646).
     * Nested `$a['x']['y']` dim chains before isset/empty are also preludes (#21991).
     *
     * @param list<Op> $cfgChildren
     */
    private function isIssetOrEmptyInlineCallArgPreludeStmt(?Op $stmt, int $index, array $cfgChildren): bool
    {
        if (!$stmt instanceof Op\Expr) {
            return false;
        }
        $next = $cfgChildren[$index + 1] ?? null;
        if (
            ($stmt instanceof Op\Expr\PropertyFetch && $this->isPropertyFetchOnlyIssetVar($stmt, $next))
            || ($stmt instanceof Op\Expr\StaticPropertyFetch && $this->isStaticPropertyFetchOnlyIssetVar($stmt, $next))
            || ($stmt instanceof Op\Expr\PropertyFetch && $this->propertyFetchResultFeedsEmpty($stmt, $next))
            || ($stmt instanceof Op\Expr\StaticPropertyFetch && $this->staticPropertyFetchResultFeedsEmpty($stmt, $next))
            || ($stmt instanceof Op\Expr\ArrayDimFetch && $this->isArrayDimFetchOnlyIssetVar($stmt, $next))
            || ($stmt instanceof Op\Expr\ArrayDimFetch && $this->isArrayDimFetchOnlyUnsetVar($stmt, $next))
            || ($stmt instanceof Op\Expr\ArrayDimFetch && $this->arrayDimFetchResultFeedsEmpty($stmt, $next))
        ) {
            return true;
        }
        // Nested dim chain: `$a['x']['y']` then isset/empty/unset (#21991).
        if (!$stmt instanceof Op\Expr\ArrayDimFetch) {
            return false;
        }
        $j = $index;
        $current = $stmt;
        while ($j + 1 < \count($cfgChildren) && ($cfgChildren[$j + 1] ?? null) instanceof Op\Expr\ArrayDimFetch) {
            /** @var Op\Expr\ArrayDimFetch $nextDim */
            $nextDim = $cfgChildren[$j + 1];
            if (!$this->arrayDimFetchConsumesPriorResult($nextDim, $current)) {
                break;
            }
            $current = $nextDim;
            ++$j;
        }
        $tail = $cfgChildren[$j + 1] ?? null;
        if (null === $tail) {
            return false;
        }

        return $this->isArrayDimFetchOnlyIssetVar($current, $tail)
            || $this->isArrayDimFetchOnlyUnsetVar($current, $tail)
            || $this->arrayDimFetchResultFeedsEmpty($current, $tail);
    }

    private function arrayDimFetchResultFeedsEmpty(Op\Expr\ArrayDimFetch $fetch, ?Op $next): bool
    {
        if (!$next instanceof Op\Expr\Empty_) {
            return false;
        }
        $target = $next->expr;
        if ($target === $fetch || $target === $fetch->result) {
            return true;
        }
        while ($target instanceof Temporary) {
            if ($target === $fetch->result) {
                return true;
            }
            if (null === $target->original) {
                break;
            }
            $target = $target->original;
        }

        return $target === $fetch->result;
    }

    private function propertyFetchResultFeedsEmpty(Op\Expr\PropertyFetch $fetch, ?Op $next): bool
    {
        if (!$next instanceof Op\Expr\Empty_) {
            return false;
        }
        $target = $next->expr;
        if ($target === $fetch || $target === $fetch->result) {
            return true;
        }
        while ($target instanceof Temporary) {
            if ($target === $fetch->result) {
                return true;
            }
            if (null === $target->original) {
                break;
            }
            $target = $target->original;
        }

        return $target === $fetch->result;
    }

    private function staticPropertyFetchResultFeedsEmpty(Op\Expr\StaticPropertyFetch $fetch, ?Op $next): bool
    {
        if (!$next instanceof Op\Expr\Empty_) {
            return false;
        }
        $target = $next->expr;
        if ($target === $fetch || $target === $fetch->result) {
            return true;
        }
        while ($target instanceof Temporary) {
            if ($target === $fetch->result) {
                return true;
            }
            if (null === $target->original) {
                break;
            }
            $target = $target->original;
        }

        return $target === $fetch->result;
    }

    /**
     * 0-based ordinal among hoisted sibling call-arg producers, skipping isset/empty preludes (#15646).
     *
     * @param list<Op> $cfgChildren
     */
    private function hoistedSiblingCallArgProducerOrdinal(
        int $producerIndex,
        int $firstSibling,
        int $consumerIndex,
        array $cfgChildren
    ): int {
        $ordinal = 0;
        for ($j = $firstSibling; $j < $consumerIndex; ++$j) {
            if ($j === $producerIndex) {
                return $ordinal;
            }
            $stmt = $cfgChildren[$j] ?? null;
            if (!$stmt instanceof Op\Expr || !$this->isInlineExprCallArgProducer($stmt)) {
                continue;
            }
            if ($this->isIssetOrEmptyInlineCallArgPreludeStmt($stmt, $j, $cfgChildren)) {
                continue;
            }
            ++$ordinal;
        }

        return $ordinal;
    }

}
