<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;

use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * Hoisted-sibling feed helpers + array_keys/combine SEND rewire (#36387 / #36403).
 *
 * Extracted from {@see RewireInlineCallArgSendSlots} so gen-0 split-TU can hollow
 * a smaller Concern TU (Part of #36387 / prior #36147).
 *
 * Covers {@see hoistedSiblingFeedsLaterMultiArgConsumer},
 * {@see inlineClosurePairHaystackFuncCallNeedsReturnSlot},
 * {@see isAdjacentOuterHoistedFuncCallBeforeMultiArgConsumer},
 * {@see nestedLiteralPreludeInlineCallProducerNeedsReturnSlot},
 * {@see cfgCallIsHoistedArrayKeysForArrayCombine},
 * {@see cfgCallImmediatelyFeedsAdjacentConsumer},
 * {@see nearestHoistedFuncCallProducerBeforeConsumer},
 * {@see hoistedProducerFeedsConsumerThroughLiteralGap},
 * {@see siblingFuncCallFeedsVarExportConsumer},
 * {@see siblingMethodCallFeedsVarExportConsumer},
 * {@see cfgCallOpImmediatelyVoidDiscarded},
 * {@see partitionNestedInlineCallArgProducerOps}, and
 * {@see rewireArrayKeysInlineInitArrayArgSendSlots}.
 *
 * Call sites and visibility stay identical so LintCompiler overrides are unaffected.
 * Mirrors php-src Zend/zend_execute.c ZEND_SEND_* / adjacent call-arg wiring — move-only.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; call-arg
 * slot wiring relies on coercion (same as RewireInlineCallArgSendSlots).
 */
trait HoistedSiblingFeedAndArrayKeysArgSendRewire
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

}
