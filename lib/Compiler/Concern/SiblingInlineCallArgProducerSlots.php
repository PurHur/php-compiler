<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;

use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * Sibling inline call-arg producer slot wiring (#36387 / #36403).
 *
 * Extracted from {@see \PHPCompiler\Compiler} so the hub can shrink toward
 * host-CFG split-TU emit under SPINE_CHUNK (gen-0 <30m).
 *
 * Covers {@see resolveSiblingInlineCallArgProducerSlot} and related
 * sibling-producer slot helpers.
 * Array_merge-family / var_export nested slots live in
 * {@see VarExportNestedAndArrayMergeFamilyCallArgSlots}.
 * Substr nested-haystack + method/static call-init slots live in
 * {@see SubstrNestedHaystackAndMethodOrStaticCallInitSlots}.
 * Outer-sibling EXEC_RETURN ordinals + hoisted ConstFetch prelude slots live in
 * {@see OuterSiblingExecReturnAndHoistedConstFetchSlots}.
 * Final sibling ARG_SEND slot lives in
 * {@see FinalSiblingInlineCallArgSendSlot}.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; call-arg
 * slot wiring relies on coercion (same as SiblingInlineFuncCallProducers).
 */
trait SiblingInlineCallArgProducerSlots
{
    /**
     * hold(); in_array(..., ['a','b'], true) — stmt-level UDF then hoisted Array_ prelude only (#15422, #15609).
     *
     * @param list<Op> $cfgChildren
     */
    private function priorStmtLevelCallSeparatedByHoistedArrayPreludeOnly(
        int $producerIndex,
        int $consumerIndex,
        array $cfgChildren
    ): bool {
        if ($producerIndex >= $consumerIndex - 1) {
            return false;
        }
        $producer = $cfgChildren[$producerIndex] ?? null;
        if (!$producer instanceof Op\Expr\FuncCall && !$producer instanceof Op\Expr\NsFuncCall) {
            return false;
        }
        $consumer = $cfgChildren[$consumerIndex] ?? null;
        if (!$consumer instanceof Op\Expr\FuncCall && !$consumer instanceof Op\Expr\NsFuncCall) {
            return false;
        }
        if ($this->inlineCallArgProducerFeedsConsumer($producer, $consumer)) {
            return false;
        }
        // in_array(id('x'), ['x'], true) — dead-temp nested FuncCall + Array_/ConstFetch prelude
        // must EXEC_RETURN; do not treat as stmt-level void (#28891, re-#16013).
        if (
            $producer instanceof Op\Expr
            && (
                $this->isNestedCallArgProducerForConsumer(
                    $producer,
                    $consumer,
                    $producerIndex,
                    $consumerIndex,
                    $cfgChildren
                )
                || $this->isNestedCallArgProducerSeparatedByConsumerLiteralPreludes(
                    $producer,
                    $consumer,
                    $producerIndex,
                    $consumerIndex,
                    $cfgChildren
                )
            )
        ) {
            return false;
        }
        $hasArrayPrelude = false;
        for ($j = $producerIndex + 1; $j < $consumerIndex; ++$j) {
            $mid = $cfgChildren[$j] ?? null;
            if ($mid instanceof Op\Expr\Array_) {
                $hasArrayPrelude = true;
                continue;
            }
            if ($mid instanceof Op\Expr\ConstFetch || $mid instanceof Op\Expr\ClassConstFetch) {
                continue;
            }
            if ($this->isUnaryInlineSiblingCallArgExpr($mid)) {
                continue;
            }
            if ($this->isSiblingInlineCallProducerExpr($mid)) {
                return false;
            }

            return false;
        }

        return $hasArrayPrelude;
    }

    /** Void stmt-level call before hoisted Array_ consumer args — skip EXEC_RETURN slot (#15609). */
    private function stmtLevelVoidCallBeforeHoistedArrayConsumerPrelude(?Op $cfgCallOp, Block $block): bool
    {
        if (null === $cfgCallOp || null === $block->orig) {
            return false;
        }
        if (!$cfgCallOp instanceof Op\Expr\FuncCall && !$cfgCallOp instanceof Op\Expr\NsFuncCall) {
            return false;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (null === $callIndex) {
            return false;
        }
        $cfgChildren = $block->orig->children;
        for ($j = $callIndex + 1; $j < \count($cfgChildren); ++$j) {
            $next = $cfgChildren[$j] ?? null;
            if ($next instanceof Op\Expr\FuncCall || $next instanceof Op\Expr\NsFuncCall) {
                $callee = $this->resolveCfgFuncCallName($next);
                if (!\in_array(strtolower($callee ?? ''), ['in_array', 'array_search', 'array_key_exists'], true)) {
                    return false;
                }

                return $this->priorStmtLevelCallSeparatedByHoistedArrayPreludeOnly(
                    $callIndex,
                    $j,
                    $cfgChildren
                );
            }
            if ($next instanceof Op\Expr\Array_
                || $next instanceof Op\Expr\ConstFetch
                || $next instanceof Op\Expr\ClassConstFetch
                || $this->isUnaryInlineSiblingCallArgExpr($next)
            ) {
                continue;
            }

            return false;
        }

        return false;
    }

    /**
     * array_udiff(array_keys(...), array_keys(...), 'strcmp') after prior u* stmts — scan local array_keys (#16045).
     *
     * @param list<OpCode> $emitOps
     */
    private function resolveTrailingComparatorArrayKeysArgSlot(
        Block $block,
        Op $cfgCallOp,
        int $argIndex,
        int $callIndex,
        array &$emitOps = []
    ): ?int {
        if (null === $block->orig || !property_exists($cfgCallOp, 'args') || !\is_array($cfgCallOp->args)) {
            return null;
        }
        $callbackArgIndex = \count($cfgCallOp->args) - 1;
        if ($argIndex >= $callbackArgIndex) {
            return null;
        }
        $funcArgIndex = 0;
        $targetFuncArgIndex = null;
        foreach ($cfgCallOp->args as $i => $callArg) {
            if ($i >= $callbackArgIndex) {
                break;
            }
            if (
                $this->isEmbeddedCallLiteralArg($callArg)
                || $this->callArgIsDeadInlineTemporary($callArg)
            ) {
                if ($i === $argIndex) {
                    $targetFuncArgIndex = $funcArgIndex;
                    break;
                }
                ++$funcArgIndex;
            }
        }
        if (null === $targetFuncArgIndex) {
            return null;
        }
        $neededKeyCount = 0;
        foreach ($cfgCallOp->args as $i => $callArg) {
            if ($i >= $callbackArgIndex) {
                break;
            }
            if (
                $this->isEmbeddedCallLiteralArg($callArg)
                || $this->callArgIsDeadInlineTemporary($callArg)
            ) {
                ++$neededKeyCount;
            }
        }
        if ($neededKeyCount < 1) {
            return null;
        }
        $keysProducers = [];
        $cfgChildren = $block->orig->children;
        for ($j = $callIndex - 1; $j >= 0; --$j) {
            if (\count($keysProducers) >= $neededKeyCount) {
                break;
            }
            $child = $cfgChildren[$j] ?? null;
            if (
                ($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall)
                && 'array_keys' === strtolower($this->resolveCfgFuncCallName($child) ?? '')
            ) {
                array_unshift($keysProducers, $child);
                continue;
            }
            if ($child instanceof Op\Expr\Array_
                || $child instanceof Op\Expr\ConstFetch
                || $child instanceof Op\Expr\ClassConstFetch
                || $this->isUnaryInlineSiblingCallArgExpr($child)
                || $child instanceof Op\Expr\Assign
                || $child instanceof Op\Expr\AssignRef
            ) {
                continue;
            }
            if (
                ($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall)
                && $this->builtinUsesTrailingComparatorCallback($this->resolveCfgFuncCallName($child))
            ) {
                break;
            }
            break;
        }
        $producer = $keysProducers[$targetFuncArgIndex] ?? null;
        if (!$producer instanceof Op\Expr\FuncCall && !$producer instanceof Op\Expr\NsFuncCall) {
            return null;
        }
        // Compile every deferred sibling array_keys() before resolving slots — per-arg compile
        // leaves exec-return ordinals short for arg #0 (#16300, re-#14021).
        $prevForce = $this->forceDeferredSiblingCallReturnSlot;
        $this->forceDeferredSiblingCallReturnSlot = true;
        try {
            foreach ($keysProducers as $keysProducer) {
                if (
                    !($keysProducer instanceof Op\Expr\FuncCall || $keysProducer instanceof Op\Expr\NsFuncCall)
                    || null !== $block->slotForOperand($keysProducer->result)
                ) {
                    continue;
                }
                foreach ($this->compileExpr($keysProducer, $block) as $op) {
                    $emitOps[] = $op;
                }
            }
        } finally {
            $this->forceDeferredSiblingCallReturnSlot = $prevForce;
        }
        $operandSlot = $block->slotForOperand($producer->result);
        if (null !== $operandSlot) {
            return (int) $operandSlot;
        }
        $keysExecOrdinal = $this->slotForSiblingInlineFuncCallProducerExecReturnOrdinal(
            $block,
            $targetFuncArgIndex
        );
        if (null !== $keysExecOrdinal) {
            return $keysExecOrdinal;
        }
        $slot = $this->slotForInlineCallArgProducerResult($block, $producer, $cfgCallOp, $cfgChildren);

        return null !== $slot ? (int) $slot : null;
    }

    /**
     * php-cfg `f(g(), h())` — map arg N to the Nth sibling hoisted FuncCall producer (#9463, #10917).
     */
    private function resolveSiblingInlineCallArgProducerSlot(
        Block $block,
        Op $cfgCallOp,
        int $argIndex,
        array &$emitOps = []
    ): ?int {
        if (null === $block->orig || !property_exists($cfgCallOp, 'args') || !is_array($cfgCallOp->args)) {
            return null;
        }
        $argCount = \count($cfgCallOp->args);
        if ($argCount < 2 || $argIndex >= $argCount) {
            return null;
        }
        $callArg = $cfgCallOp->args[$argIndex] ?? null;
        if (
            $callArg instanceof Operand
            && $this->isByRefNamedCallArgExcludedFromSiblingProducerWiring($cfgCallOp, $argIndex, $callArg)
        ) {
            return null;
        }
        if ($this->callArgHasHoistedConstPrelude($cfgCallOp, $argIndex, $block)) {
            return null;
        }
        if ($callArg instanceof Operand) {
            $hoistedScalarSlot = $this->tryFoldHoistedBoolNullLiteralCallArg(
                $callArg,
                $block,
                $cfgCallOp,
                $argIndex
            );
            if (null !== $hoistedScalarSlot) {
                return $hoistedScalarSlot;
            }
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (null === $callIndex) {
            return null;
        }
        $consumerName = $this->resolveCfgFuncCallName($cfgCallOp);
        if ($this->builtinUsesTrailingComparatorCallback($consumerName)) {
            $trailingKeysSlot = $this->resolveTrailingComparatorArrayKeysArgSlot(
                $block,
                $cfgCallOp,
                $argIndex,
                $callIndex,
                $emitOps
            );
            if (null !== $trailingKeysSlot) {
                return $trailingKeysSlot;
            }
        }
        $callbackArgIndex = $this->inlineClosureArrayPairCallbackArgIndex($consumerName);
        if ($callbackArgIndex >= 0 && 2 === $argCount) {
            $hasCallbackProducer = false;
            $hasHaystackFuncCall = false;
            for ($j = $callIndex - 1; $j >= 0; --$j) {
                $scan = $block->orig->children[$j] ?? null;
                if ($scan instanceof Op\Expr\ArrowFunction
                    || $scan instanceof Op\Expr\Closure
                    || $scan instanceof Op\Expr\FirstClassCallable) {
                    $hasCallbackProducer = true;
                }
                if ($scan instanceof Op\Expr\FuncCall || $scan instanceof Op\Expr\NsFuncCall) {
                    $hasHaystackFuncCall = true;
                }
            }
            if ($hasCallbackProducer && $hasHaystackFuncCall) {
                return null;
            }
        }
        $firstSibling = $this->firstSiblingInlineFuncCallProducerIndex($callIndex, $block->orig->children);
        if (null === $firstSibling) {
            if ($this->builtinUsesTrailingComparatorCallback($consumerName)) {
                return $this->resolveTrailingComparatorArrayKeysArgSlot(
                    $block,
                    $cfgCallOp,
                    $argIndex,
                    $callIndex,
                    $emitOps
                );
            }

            return null;
        }
        $outerSlot = $this->outerSiblingInlineCallArgProducerSlot($block, $cfgCallOp, $argIndex, $emitOps);
        if (null !== $outerSlot) {
            return (int) $outerSlot;
        }
        // Same intervening-fetch guard as outerSiblingInlineCallArgProducerSlot (#34997):
        // do not ordinal-map stmt-level StaticCall/FuncCall when StaticPropertyFetch covers args.
        if ($this->interveningFetchProducersCoverDeadTempCallArgs(
            $firstSibling,
            $callIndex,
            $block->orig->children,
            $cfgCallOp
        )) {
            return null;
        }
        $arrayPreludeChain = $this->siblingFuncCallChainHasArrayPrelude(
            $firstSibling,
            $callIndex,
            $block->orig->children
        );
        if ($arrayPreludeChain) {
            $producerIndex = $this->siblingInlineFuncCallProducerIndexAtOrdinal(
                $argIndex,
                $firstSibling,
                $callIndex,
                $block->orig->children
            );
            if (null === $producerIndex) {
                $funcProducerCount = $this->countSiblingInlineFuncCallProducers(
                    $firstSibling,
                    $callIndex,
                    $block->orig->children
                );
                if ($argIndex >= $funcProducerCount) {
                    $preludeOrdinal = $argIndex - $funcProducerCount;
                    $seenPrelude = -1;
                    for ($j = $firstSibling + 1; $j < $callIndex; ++$j) {
                        $scan = $block->orig->children[$j] ?? null;
                        if (!$scan instanceof Op\Expr || $this->isSiblingInlineCallProducerExpr($scan)) {
                            continue;
                        }
                        if (
                            $scan instanceof Op\Expr\ConstFetch
                            || $scan instanceof Op\Expr\ClassConstFetch
                            || $scan instanceof Op\Expr\Array_
                        ) {
                            ++$seenPrelude;
                            if ($seenPrelude === $preludeOrdinal) {
                                if (null === $block->slotForOperand($scan->result)) {
                                    foreach ($this->compileExpr($scan, $block) as $op) {
                                        $emitOps[] = $op;
                                    }
                                }
                                $preludeSlot = $block->slotForOperand($scan->result);
                                if (null !== $preludeSlot) {
                                    return (string) $preludeSlot;
                                }
                            }
                        }
                    }
                }

                return null;
            }
            if (
                'array_pad' === strtolower($consumerName ?? '')
                && $this->priorStmtLevelCallSeparatedByHoistedArrayPreludeOnly(
                    $producerIndex,
                    $callIndex,
                    $block->orig->children
                )
            ) {
                return null;
            }
        } else {
            $producerIndex = null;
            for ($j = $firstSibling; $j < $callIndex; ++$j) {
                $scan = $block->orig->children[$j] ?? null;
                if (!$this->isSiblingInlineCallProducerExpr($scan) || !$scan instanceof Op\Expr) {
                    continue;
                }
                $targetArg = $this->siblingMultiArgFuncCallProducerTargetArgIndex(
                    $j,
                    $callIndex,
                    $block->orig->children
                );
                if (
                    $targetArg === $argIndex
                    && $this->isSiblingMultiArgFuncCallProducer(
                        $scan,
                        $cfgCallOp,
                        $j,
                        $callIndex,
                        $block->orig->children
                    )
                ) {
                    $producerIndex = $j;
                    break;
                }
            }
            if (null === $producerIndex) {
                $outerSlot = $this->outerSiblingInlineCallArgProducerSlot($block, $cfgCallOp, $argIndex, $emitOps);
                if (null !== $outerSlot) {
                    return (int) $outerSlot;
                }
                $siblingCount = $callIndex - $firstSibling;
                if ($siblingCount < 2 || $argIndex >= $siblingCount) {
                    return null;
                }
                $producerIndex = $firstSibling + $argIndex;
            }
        }
        $producer = $block->orig->children[$producerIndex] ?? null;
        if (!$this->isSiblingInlineCallProducerExpr($producer)) {
            return null;
        }
        if (!$this->isSiblingMultiArgFuncCallProducer(
            $producer,
            $cfgCallOp,
            $producerIndex,
            $callIndex,
            $block->orig->children
        )) {
            return null;
        }
        if ($this->siblingMultiArgFuncCallProducerTargetArgIndex(
            $producerIndex,
            $callIndex,
            $block->orig->children
        ) !== $argIndex) {
            return null;
        }
        $execReturnSlot = null;
        if (
            null !== $block->orig
            && (
                $producer instanceof Op\Expr\FuncCall
                || $producer instanceof Op\Expr\NsFuncCall
            )
        ) {
            $execReturnSlot = $this->slotForInlineFuncCallProducerExecReturnByCfgIndex(
                $block,
                $producerIndex,
                $block->orig->children
            );
        }
        if (
            null === $execReturnSlot
            && null !== $block->orig
            && (
                $producer instanceof Op\Expr\FuncCall
                || $producer instanceof Op\Expr\NsFuncCall
            )
        ) {
            $execReturnSlot = $this->slotForSiblingInlineCallProducerExecReturnByExpr(
                $block,
                $producer,
                $cfgCallOp,
                $block->orig->children
            );
        }
        // var_export($o->m(), true) — MethodCall EXEC_RETURN ordinal drifts past ctor NEW (#10778, #16794).
        if (
            $producer instanceof Op\Expr\MethodCall
            || $producer instanceof Op\Expr\StaticCall
        ) {
            if (null === $block->slotForOperand($producer->result)) {
                $prevForce = $this->forceDeferredSiblingCallReturnSlot;
                $this->forceDeferredSiblingCallReturnSlot = true;
                try {
                    foreach ($this->compileExpr($producer, $block) as $op) {
                        $emitOps[] = $op;
                    }
                } finally {
                    $this->forceDeferredSiblingCallReturnSlot = $prevForce;
                }
            }
            if (null !== $block->orig) {
                $methodExecSlot = $this->slotForSiblingMethodCallProducerExecReturn(
                    $block,
                    $producer,
                    $cfgCallOp,
                    $block->orig->children
                );
                if (null !== $methodExecSlot) {
                    return (int) $methodExecSlot;
                }
            }
            $operandSlot = $block->slotForOperand($producer->result);
            if (null !== $operandSlot) {
                return (int) $operandSlot;
            }
        }
        if (
            null === $execReturnSlot
            && (
                $producer instanceof Op\Expr\FuncCall
                || $producer instanceof Op\Expr\NsFuncCall
            )
        ) {
            $prevForce = $this->forceDeferredSiblingCallReturnSlot;
            $this->forceDeferredSiblingCallReturnSlot = true;
            try {
                foreach ($this->compileExpr($producer, $block) as $op) {
                    $emitOps[] = $op;
                }
            } finally {
                $this->forceDeferredSiblingCallReturnSlot = $prevForce;
            }
            if (
                null !== $block->orig
                && (
                    $producer instanceof Op\Expr\FuncCall
                    || $producer instanceof Op\Expr\NsFuncCall
                )
            ) {
                $execReturnSlot = $this->slotForInlineFuncCallProducerExecReturnByCfgIndex(
                    $block,
                    $producerIndex,
                    $block->orig->children
                );
            }
            if (null === $execReturnSlot && null !== $block->orig) {
                $execReturnSlot = $this->slotForSiblingInlineCallProducerExecReturnByExpr(
                    $block,
                    $producer,
                    $cfgCallOp,
                    $block->orig->children
                );
            }
        }
        if (null !== $execReturnSlot) {
            return (string) $execReturnSlot;
        }
        if ([] === $emitOps && $this->siblingConsumerHasTrailingByRefNamedLocal($cfgCallOp)) {
            $byRefNamedArg = $cfgCallOp->args[$argIndex] ?? null;
            if (
                !(
                    $byRefNamedArg instanceof Operand
                    && $this->isByRefNamedCallArgExcludedFromSiblingProducerWiring(
                        $cfgCallOp,
                        $argIndex,
                        $byRefNamedArg
                    )
                )
            ) {
                $execReturnSlot = $this->slotForSiblingInlineFuncCallProducerExecReturnOrdinal(
                    $block,
                    (int) $argIndex
                );
                if (null !== $execReturnSlot) {
                    return (string) $execReturnSlot;
                }
            }
        }

        return $block->slotForOperand($producer->result);
    }

}
