<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\BuiltinByRefParams;
use PHPCompiler\OpCode;

use PHPCfg\Func as CfgFunc;
use PHPCfg\Op;
use PHPCfg\Block as CfgBlock;
use PHPCfg\Operand;
use PHPCfg\Operand\Temporary;

/**
 * isset()/empty()/unset() quiet ArrayDimFetch skips, property/dim fetch
 * compile helpers, and call-arg property-fetch prelude wiring (#36387 / #36403).
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

    /**
     * `@` on builtins with by-ref out args must keep errno/errstr slots after END_SILENCE (#9320, #10336).
     */
    private function inheritErrorSuppressByRefCallArgSlots(
        Block $suppressCompiled,
        Block $endCompiled,
        Op $primary
    ): void {
        if (
            !$primary instanceof Op\Expr\FuncCall
            && !$primary instanceof Op\Expr\NsFuncCall
        ) {
            return;
        }
        if (!property_exists($primary, 'args') || !\is_array($primary->args)) {
            return;
        }
        $name = $this->resolveCfgFuncCallName($primary);
        if (null === $name) {
            return;
        }
        foreach (BuiltinByRefParams::forFunction($name) as $argIndex) {
            $arg = $primary->args[$argIndex] ?? null;
            if (!$arg instanceof Operand) {
                continue;
            }
            $slot = $this->errorSuppressByRefArgSendSlot($suppressCompiled, $primary, (int) $argIndex)
                ?? $suppressCompiled->slotForOperand($arg);
            if (null === $slot) {
                continue;
            }
            $endCompiled->forceBindScopeSlot($arg, $slot);
            $root = Block::cfgVarRoot($arg);
            if (null !== $root) {
                $endCompiled->forceBindScopeSlot($root, $slot);
            }
        }
    }

    /**
     * ARG_SEND slot for a by-ref callee arg inside an {@see ErrorSuppressBlock} (#9320).
     */
    private function errorSuppressByRefArgSendSlot(
        Block $suppressCompiled,
        Op $primary,
        int $argIndex
    ): ?int {
        unset($primary);
        $inCall = false;
        $sendOrdinal = -1;
        $funcCallInits = 0;
        foreach ($suppressCompiled->opCodes as $op) {
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$funcCallInits;
                if (1 === $funcCallInits) {
                    $inCall = true;
                    $sendOrdinal = -1;
                }
                continue;
            }
            if (!$inCall) {
                continue;
            }
            if (OpCode::TYPE_ARG_SEND === $op->type) {
                ++$sendOrdinal;
                if ($sendOrdinal === $argIndex) {
                    return (int) $op->arg1;
                }
                continue;
            }
            if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type || OpCode::TYPE_FUNCCALL_EXEC_NORETURN === $op->type) {
                break;
            }
        }

        return null;
    }

    /**
     * trim($obj->prop) — php-cfg hoists PropertyFetch as its own stmt with a dead arg temp (#14467, libxml).
     */
    private function syncPropertyFetchResultToFollowingFuncCallArg(
        Op\Expr\PropertyFetch $fetch,
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
            foreach (array_reverse($block->opCodes) as $opCode) {
                if (OpCode::TYPE_PROPERTY_FETCH === $opCode->type && null !== $opCode->arg1) {
                    $fetchSlot = (int) $opCode->arg1;
                    break;
                }
            }
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
            if (!$this->propertyFetchFuncCallArgUsesHoistedFetch($arg, (int) $argIndex, $fetch, $next)) {
                continue;
            }
            $block->bindOperandScopeSlot($arg, $fetchSlot);
            $this->registerSyncedCoalesceFuncCallArgSlot($arg, $fetchSlot);
        }
        if (null !== $fetch->result) {
            $this->registerSyncedCoalesceFuncCallArgSlot($fetch->result, $fetchSlot);
        }
    }

    /**
     * Hoisted PropertyFetch before FuncCall — only the consumer arg gets the fetch slot (#14467, #18427).
     *
     * implode(',', $obj->items) must not rewire the separator literal to the property temp.
     */
    private function propertyFetchFuncCallArgUsesHoistedFetch(
        Operand $arg,
        int $argIndex,
        Op\Expr\PropertyFetch $fetch,
        Op $callOp
    ): bool {
        if ($this->isEmbeddedCallLiteralArg($arg)) {
            return false;
        }
        if (null !== $fetch->result && $this->operandsReferToSameVariable($arg, $fetch->result)) {
            return true;
        }
        if (!$this->callArgIsDeadInlineTemporary($arg)) {
            return false;
        }
        if (!property_exists($callOp, 'args') || !is_array($callOp->args)) {
            return false;
        }
        $deadTempIndices = [];
        foreach ($callOp->args as $i => $candidate) {
            if (!$candidate instanceof Operand || $this->isEmbeddedCallLiteralArg($candidate)) {
                continue;
            }
            if ($this->callArgIsDeadInlineTemporary($candidate)) {
                $deadTempIndices[] = (int) $i;
            }
        }

        return 1 === \count($deadTempIndices) && (int) $argIndex === $deadTempIndices[0];
    }

    /** Last hoisted TYPE_PROPERTY_FETCH result slot in $block (trim($obj->prop) dead-arg wiring). */
    private function lastPropertyFetchResultSlotBeforePendingCall(Block $block): ?string
    {
        foreach (array_reverse($block->opCodes) as $opCode) {
            if (OpCode::TYPE_PROPERTY_FETCH === $opCode->type && null !== $opCode->arg1) {
                return (string) $opCode->arg1;
            }
        }

        return null;
    }

    /**
     * Hoisted PropertyFetch before MethodCall/FuncCall when scalar preludes sit between (#18860).
     *
     * importNode($doc->documentElement->firstChild, true) — ConstFetch between chain and call.
     *
     * @return Op\Expr\PropertyFetch|Op\Expr\NullsafePropertyFetch|null
     */
    private function propertyFetchPreludeMatchingCallArg(
        Block $block,
        Op $cfgCallOp,
        int $callIndex,
        int $argIndex,
        Operand $arg
    ): Op\Expr\PropertyFetch|Op\Expr\NullsafePropertyFetch|null {
        if (null === $block->orig || $callIndex < 1 || !property_exists($cfgCallOp, 'args')) {
            return null;
        }
        $callArgs = $cfgCallOp->args;
        if (!\is_array($callArgs) || !$this->callArgIsDeadInlineTemporary($arg)) {
            return null;
        }
        // importNode($doc->documentElement->firstChild, true) — hoisted true is not a PropertyFetch arg (#18860, re-open).
        if (null !== $this->tryFoldHoistedBoolNullLiteralCallArg($arg, $block, $cfgCallOp, $argIndex)) {
            return null;
        }
        $nonLiteralArgCount = 0;
        foreach ($callArgs as $callArg) {
            if (null !== $callArg && !$this->isEmbeddedCallLiteralArg($callArg)) {
                ++$nonLiteralArgCount;
            }
        }
        $trailingScalarPreludeCount = 0;
        $scalarProbeIndex = $callIndex - 1;
        while ($scalarProbeIndex >= 0) {
            $scalarProbe = $block->orig->children[$scalarProbeIndex] ?? null;
            if ($scalarProbe instanceof Op\Expr\ConstFetch || $scalarProbe instanceof Op\Expr\ClassConstFetch) {
                ++$trailingScalarPreludeCount;
                --$scalarProbeIndex;
                continue;
            }
            break;
        }
        $propertyArgCount = max(0, $nonLiteralArgCount - $trailingScalarPreludeCount);
        $fetches = [];
        $probeIndex = $callIndex - 1;
        while ($probeIndex >= 0) {
            $probe = $block->orig->children[$probeIndex] ?? null;
            if ($probe instanceof Op\Expr\ConstFetch || $probe instanceof Op\Expr\ClassConstFetch) {
                --$probeIndex;
                continue;
            }
            if (
                $probe instanceof Op\Expr\PropertyFetch
                || $probe instanceof Op\Expr\NullsafePropertyFetch
            ) {
                $fetches[] = $probe;
                --$probeIndex;
                continue;
            }
            break;
        }
        if ([] === $fetches) {
            return null;
        }
        // Chained $obj->a->b hoists intermediate PropertyFetches that feed the next
        // fetch's receiver, not call args. Keep only leaf fetches (#19719, #18860).
        $leafFetches = [];
        foreach ($fetches as $i => $fetch) {
            $feedsNearerFetch = false;
            for ($j = 0; $j < $i; ++$j) {
                $nearer = $fetches[$j];
                if (
                    null !== $fetch->result
                    && property_exists($nearer, 'var')
                    && null !== $nearer->var
                    && $this->operandsReferToSameVariable($fetch->result, $nearer->var)
                ) {
                    $feedsNearerFetch = true;
                    break;
                }
            }
            if (!$feedsNearerFetch) {
                $leafFetches[] = $fetch;
            }
        }
        $fetches = $leafFetches;
        // MethodCall receiver PropertyFetch is hoisted with arg fetches but is not a call arg
        // (documentElement->insertBefore($d2->…->firstChild, $d1->…->firstChild)) (#22710).
        if ($cfgCallOp instanceof Op\Expr\MethodCall && null !== $cfgCallOp->var) {
            $receiverVar = $cfgCallOp->var;
            $fetches = \array_values(\array_filter(
                $fetches,
                function ($fetch) use ($receiverVar): bool {
                    return null === $fetch->result
                        || !$this->operandsReferToSameVariable($receiverVar, $fetch->result);
                }
            ));
        }
        // $a->parentNode->replaceChild($b->cloneNode(true), $a) — parentNode feeds the *outer*
        // MethodCall receiver, not cloneNode's bool arg. Drop PropertyFetches consumed as
        // later sibling MethodCall receivers (#25876).
        if (\is_int($callIndex) && null !== $block->orig) {
            $laterReceiverVars = [];
            for ($later = $callIndex + 1, $nChildren = \count($block->orig->children); $later < $nChildren; ++$later) {
                $laterOp = $block->orig->children[$later] ?? null;
                if (
                    $laterOp instanceof Op\Expr\MethodCall
                    && null !== $laterOp->var
                ) {
                    $laterReceiverVars[] = $laterOp->var;
                }
            }
            if ([] !== $laterReceiverVars) {
                $fetches = \array_values(\array_filter(
                    $fetches,
                    function ($fetch) use ($laterReceiverVars): bool {
                        if (null === $fetch->result) {
                            return true;
                        }
                        foreach ($laterReceiverVars as $laterVar) {
                            if ($this->operandsReferToSameVariable($laterVar, $fetch->result)) {
                                return false;
                            }
                        }

                        return true;
                    }
                ));
            }
        }
        if ([] === $fetches) {
            return null;
        }
        if (\count($fetches) > $propertyArgCount) {
            // Extra leaf is usually the MethodCall receiver (documentElement->C14NFile($tmp)).
            // When every non-literal arg is a scalar ConstFetch (propertyArgCount===0), leftovers
            // are outer receivers — not this call's args (#25876 nested cloneNode(true/false)).
            if (0 !== $argIndex || 0 === $propertyArgCount) {
                return null;
            }

            return $fetches[0];
        }
        // Map leaf PropertyFetches onto dead-temp arg indices (skip embedded literals).
        // two('L', $el->tagName) after @$doc->loadXML() — raw argIndex 1 must match the
        // sole dead-temp slot, not firstPropertyArgIndex=0 (#21439, re-#19719/#18860).
        $deadTempArgIndices = [];
        foreach ($callArgs as $i => $callArg) {
            if (
                $callArg instanceof Operand
                && !$this->isEmbeddedCallLiteralArg($callArg)
                && $this->callArgIsDeadInlineTemporary($callArg)
            ) {
                $deadTempArgIndices[] = (int) $i;
            }
        }
        $propertyDeadTempArgIndices = [];
        foreach ($deadTempArgIndices as $deadIdx) {
            $deadArg = $callArgs[$deadIdx] ?? null;
            if (
                $deadArg instanceof Operand
                && null !== $this->tryFoldHoistedBoolNullLiteralCallArg($deadArg, $block, $cfgCallOp, $deadIdx)
            ) {
                continue;
            }
            $propertyDeadTempArgIndices[] = $deadIdx;
        }
        // trailingScalarPreludeCount already subtracts ConstFetch/ClassConstFetch (incl. LIBXML_*),
        // but tryFold only drops true/false/null — trim so saveXML($el->prop, LIBXML_*) does not
        // bind the PropertyFetch slot to the options arg (#25292).
        if (\count($propertyDeadTempArgIndices) > $propertyArgCount) {
            $propertyDeadTempArgIndices = \array_slice($propertyDeadTempArgIndices, 0, $propertyArgCount);
        }
        $deadOrdinal = array_search($argIndex, $propertyDeadTempArgIndices, true);
        if (!\is_int($deadOrdinal)) {
            return null;
        }
        // MethodCall/FuncCall producers fill leading dead-temp args; PropertyFetch
        // leaves map to the trailing propertyArgCount slots (#19719):
        // insertBefore($d->createElement('x'), $r->lastChild).
        $fetchCount = \count($fetches);
        $firstFetchDeadOrdinal = \count($propertyDeadTempArgIndices) - $fetchCount;
        if ($deadOrdinal < $firstFetchDeadOrdinal) {
            return null;
        }
        $ordinal = $fetchCount - 1 - ($deadOrdinal - $firstFetchDeadOrdinal);
        if ($ordinal < 0 || $ordinal >= $fetchCount) {
            return null;
        }

        return $fetches[$ordinal];
    }

    /**
     * @return ?string scope slot for a hoisted PropertyFetch call arg (#18860)
     */
    private function propertyFetchPreludeResultSlot(
        Block $block,
        Op\Expr\PropertyFetch|Op\Expr\NullsafePropertyFetch $prelude,
        Op $cfgCallOp
    ): ?string {
        if (null === $block->slotForOperand($prelude->result)) {
            foreach ($this->compileExpr($prelude, $block) as $op) {
                $block->addOpCode($op);
            }
        }

        // Prefer this prelude's operand slot. Looking back for the last TYPE_PROPERTY_FETCH
        // collapses distinct PropertyFetch call args onto one temp — e.g. peek($a->x, $b->x)
        // and insertBefore($d2->documentElement->firstChild, $d1->…->firstChild) (#22710).
        $operandSlot = $block->slotForOperand($prelude->result);
        if (null !== $operandSlot) {
            return (string) $operandSlot;
        }

        return $this->compiledExpressionPreludeResultSlotBeforePendingFuncCall($block, $prelude)
            ?? $this->slotForInlineCallArgProducerResult(
                $block,
                $prelude,
                $cfgCallOp,
                null !== $block->orig ? $block->orig->children : null
            );
    }

    private function compileStaticPropertyFetchRead(
        Op\Expr\StaticPropertyFetch $fetch,
        Block $block,
        bool $propertyHookCoalesceRead = false
    ): void {
        $op = new OpCode(
            OpCode::TYPE_STATIC_PROPERTY_FETCH,
            $this->compileOperand($fetch->result, $block, false),
            $this->compileClassNameOperand($fetch->class, $block),
            $this->compileStaticPropertyNameSlot($fetch->name, $fetch->class, $block)
        );
        $this->assignSourceMetadata($op, $fetch);
        if ($propertyHookCoalesceRead) {
            $op->propertyHookCoalesceRead = true;
        }
        $block->addOpCode($op);
    }

    /**
     * Emit a write fetch in $block (used by ??= right branch when backing is null, #6472).
     */
    private function compilePropertyFetchWrite(Op\Expr\PropertyFetch $fetch, Block $block): void
    {
        $this->rejectTemporaryExpressionInWriteContext($fetch->result, $block, $fetch);
        $block->addOpCode(new OpCode(
            OpCode::TYPE_PROPERTY_FETCH_WRITE,
            $this->compileOperand($fetch->result, $block, false),
            $this->compileOperand($fetch->var, $block, true),
            $this->compileOperand($fetch->name, $block, true)
        ));
    }

    private function compileStaticPropertyFetchWrite(Op\Expr\StaticPropertyFetch $fetch, Block $block): void
    {
        $block->addOpCode(new OpCode(
            OpCode::TYPE_STATIC_PROPERTY_FETCH,
            $this->compileOperand($fetch->result, $block, false),
            $this->compileClassNameOperand($fetch->class, $block),
            $this->compileStaticPropertyNameSlot($fetch->name, $fetch->class, $block)
        ));
    }

    /**
     * Emit a read fetch in $block (used by ?? left branch when the stmt fetch was skipped).
     *
     * @param bool $skipFloatKeyDeprecation When true, isset already warned for this dim (#29664).
     */
    private function compileArrayDimFetchRead(
        Op\Expr\ArrayDimFetch $fetch,
        Block $block,
        bool $skipFloatKeyDeprecation = false
    ): void {
        $this->rejectArrayEmptyOffsetRead($fetch, $block);
        $op = new OpCode(
            OpCode::TYPE_ARRAY_DIM_FETCH,
            $this->compileOperand($fetch->result, $block, false),
            $this->compileArrayDimFetchContainerSlot($fetch, $block),
            null !== $fetch->dim ? $this->compileOperand($fetch->dim, $block, true) : null
        );
        $op->arrayDimFetchSkipFloatKeyDeprecation = $skipFloatKeyDeprecation;
        $this->assignSourceMetadata($op, $fetch);
        $block->addOpCode($op);
    }

    /**
     * Emit a write fetch in $block (used by ??= right branch when the key is absent, issue #1235).
     */
    private function compileArrayDimFetchWrite(Op\Expr\ArrayDimFetch $fetch, Block $block): void
    {
        $this->rejectGlobalsAppend($fetch, $block);
        $this->rejectTemporaryExpressionInWriteContext($fetch->result, $block, $fetch);
        $op = new OpCode(
            OpCode::TYPE_ARRAY_DIM_FETCH_WRITE,
            $this->compileOperand($fetch->result, $block, false),
            $this->compileArrayDimFetchContainerSlot($fetch, $block),
            null !== $fetch->dim ? $this->compileOperand($fetch->dim, $block, true) : null
        );
        $this->assignSourceMetadata($op, $fetch);
        $block->addOpCode($op);
    }

    /**
     * FETCH_DIM_W for each dim in an outermost-first nested ??= write chain (#28954).
     *
     * @param list<Op\Expr\ArrayDimFetch> $chain
     */
    private function compileArrayDimFetchWriteChain(array $chain, Block $block): void
    {
        foreach ($chain as $fetch) {
            $this->compileArrayDimFetchWrite($fetch, $block);
        }
    }

    /**
     * Read each dim in an outermost-first nested ?? / ??= left branch (#28954).
     *
     * @param list<Op\Expr\ArrayDimFetch> $chain
     * @param bool                        $skipFloatKeyDeprecation Last-dim isset already warned (#29664).
     */
    private function compileArrayDimFetchReadChain(
        array $chain,
        Block $block,
        bool $skipFloatKeyDeprecation = false
    ): void {
        $last = count($chain) - 1;
        foreach ($chain as $i => $fetch) {
            // Only the final dim shares the coalesce isset probe; prefixes used quiet FETCH_DIM_IS.
            $this->compileArrayDimFetchRead(
                $fetch,
                $block,
                $skipFloatKeyDeprecation && $i === $last
            );
        }
    }

    /**
     * Compile dim-fetch for call-arg wiring — force write fetch for by-ref builtins (#4512).
     */
    private function compileArrayDimFetchForCallArg(
        Op\Expr\ArrayDimFetch $fetch,
        Block $block,
        ?Op $cfgCallOp,
        int $argIndex
    ): void {
        $forWrite = false;
        if (null !== $cfgCallOp) {
            $calleeName = $this->funcCallExprCalleeName($cfgCallOp);
            if (null !== $calleeName && $this->callArgRequiresByRef($calleeName, $argIndex, $fetch->result, $block)) {
                $forWrite = true;
            }
        }
        if (!$forWrite) {
            $forWrite = $this->isArrayDimFetchForWrite($fetch, $block);
        }
        if ($forWrite) {
            $this->compileArrayDimFetchWrite($fetch, $block);
        } else {
            foreach ($this->compileExpr($fetch, $block) as $op) {
                $block->addOpCode($op);
            }
        }
    }

    /**
     * Container slot for array dim fetch/write opcodes.
     *
     * PhiResolver may clear {@see Op\Expr\ArrayDimFetch::$var} after param phi merge in large
     * compilation units (PackEngine.parseFormat, #13092). Recover from typed parameters.
     */
    protected function compileArrayDimFetchContainerSlot(Op\Expr\ArrayDimFetch $fetch, Block $block): int
    {
        if (null !== $fetch->var) {
            $slot = $this->compileOperand($fetch->var, $block, true);
            if (null !== $slot) {
                return $slot;
            }
        }

        $recovered = $this->recoverPhiClearedArrayDimFetchContainer($fetch, $block);
        if (null !== $recovered) {
            return $recovered;
        }

        $this->throwCompileLogic('ArrayDimFetch missing container operand');
    }

    /**
     * PhiResolver postprocessor can leave ArrayDimFetch.var null while the container CV remains
     * a typed parameter (php-cfg/Visitor/PhiResolver.php, #13092).
     */
    private function recoverPhiClearedArrayDimFetchContainer(
        Op\Expr\ArrayDimFetch $fetch,
        Block $block
    ): ?int {
        if (null !== $fetch->var || null === $block->func || [] === $block->func->params) {
            return null;
        }

        $preferred = $this->isArrayAppendDim($fetch->dim) ? 'array' : 'string';
        foreach ($block->func->params as $param) {
            if (null === $param->result) {
                continue;
            }
            $decl = $this->declNameFromCfgType($param->declaredType ?? null);
            if ('array' === $preferred) {
                if ('array' !== $decl && null === $this->genericArraySpecFromCfgType($param->declaredType ?? null)) {
                    continue;
                }
            } elseif ('string' !== $decl) {
                continue;
            }
            $slot = $this->compileOperand($param->result, $block, true);
            if (null !== $slot) {
                return $slot;
            }
        }

        foreach ($block->func->params as $param) {
            if (null === $param->result) {
                continue;
            }
            $slot = $this->compileOperand($param->result, $block, true);
            if (null !== $slot) {
                return $slot;
            }
        }

        if ($this->isArrayAppendDim($fetch->dim) || null === $fetch->dim) {
            $emptyInit = $this->recoverEmptyArrayInitLocalOperand($block->func);
            if (null !== $emptyInit) {
                $slot = $this->compileOperand($emptyInit, $block, true);
                if (null !== $slot) {
                    return $slot;
                }
            }
        }

        if (null !== $fetch->dim) {
            $dimRoot = Block::cfgVarRoot($fetch->dim);
            $dimName = null !== $dimRoot ? Block::resolveVariableName($dimRoot) : null;
            $call = 'currentArg' === $dimName
                ? $this->findFuncCallFirstArgOperand($block->func, 'count')
                : $this->findFuncCallFirstArgOperand($block->func, 'strlen');
            if (null !== $call) {
                $slot = $this->compileOperand($call, $block, true);
                if (null !== $slot) {
                    return $slot;
                }
            }
        }

        return null;
    }

    private function recoverEmptyArrayInitLocalOperand(CfgFunc $func): ?Operand
    {
        if (null === $func->cfg) {
            return null;
        }
        $emptyInits = [];
        $walk = function ($node) use (&$walk, &$emptyInits): void {
            if ($node instanceof CfgBlock) {
                $children = $node->children;
                foreach ($children as $i => $child) {
                    if (
                        $child instanceof Op\Expr\Assign
                        && $i > 0
                        && $children[$i - 1] instanceof Op\Expr\Array_
                    ) {
                        $arr = $children[$i - 1];
                        if ([] === $arr->keys && [] === $arr->values && $arr->result === $child->expr) {
                            $emptyInits[] = $child->var;
                        }
                    }
                    $walk($child);
                }
            }
            if ($node instanceof Op\Stmt\JumpIf) {
                $walk($node->if);
                $walk($node->else);
            }
            if ($node instanceof Op\Stmt\Loop) {
                $walk($node->loop);
            }
            if ($node instanceof Op\Stmt\Foreach_) {
                $walk($node->loop);
            }
        };
        $walk($func->cfg);

        return 1 === count($emptyInits) ? $emptyInits[0] : null;
    }
}
