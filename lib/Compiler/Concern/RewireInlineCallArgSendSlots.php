<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;

use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * Inline call-arg SEND-slot rewires and adjacent feed helpers (#36387 / #36403).
 *
 * Extracted from {@see \PHPCompiler\Compiler} so the hub can shrink toward
 * host-CFG split-TU emit under SPINE_CHUNK (gen-0 <30m).
 *
 * Covers {@see hoistedSiblingFeedsLaterMultiArgConsumer}, the rewire*ArgSendSlots
 * family (array_keys/combine, var_export, bitmask, substr/sprintf, …), and
 * {@see slotForInlineExprResultInProducerOps}.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; call-arg
 * slot wiring relies on coercion (same as InlineCallArgSlotResolvers).
 */
trait RewireInlineCallArgSendSlots
{
    /**
     * php-cfg var_dump(strlen($s), substr($s, 0, 2)) — hoisted sibling producers need EXEC_RETURN (#16254).
     */
    private function hoistedSiblingFeedsLaterMultiArgConsumer(?Op $cfgCallOp, Block $block): bool
    {
        if (
            !$cfgCallOp instanceof Op\Expr\FuncCall
            && !$cfgCallOp instanceof Op\Expr\NsFuncCall
        ) {
            return false;
        }
        if (null === $block->orig) {
            return false;
        }
        $cfgChildren = $block->orig->children;
        $producerIndex = $this->cfgCallOpIndexInChildren($cfgChildren, $cfgCallOp);
        if (!\is_int($producerIndex)) {
            return false;
        }
        // Only consumers in the near sibling window — scanning every later multi-arg call made
        // nested stmt blocks O(n²) isSibling rejects (#36387).
        $n = \count($cfgChildren);
        $scanEnd = min($n, $producerIndex + 1 + 32);
        for ($consumerIndex = $producerIndex + 1; $consumerIndex < $scanEnd; ++$consumerIndex) {
            $consumer = $cfgChildren[$consumerIndex] ?? null;
            if (!$this->isSiblingMultiArgInlineCallConsumer($consumer)) {
                continue;
            }
            if (!\is_array($consumer->args ?? null) || \count($consumer->args) < 2) {
                continue;
            }
            if ($this->isSiblingMultiArgFuncCallProducer(
                $cfgCallOp,
                $consumer,
                $producerIndex,
                $consumerIndex,
                $cfgChildren
            )) {
                return true;
            }
        }

        return false;
    }

    /**
     * array_filter(explode(...), fn(...)) — hoisted haystack FuncCall before callback-at-arg-1 consumer (#17948).
     */
    private function inlineClosurePairHaystackFuncCallNeedsReturnSlot(?Op $cfgCallOp, Block $block): bool
    {
        if (
            !$cfgCallOp instanceof Op\Expr\FuncCall
            && !$cfgCallOp instanceof Op\Expr\NsFuncCall
        ) {
            return false;
        }
        if (null === $block->orig) {
            return false;
        }
        $cfgChildren = $block->orig->children;
        $producerIndex = $this->cfgCallOpIndexInChildren($cfgChildren, $cfgCallOp);
        if (!\is_int($producerIndex)) {
            return false;
        }
        // Bound: haystack + callback + consumer sit in a near window (#36387).
        $n = \count($cfgChildren);
        $scanEnd = min($n, $producerIndex + 1 + 32);
        for ($consumerIndex = $producerIndex + 1; $consumerIndex < $scanEnd; ++$consumerIndex) {
            $consumer = $cfgChildren[$consumerIndex] ?? null;
            if (!$this->isSiblingMultiArgInlineCallConsumer($consumer)) {
                continue;
            }
            $consumerName = $this->resolveCfgFuncCallName($consumer);
            if (1 !== $this->inlineClosureArrayPairCallbackArgIndex($consumerName)) {
                continue;
            }
            if (!\is_array($consumer->args ?? null) || \count($consumer->args) < 2) {
                continue;
            }
            $hasCallbackBetween = false;
            for ($j = $producerIndex + 1; $j < $consumerIndex; ++$j) {
                $mid = $cfgChildren[$j] ?? null;
                if ($mid instanceof Op\Expr\ArrowFunction
                    || $mid instanceof Op\Expr\Closure
                    || $mid instanceof Op\Expr\FirstClassCallable) {
                    $hasCallbackBetween = true;
                    break;
                }
            }
            if (!$hasCallbackBetween) {
                continue;
            }
            $haystack = $this->trailingInlineFuncCallHaystackBeforeCfgCall($consumer, $block);
            if ($haystack === $cfgCallOp) {
                return true;
            }
        }

        return false;
    }

    /**
     * php-cfg f(g()) hoisted before multi-arg consumer — outer f needs EXEC_RETURN (#15488).
     */
    private function isAdjacentOuterHoistedFuncCallBeforeMultiArgConsumer(?Op $cfgCallOp, Block $block): bool
    {
        if (
            !$cfgCallOp instanceof Op\Expr\FuncCall
            && !$cfgCallOp instanceof Op\Expr\NsFuncCall
        ) {
            return false;
        }
        if (null === $block->orig) {
            return false;
        }
        $cfgChildren = $block->orig->children;
        $producerIndex = $this->cfgCallOpIndexInChildren($cfgChildren, $cfgCallOp);
        if (!is_int($producerIndex) || $producerIndex < 1) {
            return false;
        }
        $inner = $cfgChildren[$producerIndex - 1] ?? null;
        if (
            !$inner instanceof Op\Expr\FuncCall
            && !$inner instanceof Op\Expr\NsFuncCall
        ) {
            return false;
        }
        if (!$this->isAdjacentNestedFuncCallProducer($inner, $cfgCallOp, $producerIndex - 1, $producerIndex)) {
            return false;
        }
        // Bound: multi-arg consumer for f(g()) sits near the outer producer (#36387).
        $n = \count($cfgChildren);
        $scanEnd = min($n, $producerIndex + 1 + 32);
        for ($consumerIndex = $producerIndex + 1; $consumerIndex < $scanEnd; ++$consumerIndex) {
            $consumer = $cfgChildren[$consumerIndex] ?? null;
            if (!$this->isSiblingMultiArgInlineCallConsumer($consumer)) {
                continue;
            }
            if (!property_exists($consumer, 'args') || !\is_array($consumer->args) || \count($consumer->args) < 2) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * php-cfg `json_decode(str_repeat(...), true, 512, JSON_THROW_ON_ERROR)` — hoisted FuncCall
     * before ConstFetch literal preludes must EXEC_RETURN (#12009, #15441).
     */
    private function nestedLiteralPreludeInlineCallProducerNeedsReturnSlot(?Op $cfgCallOp, Block $block): bool
    {
        if (!$cfgCallOp instanceof Op\Expr || null === $block->orig) {
            return false;
        }
        $cfgChildren = $block->orig->children;
        $producerIndex = $this->cfgCallOpIndexInChildren($cfgChildren, $cfgCallOp, $block->orig);
        if (!\is_int($producerIndex)) {
            return false;
        }
        // Bound: literal preludes + consumer stay in a near window (#36387).
        $n = \count($cfgChildren);
        $scanEnd = min($n, $producerIndex + 1 + 32);
        for ($i = $producerIndex + 1; $i < $scanEnd; ++$i) {
            $consumer = $cfgChildren[$i];
            if (
                $consumer instanceof Op\Expr\ConstFetch
                || $consumer instanceof Op\Expr\ClassConstFetch
                || $consumer instanceof Op\Expr\Array_
            ) {
                continue;
            }
            if ($this->isUnaryInlineSiblingCallArgExpr($consumer)) {
                continue;
            }
            if (!$this->isInlineExprCallArgConsumer($consumer)) {
                return false;
            }

            return $this->isNestedCallArgProducerSeparatedByConsumerLiteralPreludes(
                $cfgCallOp,
                $consumer,
                $producerIndex,
                $i,
                $cfgChildren
            );
        }

        return false;
    }

    private function cfgCallIsHoistedArrayKeysForArrayCombine(?Op $cfgCallOp, Block $block): bool
    {
        if (null === $cfgCallOp || null === $block->orig) {
            return false;
        }
        if (!$cfgCallOp instanceof Op\Expr\FuncCall && !$cfgCallOp instanceof Op\Expr\NsFuncCall) {
            return false;
        }
        if ('array_keys' !== $this->resolveCfgFuncCallName($cfgCallOp)) {
            return false;
        }
        foreach ($block->orig->children as $child) {
            if (!$child instanceof Op\Expr\FuncCall && !$child instanceof Op\Expr\NsFuncCall) {
                continue;
            }
            if ('array_combine' !== $this->resolveCfgFuncCallName($child)) {
                continue;
            }
            $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $child);
            $matched = $this->matchInlineCallArgProducer(
                $producers,
                $child->args ?? [],
                0,
                $child,
                $block,
                'array_combine'
            );
            if ($matched === $cfgCallOp) {
                return true;
            }
        }

        return false;
    }

    /**
     * php-cfg `take('a', fseek(...), 0)` — adjacent hoisted producer needs EXEC_RETURN (#13451).
     */
    private function cfgCallImmediatelyFeedsAdjacentConsumer(?Op $cfgCallOp, Block $block): bool
    {
        if (null === $cfgCallOp || null === $block->orig) {
            return false;
        }
        $cfgChildren = $block->orig->children;
        $producerIndex = null;
        foreach ($cfgChildren as $i => $child) {
            if ($child === $cfgCallOp) {
                $producerIndex = $i;
                break;
            }
        }
        if (null === $producerIndex) {
            return false;
        }
        if (!$cfgCallOp instanceof Op\Expr) {
            return false;
        }
        $consumerIndex = $producerIndex + 1;
        // Skip dim-fetch / scalar preludes that feed other call args (#36380):
        // show(id($t), $t[0]) — id must EXEC_RETURN despite ArrayDimFetch between.
        $scanEnd = min(\count($cfgChildren), $producerIndex + 1 + 8);
        while ($consumerIndex < $scanEnd) {
            $gap = $cfgChildren[$consumerIndex] ?? null;
            if (
                $gap instanceof Op\Expr\ArrayDimFetch
                || $gap instanceof Op\Expr\ConstFetch
                || $gap instanceof Op\Expr\ClassConstFetch
                || $this->isUnaryInlineSiblingCallArgExpr($gap)
            ) {
                ++$consumerIndex;
                continue;
            }
            break;
        }
        $consumer = $cfgChildren[$consumerIndex] ?? null;
        if (
            null !== $consumer
            && $this->isInlineExprCallArgConsumer($consumer)
            && (
                $this->isAdjacentNestedFuncCallProducer(
                    $cfgCallOp,
                    $consumer,
                    $producerIndex,
                    $consumerIndex
                )
                || (
                    $consumerIndex > $producerIndex + 1
                    && $this->nestedFuncCallProducerSeparatedByDimFetchPreludesOnly(
                        $producerIndex,
                        $consumerIndex,
                        $cfgChildren
                    )
                    && property_exists($consumer, 'args')
                    && \is_array($consumer->args)
                    && \count($consumer->args) >= 2
                    && $this->deadInlineTemporaryArgCount($consumer) >= 1
                )
            )
        ) {
            return true;
        }

        return $this->siblingFuncCallFeedsVarExportConsumer($cfgCallOp, $block, $producerIndex)
            || $this->siblingMethodCallFeedsVarExportConsumer($cfgCallOp, $block, $producerIndex)
            || $this->hoistedProducerFeedsConsumerThroughLiteralGap($cfgCallOp, $block);
    }

    /**
     * php-cfg `in_array('x', g(), true)` — lone hoisted FuncCall before literal prelude + consumer (#15438, #13507).
     *
     * @param list<Op> $cfgChildren
     */
    private function nearestHoistedFuncCallProducerBeforeConsumer(int $consumerIndex, array $cfgChildren): ?int
    {
        for ($i = $consumerIndex - 1; $i >= 0; --$i) {
            $child = $cfgChildren[$i] ?? null;
            if (
                $child instanceof Op\Expr\ConstFetch
                || $child instanceof Op\Expr\ClassConstFetch
                || $child instanceof Op\Expr\Array_
            ) {
                continue;
            }
            if ($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall) {
                return $i;
            }

            break;
        }

        return null;
    }

    /**
     * php-cfg hoists zero-arg builtins before multi-arg consumers with ConstFetch preludes (#15438, #13507).
     */
    private function hoistedProducerFeedsConsumerThroughLiteralGap(Op $cfgCallOp, Block $block): bool
    {
        if (!$cfgCallOp instanceof Op\Expr || null === $block->orig) {
            return false;
        }
        $cfgChildren = $block->orig->children;
        $producerIndex = null;
        foreach ($cfgChildren as $i => $child) {
            if ($child === $cfgCallOp) {
                $producerIndex = $i;
                break;
            }
        }
        if (null === $producerIndex) {
            return false;
        }
        // Bound: literal/property gap + consumer stay near the producer (#36387).
        $n = \count($cfgChildren);
        $scanEnd = min($n, $producerIndex + 1 + 32);
        for ($i = $producerIndex + 1; $i < $scanEnd; ++$i) {
            $child = $cfgChildren[$i];
            if (
                $child instanceof Op\Expr\ConstFetch
                || $child instanceof Op\Expr\ClassConstFetch
                || $child instanceof Op\Expr\Array_
                // insertBefore($d->createElement('x'), $r->lastChild) — PropertyFetch between (#19719).
                || $child instanceof Op\Expr\PropertyFetch
                || $child instanceof Op\Expr\NullsafePropertyFetch
            ) {
                continue;
            }
            if ($this->isUnaryInlineSiblingCallArgExpr($child)) {
                continue;
            }
            if (!$this->isInlineExprCallArgConsumer($child)) {
                return false;
            }
            if (!property_exists($child, 'args') || !\is_array($child->args)) {
                return false;
            }
            $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($cfgChildren, $child);
            foreach ($child->args as $argIndex => $callArg) {
                if (!$this->callArgIsDeadInlineTemporary($callArg)) {
                    continue;
                }
                $matched = $this->matchInlineCallArgProducer(
                    $producers,
                    $child->args,
                    (int) $argIndex,
                    $child,
                    $block
                );
                if ($matched === $cfgCallOp) {
                    return true;
                }
            }

            return false;
        }

        return false;
    }

    /**
     * php-cfg `var_export(key($a), true)` hoists key() sibling; ConstFetch `true` may sit between (#13829).
     */
    private function siblingFuncCallFeedsVarExportConsumer(Op $cfgCallOp, Block $block, int $producerIndex): bool
    {
        if (!$this->isSiblingInlineCallProducerExpr($cfgCallOp)) {
            return false;
        }
        $cfgChildren = $block->orig->children;
        // Bound: ConstFetch true + var_export sit near the producer (#36387).
        $n = \count($cfgChildren);
        $scanEnd = min($n, $producerIndex + 1 + 32);
        for ($i = $producerIndex + 1; $i < $scanEnd; ++$i) {
            $child = $cfgChildren[$i];
            if (
                $child instanceof Op\Expr\ConstFetch
                || $child instanceof Op\Expr\ClassConstFetch
                || $child instanceof Op\Expr\Array_
            ) {
                continue;
            }
            if (
                !$child instanceof Op\Expr\FuncCall
                && !$child instanceof Op\Expr\NsFuncCall
            ) {
                return false;
            }
            if ('var_export' !== $this->resolveCfgFuncCallName($child)) {
                return false;
            }
            $callArg = $child->args[0] ?? null;

            return null !== $callArg && $this->callArgIsDeadInlineTemporary($callArg);
        }

        return false;
    }

    /**
     * php-cfg `var_export($it->current(), true)` hoists MethodCall sibling; ConstFetch `true` may sit between (#13901).
     */
    private function siblingMethodCallFeedsVarExportConsumer(Op $cfgCallOp, Block $block, int $producerIndex): bool
    {
        if (!$cfgCallOp instanceof Op\Expr\MethodCall && !$cfgCallOp instanceof Op\Expr\StaticCall) {
            return false;
        }
        $cfgChildren = $block->orig->children;
        // Bound: ConstFetch true + var_export sit near the producer (#36387).
        $n = \count($cfgChildren);
        $scanEnd = min($n, $producerIndex + 1 + 32);
        for ($i = $producerIndex + 1; $i < $scanEnd; ++$i) {
            $child = $cfgChildren[$i];
            if (
                $child instanceof Op\Expr\ConstFetch
                || $child instanceof Op\Expr\ClassConstFetch
                || $child instanceof Op\Expr\Array_
            ) {
                continue;
            }
            if (
                !$child instanceof Op\Expr\FuncCall
                && !$child instanceof Op\Expr\NsFuncCall
            ) {
                return false;
            }
            if ('var_export' !== $this->resolveCfgFuncCallName($child)) {
                return false;
            }
            $callArg = $child->args[0] ?? null;

            return null !== $callArg && $this->callArgIsDeadInlineTemporary($callArg);
        }

        return false;
    }

    /**
     * php-cfg splits `(void) f()` into adjacent FuncCall + Cast_Void with distinct SSA temps (#9779).
     * Use EXEC_RETURN so #[\NoDiscard] sees an intentional discard (Zend zend_execute.c).
     */
    private function cfgCallOpImmediatelyVoidDiscarded(?Op $cfgCallOp, Block $block): bool
    {
        if (null === $cfgCallOp || null === $block->orig) {
            return false;
        }
        $ops = $block->orig->children;
        $count = \count($ops);
        for ($i = 0; $i < $count - 1; ++$i) {
            if ($ops[$i] !== $cfgCallOp) {
                continue;
            }

            return $ops[$i + 1] instanceof Op\Expr\Cast\Void_;
        }

        return false;
    }

    /**
     * Hoisted inline nested FuncCall producers compile into $argSends — emit them before outer INIT (#13636).
     *
     * @param list<OpCode> $argSends
     *
     * @return array{0: list<OpCode>, 1: list<OpCode>}
     */
    private function partitionNestedInlineCallArgProducerOps(array $argSends): array
    {
        $nested = [];
        $outer = [];
        $count = \count($argSends);
        for ($i = 0; $i < $count; ++$i) {
            $op = $argSends[$i];
            if (!$this->isInlineCallArgProducerInitOpcode($op)) {
                $outer[] = $op;
                continue;
            }
            $depth = 1;
            $chunk = [$op];
            ++$i;
            while ($i < $count && $depth > 0) {
                $inner = $argSends[$i];
                $chunk[] = $inner;
                if ($this->isInlineCallArgProducerInitOpcode($inner)) {
                    ++$depth;
                } elseif ($this->isInlineCallArgProducerExecOpcode($inner)) {
                    --$depth;
                }
                ++$i;
            }
            --$i;
            foreach ($chunk as $nestedOp) {
                $nested[] = $nestedOp;
            }
        }

        return [$nested, $outer];
    }

    /**
     * array_diff_assoc(array_keys(), array_keys()) — deferred sibling batch may miswire INIT_ARRAY ordinal (#16418).
     *
     * @param list<OpCode> $outerArgSends
     */
    private function rewireArrayKeysInlineInitArrayArgSendSlots(
        array &$outerArgSends,
        Block $block,
        ?Op $cfgCallOp,
        ?string $calleeName,
        array $pendingOps = []
    ): void {
        if (null === $cfgCallOp || 'array_keys' !== strtolower($calleeName ?? $this->resolveCfgFuncCallName($cfgCallOp) ?? '')) {
            return;
        }
        $callArg = $cfgCallOp->args[0] ?? null;
        if (!$callArg instanceof Operand) {
            return;
        }
        if (
            !$this->callArgIsDeadInlineTemporary($callArg)
            || !$this->callArgOperandExpectsArrayProducer($callArg)
            || $this->callArgIsCoalesceMergeProducer($callArg, $block, $cfgCallOp, 0)
        ) {
            return;
        }
        if ($this->callArgIsCoalesceMergeProducer($callArg, $block, $cfgCallOp, 0)) {
            // array_keys(f()[k] ?? []) — keep coalesce merge slot, not ?? RHS INIT_ARRAY (#16127, re-#16435).
            return;
        }
        // array_keys(array_flip([...])) / array_keys($ao->getArrayCopy()) — adjacent call result
        // feeds arg #0; do not steal nested INIT_ARRAY (#21981, #25812).
        if (null !== $block->orig) {
            $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
            if (\is_int($callIndex) && $callIndex > 0) {
                $adjacentIndex = $callIndex - 1;
                while ($adjacentIndex >= 0) {
                    $skip = $block->orig->children[$adjacentIndex] ?? null;
                    if ($skip instanceof Op\Expr\ConstFetch || $skip instanceof Op\Expr\ClassConstFetch) {
                        --$adjacentIndex;
                        continue;
                    }
                    if ($this->isUnaryInlineSiblingCallArgExpr($skip)) {
                        --$adjacentIndex;
                        continue;
                    }
                    break;
                }
                $adjacent = $block->orig->children[$adjacentIndex] ?? null;
                if (
                    $adjacent instanceof Op\Expr\FuncCall
                    || $adjacent instanceof Op\Expr\NsFuncCall
                    || $adjacent instanceof Op\Expr\MethodCall
                    || $adjacent instanceof Op\Expr\StaticCall
                    // array_keys((array)$ao) — Cast result already wired; do not steal ctor INIT_ARRAY (#28822).
                    || $adjacent instanceof Op\Expr\Cast
                ) {
                    return;
                }
            }
        }
        $correctOrdinal = $this->inlineArrayKeysHoistedArrayOrdinal($block, $cfgCallOp);
        $correctSlot = null !== $correctOrdinal
            ? $this->slotForInitArrayOrdinal($block, $correctOrdinal, $pendingOps)
            : null;
        if (null === $correctSlot) {
            $correctSlot = $this->slotForInitArrayProducerBeforeCfgCall(
                $block,
                $cfgCallOp,
                $this->inlineArrayProducerForArrayKeysDeadCallArg($callArg, $block, $cfgCallOp),
                $pendingOps
            );
        }
        if (null === $correctSlot) {
            return;
        }
        foreach ($outerArgSends as $send) {
            if (OpCode::TYPE_ARG_SEND !== $send->type) {
                continue;
            }
            $send->arg1 = $correctSlot;

            return;
        }
    }

    /**
     * decoct(fileperms($f) & 0777) on CFG branch blocks — ARG_SEND must use the AND dest (#15902).
     *
     * Only when the arithmetic producer immediately precedes this call. A sibling
     * `get(Box::Y)+1` leaves TYPE_PLUS in the merge block; the next `get(Box::Z)` must
     * keep its ClassConstFetch slot, not steal the plus result (#26990).
     *
     * @param list<OpCode> $outerArgSends
     * @param list<OpCode> $nestedProducerOps
     */
    private function rewireInlineArithmeticBranchCallArgSendSlots(
        array &$outerArgSends,
        array $nestedProducerOps,
        Block $block,
        ?Op $cfgCallOp
    ): void {
        if ((!$block->inheritUndefinedLocals && !$block->arrowAutoCapture) || null === $cfgCallOp) {
            return;
        }
        $callArg = $cfgCallOp->args[0] ?? null;
        if (
            1 !== \count($cfgCallOp->args ?? [])
            || !$this->callArgIsDeadInlineTemporary($callArg)
            || $this->callArgIsCoalesceMergeProducer($callArg, $block, $cfgCallOp, 0)
        ) {
            return;
        }
        // Intervening ClassConstFetch/ConstFetch before this call — not decoct(expr&mask) (#26990).
        if (!$this->cfgCallImmediatelyConsumesPrecedingArithmetic($block, $cfgCallOp)) {
            return;
        }
        $dest = $this->slotForRecentInlineArithmeticCallArg(
            $block,
            array_merge($nestedProducerOps, $outerArgSends)
        );
        if (null === $dest) {
            return;
        }
        foreach ($outerArgSends as $send) {
            if (OpCode::TYPE_ARG_SEND === $send->type) {
                $send->arg1 = $dest;
            }
        }
    }

    /**
     * True when the CFG child immediately before $cfgCallOp is an arithmetic/bitwise
     * producer feeding that call (#15902 decoct; negative for #26990 ClassConstFetch).
     */
    private function cfgCallImmediatelyConsumesPrecedingArithmetic(Block $block, Op $cfgCallOp): bool
    {
        $callIndex = $this->cfgCallOpIndex($block, $cfgCallOp);
        if (!\is_int($callIndex) || $callIndex < 1 || null === $block->orig) {
            return false;
        }
        $prev = $block->orig->children[$callIndex - 1] ?? null;

        return $this->isArithmeticInlineCallArgProducer($prev);
    }

    /**
     * substr(sprintf('%o', fileperms($path)), -N) after stmt-level calls — haystack ARG_SEND (#16451, #16480).
     *
     * @param list<OpCode> $outerArgSends
     * @param list<OpCode> $pendingNestedProducerOps
     */
    private function rewireSubstrNestedSprintfArgSendSlots(
        array &$outerArgSends,
        Block $block,
        ?Op $cfgCallOp,
        ?string $calleeName = null
    ): void {
        if (null === $cfgCallOp || null === $block->orig) {
            return;
        }
        if (!\is_array($cfgCallOp->args ?? null) || \count($cfgCallOp->args) < 2) {
            return;
        }
        if ('substr' !== strtolower($this->resolveInlineCallArgFuncName($cfgCallOp, $calleeName) ?? '')) {
            return;
        }
        $callIndex = $this->cfgCallOpIndex($block, $cfgCallOp);
        if (!\is_int($callIndex)) {
            return;
        }
        $cfgChildren = $block->orig->children;
        $nestedHaystack = $this->substrNestedHaystackFuncCallAtUnaryMinusPattern(
            $cfgCallOp,
            $callIndex,
            $cfgChildren
        );
        if (null === $nestedHaystack) {
            return;
        }
        $haystackSlot = $this->slotForSubstrNestedHaystackFuncCallExecReturn(
            $block,
            $nestedHaystack[0],
            $nestedHaystack[1],
            $cfgChildren
        );
        if (null === $haystackSlot) {
            return;
        }
        $offsetOp = $cfgChildren[$callIndex - 1] ?? null;
        $argSendIndex = 0;
        foreach ($outerArgSends as $send) {
            if (OpCode::TYPE_ARG_SEND !== $send->type) {
                continue;
            }
            if (0 === $argSendIndex) {
                $send->arg1 = $haystackSlot;
            } elseif (
                1 === $argSendIndex
                && $offsetOp instanceof Op\Expr\UnaryMinus
            ) {
                $inner = $offsetOp->expr ?? null;
                if ($inner instanceof Operand\Literal && is_numeric($inner->value)) {
                    $negated = is_int($inner->value) ? -(int) $inner->value : -(float) $inner->value;
                    $send->arg1 = (string) $this->freshLiteralConstantSlot(
                        new Operand\Literal($negated),
                        $block
                    );
                }
            }
            ++$argSendIndex;
        }
    }

    /**
     * tempnam(sys_get_temp_dir(), E::A) — nested FuncCall EXEC_RETURN is arg #0; enum ClassConstFetch is arg #1 (#10303, #16558).
     *
     * @param list<OpCode> $outerArgSends
     * @param list<OpCode> $pendingNestedProducerOps
     */
    private function rewireNestedFuncCallEnumPrefixCallArgSendSlots(
        array &$outerArgSends,
        Block $block,
        Op $cfgCallOp,
        array $pendingNestedProducerOps = []
    ): void {
        if (null === $block->orig || 2 !== \count($cfgCallOp->args ?? [])) {
            return;
        }
        $callIndex = $this->cfgCallOpIndex($block, $cfgCallOp);
        if (!\is_int($callIndex) || $callIndex < 2) {
            return;
        }
        $nestedCall = $block->orig->children[$callIndex - 2] ?? null;
        $enumFetch = $block->orig->children[$callIndex - 1] ?? null;
        if (
            !($nestedCall instanceof Op\Expr\FuncCall || $nestedCall instanceof Op\Expr\NsFuncCall)
            || !$enumFetch instanceof Op\Expr\ClassConstFetch
            || !$this->nestedFuncCallProducerSeparatedBySkippablePreludesOnly(
                $callIndex - 2,
                $callIndex,
                $block->orig->children
            )
        ) {
            return;
        }
        $execReturnCount = $block->funccallExecReturnCount();
        foreach ($pendingNestedProducerOps as $op) {
            if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type && null !== $op->arg1) {
                ++$execReturnCount;
            }
        }
        $execSlot = $this->slotForSiblingInlineFuncCallProducerExecReturnOrdinalWithPending(
            $block,
            max(0, $execReturnCount - 1),
            $pendingNestedProducerOps
        );
        if (null === $execSlot) {
            $execSlot = $block->slotForOperand($nestedCall->result);
        }
        $enumSlot = $block->slotForOperand($enumFetch->result);
        if (null === $enumSlot) {
            foreach ($this->compileExpr($enumFetch, $block) as $op) {
                $block->addOpCode($op);
            }
            $enumSlot = $block->slotForOperand($enumFetch->result);
        }
        if (null === $execSlot || null === $enumSlot) {
            return;
        }
        $argIndex = 0;
        foreach ($outerArgSends as $send) {
            if (OpCode::TYPE_ARG_SEND !== $send->type) {
                continue;
            }
            if (0 === $argIndex) {
                $send->arg1 = (string) $execSlot;
            } elseif (1 === $argIndex) {
                $send->arg1 = (string) $enumSlot;
            }
            ++$argIndex;
        }
    }

    /**
     * count($ref->getAttributes(...)) — wire MethodCall EXEC_RETURN into the outer ARG_SEND (#21867, #22693).
     *
     * Covers filtered getAttributes(Foo::class) (hoisted ClassConstFetch prelude) and bare
     * getAttributes() (no prelude). Without this, ARG_SEND keeps an earlier dead temp (often a
     * prior ::class string / null) and count() TypeErrors on null.
     *
     * @param list<OpCode> $outerArgSends
     * @param list<OpCode> $pendingNestedProducerOps
     */
    private function rewireNestedMethodCallHoistedClassConstOuterCallArgSendSlots(
        array &$outerArgSends,
        Block $block,
        ?Op $cfgCallOp,
        array $pendingNestedProducerOps = []
    ): void {
        if (null === $cfgCallOp || null === $block->orig) {
            return;
        }
        if (!\is_array($cfgCallOp->args ?? null) || 1 !== \count($cfgCallOp->args)) {
            return;
        }
        $callArg = $cfgCallOp->args[0] ?? null;
        if (!$this->callArgIsDeadInlineTemporary($callArg)) {
            return;
        }
        $callIndex = $this->cfgCallOpIndex($block, $cfgCallOp);
        if (!\is_int($callIndex) || $callIndex < 1) {
            return;
        }
        $producer = $block->orig->children[$callIndex - 1] ?? null;
        if (
            !$producer instanceof Op\Expr\MethodCall
            && !$producer instanceof Op\Expr\StaticCall
        ) {
            return;
        }
        // Filtered form: ClassConstFetch immediately before MethodCall feeds the method arg.
        // If the outer dead temp is that ClassConstFetch result, wiring is already correct.
        if ($callIndex >= 2) {
            $prelude = $block->orig->children[$callIndex - 2] ?? null;
            if (
                $prelude instanceof Op\Expr\ClassConstFetch
                && $callArg instanceof Operand
                && $this->operandsReferToSameVariable($prelude->result, $callArg)
            ) {
                return;
            }
        }
        $execSlot = $this->slotForMethodOrStaticCallInitFollowingExecReturn(
            $block,
            $producer,
            $pendingNestedProducerOps
        );
        if (null === $execSlot) {
            $execSlot = $this->slotForSiblingMethodCallProducerExecReturn(
                $block,
                $producer,
                $cfgCallOp,
                $block->orig->children
            );
        }
        if (null === $execSlot) {
            return;
        }
        foreach ($outerArgSends as $send) {
            if (OpCode::TYPE_ARG_SEND !== $send->type) {
                continue;
            }
            $send->arg1 = (string) $execSlot;

            return;
        }
    }

    /**
     * var_dump(f(), g()) after an earlier sibling chain — map ARG_SEND to chain EXEC_RETURN slots (#16254).
     *
     * @param list<OpCode> $outerArgSends
     */
    private function rewireSiblingMultiArgInlineCallArgSendSlots(
        array &$outerArgSends,
        Block $block,
        ?Op $cfgCallOp,
        array $pendingNestedProducerOps = []
    ): void {
        if (null === $cfgCallOp || null === $block->orig) {
            return;
        }
        if (!\is_array($cfgCallOp->args ?? null) || \count($cfgCallOp->args) < 2) {
            return;
        }
        $callIndex = $this->cfgCallOpIndex($block, $cfgCallOp);
        if (!\is_int($callIndex)) {
            return;
        }
        $cfgChildren = $block->orig->children;
        if ($this->isSubstrNestedSprintfUnaryMinusPattern($cfgCallOp, $callIndex, $cfgChildren)) {
            return;
        }
        $firstSibling = $this->firstContiguousSiblingMultiArgProducerIndex(
            $callIndex,
            $cfgCallOp,
            $cfgChildren
        );
        if (!\is_int($firstSibling)) {
            $firstSibling = $this->firstSiblingInlineFuncCallProducerIndexImpl(
                $callIndex,
                $cfgChildren
            );
        }
        if (!\is_int($firstSibling) || ($callIndex - $firstSibling) < 2) {
            return;
        }
        // A::inc(); A::inc(); var_dump(A::$n, B::$n) — do not rewire ARG_SEND onto stmt
        // StaticCall EXEC_RETURN when StaticPropertyFetch covers the dead-temp args (#34997).
        if ($this->interveningFetchProducersCoverDeadTempCallArgs(
            $firstSibling,
            $callIndex,
            $cfgChildren,
            $cfgCallOp
        )) {
            return;
        }
        $chainProducerCount = $this->countContiguousSiblingMultiArgProducers(
            $firstSibling,
            $callIndex,
            $cfgCallOp,
            $cfgChildren
        );
        if ($chainProducerCount < 2) {
            if (!$this->nestedFuncCallFeedsDeadInlineCallArgZero($block, $cfgCallOp, 0)) {
                return;
            }
            $this->rewireNestedFuncCallEnumPrefixCallArgSendSlots(
                $outerArgSends,
                $block,
                $cfgCallOp,
                $pendingNestedProducerOps
            );

            return;
        }
        $execReturnCount = $block->funccallExecReturnCount();
        foreach ($pendingNestedProducerOps as $op) {
            if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type && null !== $op->arg1) {
                ++$execReturnCount;
            }
        }
        $argIndex = 0;
        $callbackArgIndex = $this->inlineClosureArrayPairCallbackArgIndex(
            $this->resolveCfgFuncCallName($cfgCallOp)
        );
        foreach ($outerArgSends as $send) {
            if (OpCode::TYPE_ARG_SEND !== $send->type) {
                continue;
            }
            if (
                $callbackArgIndex >= 0
                && $argIndex === $callbackArgIndex
                && null !== $this->leadingCallbackFirstInlineProducerBeforeCfgCall($cfgCallOp, $block)
            ) {
                // array_map(intval(...), str_split(str_repeat(...))) — keep FCC slot, not haystack EXEC_RETURN (#16279).
                ++$argIndex;
                continue;
            }
            if (
                0 === $argIndex
                && 1 === $callbackArgIndex
                && 2 === \count($cfgCallOp->args ?? [])
            ) {
                $trailingHaystack = $this->trailingInlineFuncCallHaystackBeforeCfgCall($cfgCallOp, $block);
                if (
                    $trailingHaystack instanceof Op\Expr\FuncCall
                    || $trailingHaystack instanceof Op\Expr\NsFuncCall
                ) {
                    // array_filter(str_split(...), …) after a prior filter+var_dump — bind haystack
                    // EXEC_RETURN explicitly; sibling ordinal steals var_dump (#27344 / #15490).
                    $haystackSlot = $block->slotForOperand($trailingHaystack->result);
                    if (null === $haystackSlot) {
                        $haystackIndex = array_search($trailingHaystack, $cfgChildren, true);
                        $haystackSlot = \is_int($haystackIndex)
                            ? $this->slotForInlineFuncCallProducerExecReturnByCfgIndex(
                                $block,
                                $haystackIndex,
                                $cfgChildren
                            )
                            : null;
                    }
                    if (null !== $haystackSlot) {
                        $send->arg1 = (string) $haystackSlot;
                    }
                    ++$argIndex;
                    continue;
                }
            }
            $hoistedPrelude = $this->hoistedPreludeProducerForCallArgIndex($cfgCallOp, $argIndex, $block);
            if (
                $hoistedPrelude instanceof Op\Expr\ConstFetch
                || $hoistedPrelude instanceof Op\Expr\ClassConstFetch
            ) {
                // setlocale(LC_ALL, null) after earlier setlocale stmts — keep hoisted LC_* / null prelude (#10177).
                ++$argIndex;
                continue;
            }
            if ($this->callArgHasHoistedConstPrelude($cfgCallOp, $argIndex, $block)) {
                ++$argIndex;
                continue;
            }
            if ($this->opcodeSlotIsHoistedConstPrelude($block, $send->arg1, $pendingNestedProducerOps)) {
                ++$argIndex;
                continue;
            }
            $multiArgCallArg = $cfgCallOp->args[$argIndex] ?? null;
            if (!$this->callArgIsDeadInlineTemporary($multiArgCallArg)) {
                ++$argIndex;
                continue;
            }
            if (
                'var_export' === strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '')
                && $multiArgCallArg instanceof Operand
                && $this->isCallArgDirectArrayDimFetch($multiArgCallArg)
            ) {
                ++$argIndex;
                continue;
            }
            $preludeSlot = $this->slotForImmediateConstFetchPreludeCallArg($block, $cfgCallOp, $argIndex);
            if (null !== $preludeSlot) {
                $send->arg1 = (string) $preludeSlot;
                ++$argIndex;
                continue;
            }
            $producerIndex = null;
            for ($j = $firstSibling; $j < $callIndex; ++$j) {
                $scan = $cfgChildren[$j] ?? null;
                if (!$scan instanceof Op\Expr) {
                    continue;
                }
                $feedsArg = $this->isSiblingMultiArgFuncCallProducer(
                    $scan,
                    $cfgCallOp,
                    $j,
                    $callIndex,
                    $cfgChildren
                ) || $this->isNestedCallArgProducerForConsumer(
                    $scan,
                    $cfgCallOp,
                    $j,
                    $callIndex,
                    $cfgChildren
                );
                if (!$feedsArg) {
                    continue;
                }
                if (
                    $this->siblingMultiArgFuncCallProducerTargetArgIndex(
                        $j,
                        $callIndex,
                        $cfgChildren
                    ) === $argIndex
                ) {
                    $producerIndex = $j;
                    break;
                }
            }
            if (!\is_int($producerIndex)) {
                ++$argIndex;
                continue;
            }
            $consumerName = $this->resolveCfgFuncCallName($cfgCallOp);
            $callbackArgIndex = $this->inlineClosureArrayPairCallbackArgIndex($consumerName);
            $leadingCallback = $this->leadingCallbackFirstInlineProducerBeforeCfgCall($cfgCallOp, $block);
            if (
                $callbackArgIndex >= 0
                && 2 === \count($cfgCallOp->args ?? [])
                && $argIndex === $callbackArgIndex
                && ($leadingCallback instanceof Op\Expr\ArrowFunction
                    || $leadingCallback instanceof Op\Expr\Closure
                    || $leadingCallback instanceof Op\Expr\FirstClassCallable)
            ) {
                // array_map(intval(...), str_split(...)) — keep FCC/closure send slot (#15487, #16279).
                ++$argIndex;
                continue;
            }
            $siblingOrdinal = $this->siblingInlineFuncCallProducerOrdinal(
                $producerIndex,
                $firstSibling,
                $block->orig->children
            );
            $execOrdinal = $execReturnCount - $chainProducerCount + $siblingOrdinal;
            $slot = $this->slotForSiblingInlineFuncCallProducerExecReturnOrdinalWithPending(
                $block,
                $execOrdinal,
                $pendingNestedProducerOps
            );
            if (null !== $slot) {
                $send->arg1 = (string) $slot;
            }
            ++$argIndex;
        }
    }

    /**
     * Map dead inline call-arg temps to hoisted UnaryMinus/ConstFetch preludes before the callee (#16523).
     */
    private function hoistedDeadInlinePreludeProducerForCallArgIndex(Op $callOp, int $argIndex, Block $block): ?Op\Expr
    {
        if (!property_exists($callOp, 'args') || !\is_array($callOp->args)) {
            return null;
        }
        $callArg = $callOp->args[$argIndex] ?? null;
        if (!$this->callArgIsDeadInlineTemporary($callArg)) {
            return null;
        }
        if ($this->callArgOperandExpectsArrayProducer($callArg)) {
            return null;
        }
        $preludes = $this->hoistedPreludeProducersImmediatelyBeforeCall($callOp, $block);
        if ([] === $preludes) {
            return null;
        }
        $deadInlineArgCount = 0;
        foreach ($callOp->args as $deadArg) {
            if ($this->isEmbeddedCallLiteralArg($deadArg)) {
                continue;
            }
            if ($this->callArgIsDeadInlineTemporary($deadArg)) {
                ++$deadInlineArgCount;
            }
        }
        // in_array('x', g(), true) — hoisted FuncCall between trailing ConstFetch and consumer (#16540).
        if ($deadInlineArgCount !== \count($preludes)) {
            return null;
        }
        $preludeOrdinal = 0;
        foreach ($callOp->args as $i => $deadArg) {
            if ($this->isEmbeddedCallLiteralArg($deadArg)) {
                continue;
            }
            if (!$this->callArgIsDeadInlineTemporary($deadArg)) {
                continue;
            }
            if ($deadArg instanceof Operand && $this->callArgOperandExpectsArrayProducer($deadArg)) {
                continue;
            }
            if ($i === $argIndex) {
                $prelude = $preludes[$preludeOrdinal] ?? null;
                if (
                    ($prelude instanceof Op\Expr\ConstFetch || $prelude instanceof Op\Expr\ClassConstFetch)
                    && $callArg instanceof Operand
                    && $this->callArgOperandExpectsArrayProducer($callArg)
                    && null !== $block->orig
                ) {
                    // in_array('x', get_declared_classes(), true) — strict ConstFetch is not haystack (#16540, re-#16312).
                    $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $callOp);
                    $matched = $this->matchInlineCallArgProducer($producers, $callOp->args, $argIndex, $callOp, $block);
                    if (
                        $matched instanceof Op\Expr\FuncCall
                        || $matched instanceof Op\Expr\NsFuncCall
                        || $matched instanceof Op\Expr\StaticCall
                        || $matched instanceof Op\Expr\MethodCall
                    ) {
                        return $matched;
                    }

                    return null;
                }

                return $prelude instanceof Op\Expr ? $prelude : null;
            }
            ++$preludeOrdinal;
        }

        return null;
    }

    /**
     * Hoisted ConstFetch/ClassConstFetch immediately before a call feeds a dead inline arg (#10177).
     */
    private function callArgHasHoistedConstPrelude(Op $cfgCallOp, int $argIndex, Block $block): bool
    {
        if ('setlocale' !== strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '')) {
            return false;
        }
        if (!property_exists($cfgCallOp, 'args') || !\is_array($cfgCallOp->args)) {
            return false;
        }
        $preludes = $this->hoistedPreludeProducersImmediatelyBeforeCall($cfgCallOp, $block);
        if ([] === $preludes) {
            $preludes = $this->hoistedPreludeProducersBeforeAssignStmt($cfgCallOp, $block);
        }
        if ([] === $preludes) {
            return false;
        }
        $preludeOrdinal = 0;
        foreach ($cfgCallOp->args as $i => $callArg) {
            if ($this->isEmbeddedCallLiteralArg($callArg)) {
                continue;
            }
            if (!$this->callArgIsDeadInlineTemporary($callArg)) {
                continue;
            }
            if ($i === $argIndex) {
                $prelude = $preludes[$preludeOrdinal] ?? null;

                return $prelude instanceof Op\Expr\ConstFetch
                    || $prelude instanceof Op\Expr\ClassConstFetch;
            }
            ++$preludeOrdinal;
        }

        return false;
    }

    /**
     * ARG_SEND already wired to hoisted ConstFetch — do not replace with sibling EXEC_RETURN (#10177).
     *
     * @param list<OpCode> $pendingNestedProducerOps
     */
    private function opcodeSlotIsHoistedConstPrelude(
        Block $block,
        int|string|null $slot,
        array $pendingNestedProducerOps = []
    ): bool {
        if (null === $slot) {
            return false;
        }
        $slot = (string) $slot;
        foreach (array_merge($block->opCodes, $pendingNestedProducerOps) as $op) {
            if (
                (OpCode::TYPE_CONST_FETCH === $op->type || OpCode::TYPE_CLASS_CONST_FETCH === $op->type)
                && (string) $op->arg1 === $slot
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * method_exists(I::class, 'm') after earlier stmts — sibling rewire must not steal hoisted ::class slot (#9486).
     *
     * @param list<OpCode> $outerArgSends
     * @param list<OpCode> $pendingNestedProducerOps
     */
    /**
     * register_shutdown_function(fn(...), E::A) — Closure + enum case hoisted siblings (#5751).
     *
     * @param list<OpCode> $outerArgSends
     * @param list<OpCode> $pendingNestedProducerOps
     */
    private function rewireRegisterShutdownFunctionClosureEnumCallArgSendSlots(
        array &$outerArgSends,
        Block $block,
        ?Op $cfgCallOp,
        array $pendingNestedProducerOps = []
    ): void {
        if (null === $cfgCallOp || null === $block->orig) {
            return;
        }
        if ('register_shutdown_function' !== $this->resolveCfgFuncCallName($cfgCallOp)) {
            return;
        }
        if (!\is_array($cfgCallOp->args ?? null) || 2 !== \count($cfgCallOp->args)) {
            return;
        }
        $callIndex = $this->cfgCallOpIndex($block, $cfgCallOp);
        if (!\is_int($callIndex) || $callIndex < 2) {
            return;
        }
        $closureExpr = $block->orig->children[$callIndex - 2] ?? null;
        $enumExpr = $block->orig->children[$callIndex - 1] ?? null;
        if (!($closureExpr instanceof Op\Expr\Closure || $closureExpr instanceof Op\Expr\ArrowFunction)) {
            return;
        }
        if (!$enumExpr instanceof Op\Expr\ClassConstFetch) {
            return;
        }
        $closureSlot = $block->slotForOperand($closureExpr->result);
        $enumSlot = $block->slotForOperand($enumExpr->result);
        if (null === $closureSlot) {
            foreach ($pendingNestedProducerOps as $op) {
                if (OpCode::TYPE_CLOSURE === $op->type) {
                    $closureSlot = (string) $op->arg1;
                    break;
                }
            }
        }
        if (null === $enumSlot) {
            foreach ($pendingNestedProducerOps as $op) {
                if (OpCode::TYPE_CLASS_CONST_FETCH === $op->type) {
                    $enumSlot = (string) $op->arg1;
                    break;
                }
            }
        }
        if (null === $closureSlot || null === $enumSlot) {
            return;
        }
        $argIndex = 0;
        foreach ($outerArgSends as $send) {
            if (OpCode::TYPE_ARG_SEND !== $send->type) {
                continue;
            }
            $send->arg1 = 0 === $argIndex ? $closureSlot : $enumSlot;
            ++$argIndex;
        }
    }

    private function rewireHoistedClassConstPreludeCallArgSendSlots(
        array &$outerArgSends,
        Block $block,
        ?Op $cfgCallOp,
        array $pendingNestedProducerOps = []
    ): void {
        if (null === $cfgCallOp || null === $block->orig) {
            return;
        }
        if (!\is_array($cfgCallOp->args ?? null) || [] === $cfgCallOp->args) {
            return;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (!\is_int($callIndex) || $callIndex < 1) {
            return;
        }
        $prelude = $block->orig->children[$callIndex - 1] ?? null;
        if (!$prelude instanceof Op\Expr\ClassConstFetch) {
            return;
        }
        if ($this->nestedFuncCallFeedsDeadInlineCallArgZero($block, $cfgCallOp, 0)) {
            return;
        }
        $callArg = $cfgCallOp->args[0] ?? null;
        if (!$this->callArgIsDeadInlineTemporary($callArg)) {
            return;
        }
        if (
            null !== $callArg
            && !$this->operandsReferToSameVariable($prelude->result, $callArg)
        ) {
            // array_pad([E::A], N, E::B) / extract([...], FLAGS, Prefix::A) — immediate ClassConstFetch is not arg #0 (#8883, #16041).
            // preg_replace_callback_array([...], E::CASE) — enum prelude feeds arg #1 (#5859).
            return;
        }
        if (
            'preg_replace_callback_array' === $this->resolveCfgFuncCallName($cfgCallOp)
            && \is_int($callIndex)
            && $callIndex >= 2
            && ($block->orig->children[$callIndex - 2] ?? null) instanceof Op\Expr\Array_
        ) {
            return;
        }
        if (null === $block->slotForOperand($prelude->result)) {
            foreach ($this->compileExpr($prelude, $block) as $op) {
                $block->addOpCode($op);
            }
        }
        $preludeSlot = $block->slotForOperand($prelude->result);
        if (null === $preludeSlot) {
            foreach ($pendingNestedProducerOps as $op) {
                if (OpCode::TYPE_CLASS_CONST_FETCH === $op->type) {
                    $preludeSlot = (string) $op->arg1;
                    break;
                }
            }
        }
        if (null === $preludeSlot) {
            return;
        }
        $argIndex = 0;
        foreach ($outerArgSends as $send) {
            if (OpCode::TYPE_ARG_SEND !== $send->type) {
                continue;
            }
            if (0 === $argIndex) {
                $send->arg1 = (string) $preludeSlot;
            }
            ++$argIndex;
        }
    }

    /**
     * preg_replace_callback_array(['/pat/' => $cb], E::CASE) — pattern Array_ is arg #0, enum case arg #1 (#5859, #9072).
     *
     * @param list<OpCode> $outerArgSends
     * @param list<OpCode> $allArgSends
     */
    private function rewirePregReplaceCallbackArrayPatternMapArgSendSlots(
        array &$outerArgSends,
        Block $block,
        ?Op $cfgCallOp,
        array $allArgSends = []
    ): void {
        if (null === $cfgCallOp || null === $block->orig) {
            return;
        }
        if ('preg_replace_callback_array' !== $this->resolveCfgFuncCallName($cfgCallOp)) {
            return;
        }
        if (2 !== \count($cfgCallOp->args ?? [])) {
            return;
        }
        $callIndex = $this->cfgCallOpIndex($block, $cfgCallOp);
        if (!\is_int($callIndex) || $callIndex < 2) {
            return;
        }
        $patternMap = $block->orig->children[$callIndex - 2] ?? null;
        $subjectPrelude = $block->orig->children[$callIndex - 1] ?? null;
        if (!$patternMap instanceof Op\Expr\Array_) {
            return;
        }
        if (!$subjectPrelude instanceof Op\Expr\ClassConstFetch) {
            return;
        }
        $initSlot = null;
        foreach (array_merge($block->opCodes, $allArgSends) as $op) {
            if (OpCode::TYPE_INIT_ARRAY === $op->type && null !== $op->arg1) {
                $initSlot = (string) $op->arg1;
            }
        }
        if (null === $initSlot) {
            $initSlot = $block->slotForOperand($patternMap->result);
            if (null !== $initSlot) {
                $initSlot = (string) $initSlot;
            }
        }
        $enumSlot = $block->slotForOperand($subjectPrelude->result);
        if (null === $enumSlot) {
            foreach ($this->compileExpr($subjectPrelude, $block) as $op) {
                $block->addOpCode($op);
            }
            $enumSlot = $block->slotForOperand($subjectPrelude->result);
        }
        if (null === $initSlot || null === $enumSlot) {
            return;
        }
        $argIndex = 0;
        foreach ($outerArgSends as &$send) {
            if (OpCode::TYPE_ARG_SEND !== $send->type) {
                continue;
            }
            if (0 === $argIndex) {
                $send->arg1 = $initSlot;
            } elseif (1 === $argIndex) {
                $send->arg1 = (string) $enumSlot;
            }
            ++$argIndex;
        }
        unset($send);
    }

    /**
     * array_combine([...], [...]) — ARG_SEND must map to sibling INIT_ARRAY slots, not recent-init (#16080, #10214, #17629).
     *
     * @param list<OpCode> $outerArgSends
     * @param list<OpCode> $allArgSends
     */
    private function rewireArrayCombineInlineArgSendSlots(
        array &$outerArgSends,
        Block $block,
        array $allArgSends,
        ?string $calleeName,
        ?Op $cfgCallOp
    ): void {
        if (null === $cfgCallOp || 2 !== \count($cfgCallOp->args ?? [])) {
            return;
        }
        $calleeLower = strtolower($calleeName ?? $this->resolveCfgFuncCallName($cfgCallOp) ?? '');
        if ('array_combine' !== $calleeLower) {
            return;
        }
        if (null === $block->orig) {
            return;
        }
        foreach ($this->precedingInlineCallArgProducersBeforeCfgOp(
            $block->orig->children,
            $cfgCallOp
        ) as $producer) {
            if ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall) {
                // array_combine(array_keys(...), [...]) — nested FuncCall feeds arg #0 (#16097).
                return;
            }
        }
        foreach ($cfgCallOp->args as $callArg) {
            if (
                null === $callArg
                || !$this->callArgIsDeadInlineTemporary($callArg)
                || !$this->callArgOperandExpectsArrayProducer($callArg)
            ) {
                return;
            }
        }
        $initSlots = $this->initArraySlotsForCurrentFunccall($block, $allArgSends);
        if (\count($initSlots) > 2) {
            // [] === array_combine([], []) — comparison lhs Array_ shares the post-call INIT_ARRAY window (#17629).
            $initSlots = \array_slice($initSlots, -2);
        }
        if (\count($initSlots) < 2) {
            return;
        }
        $sendOrdinal = 0;
        foreach ($outerArgSends as &$send) {
            if (OpCode::TYPE_ARG_SEND !== $send->type) {
                continue;
            }
            if (isset($initSlots[$sendOrdinal])) {
                $send->arg1 = $initSlots[$sendOrdinal];
            }
            ++$sendOrdinal;
        }
        unset($send);
    }

    /**
     * var_export(array_keys([null => 1], null), true) — arg #0 must use nested FUNCCALL_EXEC_RETURN, not INIT_ARRAY (#16107).
     *
     * @param list<OpCode> $outerArgSends
     * @param list<OpCode> $nestedProducerOps
     */
    private function rewireVarExportNestedInlineCallArgSendSlots(
        array &$outerArgSends,
        array $nestedProducerOps,
        Block $block,
        ?Op $cfgCallOp,
        ?string $calleeName = null
    ): void {
        $callee = strtolower($calleeName ?? $this->resolveCfgFuncCallName($cfgCallOp) ?? '');
        if ('var_export' !== $callee || null === $cfgCallOp) {
            return;
        }
        $callArg = $cfgCallOp->args[0] ?? null;
        if (
            !$callArg instanceof Operand
            || !$this->callArgIsDeadInlineTemporary($callArg)
            || $this->inlineArrayProducerImmediatelyBeforeCfgCall($cfgCallOp, $block) instanceof Op\Expr\Array_
        ) {
            return;
        }
        if ($this->callArgIsNullLiteral($callArg, $cfgCallOp, 0, $block)) {
            return;
        }
        $pendingDimFetchSlot = $this->lastPendingCallArgArrayDimFetchSlot(
            $block,
            array_merge($nestedProducerOps, $outerArgSends)
        );
        if (null !== $pendingDimFetchSlot) {
            // var_export(empty($a['x']['y'])) / isset(...) — quiet dim-fetch prelude must not steal arg #0 (#21991).
            if (null !== $block->orig) {
                $issetEmptyProducer = $this->findHoistedIssetOrEmptyProducerForCallArg($block, $cfgCallOp, 0);
                if (null !== $issetEmptyProducer) {
                    return;
                }
                // var_export($u[0] === $u[1]) — Identical feeds arg #0, not trailing ArrayDimFetch rhs (#12082).
                $comparisonProducer = $this->matchBooleanBinaryOpInlineCallArgProducer(
                    $this->precedingInlineCallArgProducersBeforeCfgOp(
                        $block->orig->children,
                        $cfgCallOp
                    ),
                    $callArg
                );
                if (null !== $comparisonProducer) {
                    return;
                }
                // var_export((string)$xml['a']) — Cast feeds arg #0; dim-fetch is the Cast operand (#25339).
                // Skip trailing true/false return-flag ConstFetch so two-arg form still sees the Cast.
                $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
                if (\is_int($callIndex) && $callIndex > 0) {
                    $probeIndex = $callIndex - 1;
                    while ($probeIndex >= 0) {
                        $probe = $block->orig->children[$probeIndex] ?? null;
                        if ($probe instanceof Op\Expr\ConstFetch) {
                            $flagName = strtolower($this->staticNameFromOperand($probe->name) ?? '');
                            if (\in_array($flagName, ['true', 'false'], true)) {
                                --$probeIndex;
                                continue;
                            }
                        }
                        break;
                    }
                    $argPrelude = $block->orig->children[$probeIndex] ?? null;
                    if ($argPrelude instanceof Op\Expr\Cast) {
                        return;
                    }
                    // var_export($arr['o']->name, true) — PropertyFetch feeds arg #0;
                    // ArrayDimFetch is the object receiver (#31938, zend_execute.c FETCH_OBJ_R).
                    if (
                        $argPrelude instanceof Op\Expr\PropertyFetch
                        || $argPrelude instanceof Op\Expr\NullsafePropertyFetch
                        || $argPrelude instanceof Op\Expr\StaticPropertyFetch
                    ) {
                        return;
                    }
                }
            }
            $trueSlot = null;
            foreach (array_reverse(array_merge($block->opCodes, $nestedProducerOps, $outerArgSends)) as $op) {
                if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                    break;
                }
                if (OpCode::TYPE_CONST_FETCH !== $op->type || null === $op->arg2) {
                    continue;
                }
                $name = $this->resolveCompileTimeStringSlot((int) $op->arg2, $block);
                if ('true' === strtolower($name ?? '')) {
                    $trueSlot = $op->arg1;
                    break;
                }
            }
            $sendOrdinal = 0;
            foreach ($outerArgSends as &$send) {
                if (OpCode::TYPE_ARG_SEND !== $send->type) {
                    continue;
                }
                if (0 === $sendOrdinal) {
                    $send->arg1 = (string) $pendingDimFetchSlot;
                } elseif (1 === $sendOrdinal && null !== $trueSlot) {
                    $send->arg1 = (string) $trueSlot;
                }
                ++$sendOrdinal;
            }
            unset($send);

            return;
        }
        if ($this->isCallArgDirectArrayDimFetch($callArg)) {
            $dimSlot = $this->lastPendingCallArgArrayDimFetchSlot($block, $nestedProducerOps);
            if (null !== $dimSlot) {
                $sendOrdinal = 0;
                foreach ($outerArgSends as &$send) {
                    if (OpCode::TYPE_ARG_SEND !== $send->type) {
                        continue;
                    }
                    if (0 === $sendOrdinal) {
                        $send->arg1 = (string) $dimSlot;
                    }
                    ++$sendOrdinal;
                }
                unset($send);
            }

            return;
        }
        if (null !== $block->orig) {
            $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
            if (\is_int($callIndex) && $callIndex > 0) {
                $stmtBefore = $block->orig->children[$callIndex - 1] ?? null;
                $hoistedNullFeedsSoleArg = $stmtBefore instanceof Op\Expr\ConstFetch
                    && $this->constFetchIsNull($stmtBefore)
                    && \is_array($cfgCallOp->args ?? null)
                    && 1 === \count($cfgCallOp->args)
                    && $this->callArgIsDeadInlineTemporary($cfgCallOp->args[0] ?? null);
                if (
                    ($stmtBefore instanceof Op\Expr\ConstFetch || $stmtBefore instanceof Op\Expr\ClassConstFetch)
                    && (
                        null === $this->nonConstInlineProducerBeforeTrailingScalarConstFetchPreludes(
                            $callIndex,
                            $block->orig->children
                        )
                        || $hoistedNullFeedsSoleArg
                    )
                ) {
                    // var_export($expr, true|false) — hoisted return flag is not arg #0 (#17895, #17251).
                    $skipConstEarlyReturn = false;
                    if (
                        \is_array($cfgCallOp->args ?? null)
                        && \count($cfgCallOp->args) >= 2
                        && $stmtBefore instanceof Op\Expr\ConstFetch
                    ) {
                        $name = $this->staticNameFromOperand($stmtBefore->name);
                        if (\in_array(strtolower($name ?? ''), ['true', 'false'], true)) {
                            $skipConstEarlyReturn = true;
                        }
                    }
                    if (!$skipConstEarlyReturn) {
                        $constSlot = $block->slotForOperand($stmtBefore->result);
                        if (null === $constSlot) {
                            foreach (array_reverse(array_merge($block->opCodes, $nestedProducerOps)) as $op) {
                                if (OpCode::TYPE_CONST_FETCH !== $op->type || null === $op->arg1) {
                                    continue;
                                }
                                $constSlot = $op->arg1;
                                break;
                            }
                        }
                        if (null !== $constSlot) {
                            foreach ($outerArgSends as &$send) {
                                if (OpCode::TYPE_ARG_SEND !== $send->type) {
                                    continue;
                                }
                                $send->arg1 = (string) $constSlot;
                                break;
                            }
                            unset($send);

                            return;
                        }
                    }
                }
            }
        }
        $execSlot = null;
        if (null !== $block->orig) {
            $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
            if (\is_int($callIndex) && $callIndex > 0) {
                $probeIndex = $callIndex - 1;
                while ($probeIndex >= 0) {
                    $probe = $block->orig->children[$probeIndex] ?? null;
                    if ($probe instanceof Op\Expr\ConstFetch || $probe instanceof Op\Expr\ClassConstFetch) {
                        --$probeIndex;
                        continue;
                    }
                    break;
                }
                $producer = $block->orig->children[$probeIndex] ?? null;
                // var_export($named === $pos) — comparison feeds arg #0, not prior fgets EXEC_RETURN (#11052, #17277).
                if ($this->isComparisonInlineCallArgProducer($producer)) {
                    return;
                }
                // var_export(isset($obj->p), true) / empty(...) — bool from TYPE_ISSET/EMPTY, not stale EXEC_RETURN (#17555).
                if ($producer instanceof Op\Expr\Isset_ || $producer instanceof Op\Expr\Empty_) {
                    return;
                }
                // var_export(require_once $f, true) — Include_/Eval_ result, not prior getmypid EXEC_RETURN (#25852).
                if ($producer instanceof Op\Expr\Include_ || $producer instanceof Op\Expr\Eval_) {
                    return;
                }
                // var_export($text->data) / var_export(JSON_HEX_TAG | JSON_HEX_AMP) — expression prelude feeds arg #0, not stale FuncCall EXEC_RETURN (#17540, #17562).
                $producerExpr = $producer instanceof Op\Expr\Assign ? $producer->expr : $producer;
                if ($this->isImmediateVarExportExpressionPrelude($producerExpr)) {
                    return;
                }
                // var_export("{$c}") / var_export("a{$c}b") — ConcatList already lowered via
                // tryResolveEncapsedConcatListCallArgSlot; do not steal prior New_ EXEC_RETURN (#26489 / #13466).
                // Keep this check out of isImmediateVarExportExpressionPrelude: that helper's other
                // callers compileExpr() the prelude, and ConcatList is not an Expr compile path.
                if (
                    $producerExpr instanceof Op\Expr\ConcatList
                    || $producerExpr instanceof Op\Expr\BinaryOp\Concat
                ) {
                    return;
                }
                if ($producer instanceof Op\Expr\MethodCall || $producer instanceof Op\Expr\StaticCall) {
                    if (null === $block->slotForOperand($producer->result)) {
                        $prevForce = $this->forceDeferredSiblingCallReturnSlot;
                        $this->forceDeferredSiblingCallReturnSlot = true;
                        try {
                            foreach ($this->compileExpr($producer, $block) as $op) {
                                $block->addOpCode($op);
                            }
                        } finally {
                            $this->forceDeferredSiblingCallReturnSlot = $prevForce;
                        }
                    }
                    $pairedExec = $this->slotForMethodOrStaticCallInitFollowingExecReturn(
                        $block,
                        $producer,
                        $nestedProducerOps
                    );
                    if (null !== $pairedExec) {
                        $execSlot = $pairedExec;
                    } else {
                        $operandSlot = $block->slotForOperand($producer->result);
                        if (null !== $operandSlot) {
                            $execSlot = (string) $operandSlot;
                        } else {
                            $execSlot = $this->slotForSiblingMethodCallProducerExecReturn(
                                $block,
                                $producer,
                                $cfgCallOp,
                                $block->orig->children
                            );
                        }
                    }
                } elseif ($producer instanceof Op\Expr\ArrayDimFetch) {
                    $producers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                        $block->orig->children,
                        $cfgCallOp
                    );
                    $chainedDimFetch = $this->matchChainedArrayDimFetchInlineCallArgProducer($producers, 0);
                    if ($chainedDimFetch instanceof Op\Expr\ArrayDimFetch && null !== $chainedDimFetch->result) {
                        $dimSlot = $block->slotForOperand($chainedDimFetch->result);
                        if (null === $dimSlot) {
                            $dimFetches = array_values(array_filter(
                                $producers,
                                static fn (Op\Expr $p): bool => $p instanceof Op\Expr\ArrayDimFetch
                            ));
                            if (
                                \count($dimFetches) >= 2
                                && $this->arrayDimFetchesFormProducerChain($dimFetches)
                            ) {
                                $dimSlot = $this->pendingCallArgArrayDimFetchSlot(
                                    $block,
                                    array_merge($block->opCodes, $nestedProducerOps),
                                    \count($dimFetches) - 1
                                );
                            }
                        }
                        if (null !== $dimSlot) {
                            $execSlot = (string) $dimSlot;
                        }
                    }
                } elseif ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall) {
                    $operandSlot = $block->slotForOperand($producer->result);
                    if (null !== $operandSlot) {
                        $execSlot = (string) $operandSlot;
                    } else {
                        $execSlot = $this->slotForInlineFuncCallProducerExecReturnByCfgIndex(
                            $block,
                            $probeIndex,
                            $block->orig->children
                        );
                    }
                }
            }
        }
        if (null === $execSlot) {
            $execSlot = $this->slotForLastPendingInlineCallResultBeforeFuncCallInit($nestedProducerOps);
        }
        if (null === $execSlot) {
            $execSlot = $this->slotForLastEmittedInlineCallResultBeforePendingFuncCall($block);
        }
        if (null === $execSlot) {
            return;
        }
        $initSlots = [];
        foreach (array_merge($block->opCodes, $nestedProducerOps) as $op) {
            if (OpCode::TYPE_INIT_ARRAY === $op->type && null !== $op->arg1) {
                $initSlots[] = $op->arg1;
            }
        }
        $trueSlot = null;
        foreach (array_reverse(array_merge($block->opCodes, $nestedProducerOps)) as $op) {
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                break;
            }
            if (OpCode::TYPE_CONST_FETCH !== $op->type || null === $op->arg2) {
                continue;
            }
            $name = $this->resolveCompileTimeStringSlot((int) $op->arg2, $block);
            if ('true' === strtolower($name ?? '')) {
                $trueSlot = $op->arg1;
                break;
            }
        }
        if (null === $trueSlot) {
            $trueSlot = $this->slotForVarExportHoistedReturnTruePrelude($block, $cfgCallOp);
        }
        $sendOrdinal = 0;
        foreach ($outerArgSends as &$send) {
            if (OpCode::TYPE_ARG_SEND !== $send->type) {
                continue;
            }
            if (0 === $sendOrdinal) {
                $hoistedScalarArgSlot = $this->slotForVarExportHoistedScalarConstArgZero($block, $cfgCallOp);
                if (null !== $hoistedScalarArgSlot) {
                    // var_export(INF, true) twice — arg #0 is hoisted INF/NAN, not prior var_export EXEC_RETURN (#18426).
                    $send->arg1 = $hoistedScalarArgSlot;
                } elseif ([] !== $initSlots && \in_array($send->arg1, $initSlots, true)) {
                    $send->arg1 = $execSlot;
                } elseif (null !== $trueSlot && (string) $send->arg1 === (string) $trueSlot) {
                    // var_export($it->current(), true) / var_export(f(), true) — arg #0 is producer EXEC_RETURN (#17251).
                    $send->arg1 = $execSlot;
                } elseif (
                    null === $hoistedScalarArgSlot
                    && $callArg instanceof Operand
                    && $this->callArgIsDeadInlineTemporary($callArg)
                    && (string) $send->arg1 !== (string) $execSlot
                ) {
                    // var_export($g->valid(), true) after prior var_export — dead arg temp must not reuse stale EXEC_RETURN (#17520).
                    $send->arg1 = $execSlot;
                } elseif (null === $hoistedScalarArgSlot && (string) $send->arg1 !== (string) $execSlot) {
                    // var_export($g2->current(), true) after earlier var_export — sibling MethodCall EXEC_RETURN (#18183).
                    $send->arg1 = $execSlot;
                }
            } elseif (1 === $sendOrdinal && null !== $trueSlot && (string) $send->arg1 === (string) $execSlot) {
                $send->arg1 = $trueSlot;
            }
            ++$sendOrdinal;
        }
        unset($send);
    }

    /**
     * file_put_contents($f, 'a', FILE_APPEND | LOCK_EX) — trailing dead-temp arg must use BitwiseOr dest (#18523).
     *
     * @param list<OpCode> $outerArgSends
     * @param list<OpCode> $nestedProducerOps
     */
    private function rewireInlineBitmaskTrailingCallArgSendSlots(
        array &$outerArgSends,
        array $nestedProducerOps,
        Block $block,
        ?Op $cfgCallOp
    ): void {
        if (null === $cfgCallOp || null === $block->orig || !\is_array($cfgCallOp->args ?? null)) {
            return;
        }
        $callIndex = $this->cfgCallOpIndex($block, $cfgCallOp);
        if (!\is_int($callIndex) || $callIndex < 1) {
            return;
        }
        $immediate = $block->orig->children[$callIndex - 1] ?? null;
        if ($immediate instanceof Op\Expr\Assign) {
            $hoistedRhs = $callIndex > 1 ? ($block->orig->children[$callIndex - 2] ?? null) : null;
            if (
                $hoistedRhs instanceof Op\Expr\BinaryOp\BitwiseOr
                || $hoistedRhs instanceof Op\Expr\BinaryOp\BitwiseAnd
                || $hoistedRhs instanceof Op\Expr\BinaryOp\BitwiseXor
            ) {
                $immediate = $hoistedRhs;
            } else {
                $immediate = $immediate->expr;
            }
        }
        if (
            !$immediate instanceof Op\Expr\BinaryOp\BitwiseOr
            && !$immediate instanceof Op\Expr\BinaryOp\BitwiseAnd
            && !$immediate instanceof Op\Expr\BinaryOp\BitwiseXor
        ) {
            return;
        }
        $nonEmbeddedArgIndices = [];
        foreach ($cfgCallOp->args as $i => $candidateArg) {
            if (null !== $candidateArg && !$this->isEmbeddedCallLiteralArg($candidateArg)) {
                $nonEmbeddedArgIndices[] = (int) $i;
            }
        }
        $trailingArgIndex = $nonEmbeddedArgIndices[\count($nonEmbeddedArgIndices) - 1] ?? null;
        if (null === $trailingArgIndex) {
            return;
        }
        $trailingArg = $cfgCallOp->args[$trailingArgIndex] ?? null;
        $assignInCallBitmask = ($block->orig->children[$callIndex - 1] ?? null) instanceof Op\Expr\Assign;
        if (
            !$assignInCallBitmask
            && (
                !$this->callArgIsDeadInlineTemporary($trailingArg)
                || $this->callArgOperandExpectsArrayProducer($trailingArg)
            )
        ) {
            return;
        }
        if ($assignInCallBitmask && $this->callArgOperandExpectsArrayProducer($trailingArg)) {
            return;
        }
        $bitmaskSlot = $this->slotForHoistedAssignInCallNamedDest($block, $cfgCallOp);
        if (null === $bitmaskSlot && null !== $immediate->result) {
            $bitmaskSlot = $block->slotForOperand($immediate->result);
            if (null !== $bitmaskSlot) {
                $bitmaskSlot = (string) $bitmaskSlot;
            }
        }
        if (null === $bitmaskSlot) {
            foreach (array_reverse(array_merge($nestedProducerOps, $block->opCodes, $outerArgSends)) as $op) {
                if (
                    OpCode::TYPE_BITWISE_OR === $op->type
                    || OpCode::TYPE_BITWISE_AND === $op->type
                    || OpCode::TYPE_BITWISE_XOR === $op->type
                ) {
                    if (null !== $op->arg1) {
                        $bitmaskSlot = (string) $op->arg1;
                        break;
                    }
                }
            }
        }
        if (null === $bitmaskSlot) {
            return;
        }
        if ($assignInCallBitmask) {
            for ($i = \count($outerArgSends) - 1; $i >= 0; --$i) {
                $send = $outerArgSends[$i];
                if (OpCode::TYPE_ARG_SEND !== $send->type) {
                    continue;
                }
                $send->arg1 = $bitmaskSlot;

                return;
            }

            return;
        }
        $argSendOrdinal = 0;
        foreach ($outerArgSends as $send) {
            if (OpCode::TYPE_ARG_SEND !== $send->type) {
                continue;
            }
            if ($argSendOrdinal === $trailingArgIndex) {
                $send->arg1 = $bitmaskSlot;

                return;
            }
            ++$argSendOrdinal;
        }
    }

    /**
     * is_array(file(..., FILE_* | FILE_*)) / count(file(...)) — arg #0 must use adjacent FUNCCALL_EXEC_RETURN,
     * not the hoisted bitmask OR slot (#10474).
     *
     * @param list<OpCode> $outerArgSends
     * @param list<OpCode> $nestedProducerOps
     */
    private function rewireArrayBuiltinAdjacentFuncCallArgSendSlots(
        array &$outerArgSends,
        array $nestedProducerOps,
        Block $block,
        ?Op $cfgCallOp,
        ?string $calleeName = null
    ): void {
        $callee = strtolower($calleeName ?? $this->resolveCfgFuncCallName($cfgCallOp) ?? '');
        if (!\in_array($callee, ['is_array', 'count', 'array_keys'], true) || null === $cfgCallOp || null === $block->orig) {
            return;
        }
        if (!\is_array($cfgCallOp->args ?? null) || 1 !== \count($cfgCallOp->args)) {
            return;
        }
        $callArg = $cfgCallOp->args[0] ?? null;
        if (!$callArg instanceof Operand || !$this->callArgIsDeadInlineTemporary($callArg)) {
            return;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (!\is_int($callIndex) || $callIndex < 1) {
            return;
        }
        $adjacentIndex = $callIndex - 1;
        while ($adjacentIndex >= 0) {
            $skip = $block->orig->children[$adjacentIndex] ?? null;
            if ($skip instanceof Op\Expr\ConstFetch || $skip instanceof Op\Expr\ClassConstFetch) {
                --$adjacentIndex;
                continue;
            }
            if ($this->isUnaryInlineSiblingCallArgExpr($skip)) {
                --$adjacentIndex;
                continue;
            }
            break;
        }
        $adjacent = $block->orig->children[$adjacentIndex] ?? null;
        if (!(
            $adjacent instanceof Op\Expr\FuncCall
            || $adjacent instanceof Op\Expr\NsFuncCall
            || $adjacent instanceof Op\Expr\MethodCall
            || $adjacent instanceof Op\Expr\StaticCall
        )) {
            return;
        }
        $execSlot = $this->slotForSiblingInlineCallProducerExecReturnByExpr(
            $block,
            $adjacent,
            $cfgCallOp,
            $block->orig->children
        );
        if (null === $execSlot) {
            $execSlot = $this->slotForLastPendingInlineCallResultBeforeFuncCallInit($nestedProducerOps)
                ?? $this->slotForLastEmittedInlineCallResultBeforePendingFuncCall($block);
        }
        if (null === $execSlot) {
            return;
        }
        foreach ($outerArgSends as &$send) {
            if (OpCode::TYPE_ARG_SEND === $send->type) {
                $send->arg1 = (string) $execSlot;
                break;
            }
        }
        unset($send);
    }

    private function isInlineCallArgProducerInitOpcode(OpCode $op): bool
    {
        return OpCode::TYPE_FUNCCALL_INIT === $op->type
            || OpCode::TYPE_STATICCALL_INIT === $op->type
            || OpCode::TYPE_METHODCALL_INIT === $op->type;
    }

    private function isInlineCallArgProducerExecOpcode(OpCode $op): bool
    {
        return OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type
            || OpCode::TYPE_FUNCCALL_EXEC_NORETURN === $op->type;
    }

    /**
     * json_encode($s, JSON_HEX_* | …) — arg #0 is a named local; bitmask preludes must not replace value ARG_SEND (#10956).
     *
     * @param list<OpCode> $outerArgSends
     */
    private function rewireNamedLocalBeforeInlineBitmaskCallArgSendSlots(
        array &$outerArgSends,
        Block $block,
        ?Op $cfgCallOp
    ): void {
        if (null === $cfgCallOp || null === $block->orig || \count($cfgCallOp->args ?? []) < 2) {
            return;
        }
        $valueArg = $cfgCallOp->args[0] ?? null;
        if (!$valueArg instanceof Operand || $this->callArgIsDeadInlineTemporary($valueArg)) {
            return;
        }
        if (!$this->cfgCallPrecededByInlineBitmaskProducer($cfgCallOp, $block)) {
            return;
        }
        $namedSlot = $this->namedLocalCallArgSlotIfBound($valueArg, $block, $cfgCallOp, 0)
            ?? $this->slotForNamedLocalFromAssignVarOperand($valueArg, $block);
        if (null === $namedSlot) {
            $operandSlot = $block->slotForOperand($valueArg);
            if (null === $operandSlot) {
                return;
            }
            $namedSlot = (int) $operandSlot;
        }
        $wired = (string) $this->finalizeOperandSlotForAccess($block, (int) $namedSlot, true);
        $argSendOrdinal = 0;
        foreach ($outerArgSends as &$send) {
            if (OpCode::TYPE_ARG_SEND !== $send->type) {
                continue;
            }
            if (0 === $argSendOrdinal) {
                $send->arg1 = $wired;

                return;
            }
            ++$argSendOrdinal;
        }
        unset($send);
    }

    private function cfgCallPrecededByInlineBitmaskProducer(Op $cfgCallOp, Block $block): bool
    {
        $callIndex = $this->cfgCallOpIndex($block, $cfgCallOp);
        if (!\is_int($callIndex) || $callIndex < 1) {
            return false;
        }
        $immediate = $block->orig->children[$callIndex - 1] ?? null;
        if ($immediate instanceof Op\Expr\Assign) {
            $hoistedRhs = $callIndex > 1 ? ($block->orig->children[$callIndex - 2] ?? null) : null;
            if (
                $hoistedRhs instanceof Op\Expr\BinaryOp\BitwiseOr
                || $hoistedRhs instanceof Op\Expr\BinaryOp\BitwiseAnd
                || $hoistedRhs instanceof Op\Expr\BinaryOp\BitwiseXor
            ) {
                $immediate = $hoistedRhs;
            } else {
                $immediate = $immediate->expr;
            }
        }

        return $immediate instanceof Op\Expr\BinaryOp\BitwiseOr
            || $immediate instanceof Op\Expr\BinaryOp\BitwiseAnd
            || $immediate instanceof Op\Expr\BinaryOp\BitwiseXor;
    }

    /**
     * is_array(file(...)) / count(file(...)) — ARG_SEND must use nested file() EXEC_RETURN, not bitmask OR (#10474).
     *
     * @param list<OpCode> $outerArgSends
     * @param list<OpCode> $nestedProducerOps
     */
    private function rewireIsArrayNestedFileCallArgSendSlots(
        array &$outerArgSends,
        array $nestedProducerOps,
        Block $block,
        ?Op $cfgCallOp,
        ?string $calleeName = null
    ): void {
        $callee = strtolower($calleeName ?? $this->resolveCfgFuncCallName($cfgCallOp) ?? '');
        if (!\in_array($callee, ['is_array', 'count'], true) || null === $cfgCallOp || null === $block->orig) {
            return;
        }
        $callArg = $cfgCallOp->args[0] ?? null;
        if (!$callArg instanceof Operand || !$this->callArgIsDeadInlineTemporary($callArg)) {
            return;
        }
        $callIndex = $this->cfgCallOpIndex($block, $cfgCallOp);
        if (!\is_int($callIndex) || $callIndex < 1) {
            return;
        }
        $immediate = $block->orig->children[$callIndex - 1] ?? null;
        if (!$immediate instanceof Op\Expr\FuncCall && !$immediate instanceof Op\Expr\NsFuncCall) {
            return;
        }
        if ('file' !== strtolower($this->resolveCfgFuncCallName($immediate) ?? '')) {
            return;
        }
        $execSlot = $this->slotForLastInlineFuncCallExecReturn($block, $nestedProducerOps);
        if (null === $execSlot) {
            return;
        }
        foreach ($outerArgSends as &$send) {
            if (OpCode::TYPE_ARG_SEND !== $send->type) {
                continue;
            }
            if ((string) $send->arg1 !== (string) $execSlot) {
                $send->arg1 = $execSlot;
            }
            break;
        }
        unset($send);
    }

    /**
     * var_export($expr !== false, true) — arg #0 is compare result, arg #1 is return flag only (#17250, #17277).
     *
     * @param list<OpCode> $outerArgSends
     * @param list<OpCode> $nestedProducerOps
     */
    private function rewireVarExportComparisonReturnFlagCallArgSendSlots(
        array &$outerArgSends,
        array $nestedProducerOps,
        Block $block,
        ?Op $cfgCallOp,
        ?string $calleeName = null
    ): void {
        if ('var_export' !== strtolower($calleeName ?? $this->resolveCfgFuncCallName($cfgCallOp) ?? '')) {
            return;
        }
        if (
            null === $cfgCallOp
            || null === $block->orig
            || !\is_array($cfgCallOp->args ?? null)
            || \count($cfgCallOp->args) < 2
        ) {
            return;
        }
        $callIndex = $this->cfgCallOpIndex($block, $cfgCallOp);
        if (!\is_int($callIndex) || $callIndex < 2) {
            return;
        }
        $returnFlagExpr = $block->orig->children[$callIndex - 1] ?? null;
        if (!$this->isHoistedScalarConstFetchImmediatelyBeforeCall($returnFlagExpr)) {
            return;
        }
        $comparisonExpr = $block->orig->children[$callIndex - 2] ?? null;
        if (
            !$this->isComparisonInlineCallArgProducer($comparisonExpr)
            || !$comparisonExpr instanceof Op\Expr
            || null === $comparisonExpr->result
            || !$returnFlagExpr instanceof Op\Expr\ConstFetch
            || null === $returnFlagExpr->result
        ) {
            return;
        }
        $comparisonSlot = $this->slotForInlineExprResultInProducerOps(
            $comparisonExpr,
            $block,
            $nestedProducerOps
        );
        $returnSlot = $this->slotForInlineExprResultInProducerOps(
            $returnFlagExpr,
            $block,
            $nestedProducerOps
        );
        if (null === $comparisonSlot || null === $returnSlot) {
            return;
        }
        $sendOrdinal = 0;
        foreach ($outerArgSends as &$send) {
            if (OpCode::TYPE_ARG_SEND !== $send->type) {
                continue;
            }
            $send->arg1 = 0 === $sendOrdinal ? $comparisonSlot : $returnSlot;
            ++$sendOrdinal;
            if ($sendOrdinal >= 2) {
                break;
            }
        }
        unset($send);
    }

    /**
     * @param list<OpCode> $producerOps
     */
    private function slotForInlineExprResultInProducerOps(
        Op\Expr $expr,
        Block $block,
        array $producerOps
    ): ?string {
        $mapped = $block->slotForOperand($expr->result);
        if (null !== $mapped) {
            return (string) $mapped;
        }
        $leftSlot = null;
        $rightSlot = null;
        if ($expr instanceof Op\Expr\BinaryOp) {
            $leftSlot = null !== $expr->left ? $block->slotForOperand($expr->left) : null;
            $rightSlot = null !== $expr->right ? $block->slotForOperand($expr->right) : null;
        }
        foreach ($producerOps as $op) {
            if ($expr instanceof Op\Expr\ConstFetch && OpCode::TYPE_CONST_FETCH === $op->type) {
                if ((string) $op->arg1 === (string) $block->slotForOperand($expr->result)) {
                    return (string) $op->arg1;
                }
            }
            if ($this->isComparisonInlineCallArgProducer($expr)) {
                $compareTypes = [
                    OpCode::TYPE_IDENTICAL,
                    OpCode::TYPE_NOT_IDENTICAL,
                    OpCode::TYPE_EQUAL,
                    OpCode::TYPE_NOT_EQUAL,
                    OpCode::TYPE_SPACESHIP,
                    OpCode::TYPE_SMALLER,
                    OpCode::TYPE_GREATER,
                    OpCode::TYPE_SMALLER_OR_EQUAL,
                    OpCode::TYPE_GREATER_OR_EQUAL,
                ];
                if (!\in_array($op->type, $compareTypes, true)) {
                    continue;
                }
                if (null !== $leftSlot && (string) $op->arg2 !== (string) $leftSlot) {
                    continue;
                }
                if (null !== $rightSlot && (string) $op->arg3 !== (string) $rightSlot) {
                    continue;
                }

                return (string) $op->arg1;
            }
        }

        return null;
    }
}
