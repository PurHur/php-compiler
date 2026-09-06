<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;
use PHPCompiler\VM\Variable;

use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * Sibling inline call-arg producer slot wiring (#36387 / #36403).
 *
 * Extracted from {@see \PHPCompiler\Compiler} so the hub can shrink toward
 * host-CFG split-TU emit under SPINE_CHUNK (gen-0 <30m).
 *
 * Covers {@see resolveSiblingInlineCallArgProducerSlot},
 * {@see finalSiblingInlineCallArgSendSlot}, and exec-return slot helpers.
 * Array_merge-family / var_export nested slots live in
 * {@see VarExportNestedAndArrayMergeFamilyCallArgSlots}.
 * Substr nested-haystack + method/static call-init slots live in
 * {@see SubstrNestedHaystackAndMethodOrStaticCallInitSlots}.
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

    /**
     * similar_text($a, $b, $p) / preg_match(..., $m) — trailing by-ref named local (#15476).
     */
    private function siblingConsumerHasTrailingByRefNamedLocal(Op $cfgCallOp): bool
    {
        if (
            !($cfgCallOp instanceof Op\Expr\FuncCall || $cfgCallOp instanceof Op\Expr\NsFuncCall)
            || !property_exists($cfgCallOp, 'args')
            || !\is_array($cfgCallOp->args)
        ) {
            return false;
        }
        $argCount = \count($cfgCallOp->args);
        if ($argCount < 2) {
            return false;
        }
        for ($i = $argCount - 1; $i >= 0; --$i) {
            $arg = $cfgCallOp->args[$i] ?? null;
            if (!($arg instanceof Operand)) {
                break;
            }
            if ($this->isByRefNamedCallArgExcludedFromSiblingProducerWiring($cfgCallOp, $i, $arg)) {
                return true;
            }
            if (!$this->isEmbeddedCallLiteralArg($arg)) {
                return false;
            }
        }

        return false;
    }

    /**
     * Outer hoisted producer for a multi-arg dead inline call arg — CFG sibling order (#15488, #16280).
     *
     * @param list<Op> $cfgChildren
     */
    private function outerSiblingInlineCallArgProducerForArgIndex(
        Op $cfgCallOp,
        int $argIndex,
        int $firstSibling,
        int $callIndex,
        array $cfgChildren
    ): ?Op\Expr {
        if (!\is_array($cfgCallOp->args ?? null)) {
            return null;
        }
        $argCount = \count($cfgCallOp->args);
        $outer = $this->outerSiblingInlineFuncCallProducers($firstSibling, $callIndex, $cfgChildren);
        if (\count($outer) >= $argCount) {
            $outer = \array_slice($outer, -$argCount);
        }
        if (\count($outer) !== $argCount || $argIndex >= $argCount) {
            return null;
        }
        $callArg = $cfgCallOp->args[$argIndex] ?? null;
        if ($callArg instanceof Operand) {
            $direct = $this->matchDirectResultInlineCallArgProducer($outer, $callArg);
            if ($direct instanceof Op\Expr) {
                return $direct;
            }
        }
        $deadInlineTempCount = 0;
        foreach ($cfgCallOp->args as $deadArg) {
            if ($this->callArgIsDeadInlineTemporary($deadArg)) {
                ++$deadInlineTempCount;
            }
        }
        if ($deadInlineTempCount !== $argCount) {
            return null;
        }
        $leadingEmbedded = 0;
        foreach ($cfgCallOp->args as $embeddedArg) {
            if ($this->isEmbeddedCallLiteralArg($embeddedArg)) {
                ++$leadingEmbedded;
                continue;
            }
            break;
        }
        $outerOrdinal = $argIndex - $leadingEmbedded;
        if ($outerOrdinal < 0 || !isset($outer[$outerOrdinal])) {
            return null;
        }
        $outerProducer = $outer[$outerOrdinal];
        if (!$outerProducer instanceof Op\Expr) {
            return null;
        }

        return $outerProducer;
    }

    /**
     * FUNCCALL_EXEC_RETURN / operand slot for outer hoisted producer of a dead inline call arg (#16280).
     *
     * @param list<OpCode> $emitOps
     */
    private function outerSiblingInlineCallArgProducerExecReturnSlot(
        Block $block,
        Op $cfgCallOp,
        int $argIndex,
        ?array &$emitOps = null
    ): ?string {
        if (null === $block->orig || !\is_array($cfgCallOp->args ?? null)) {
            return null;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (!\is_int($callIndex)) {
            return null;
        }
        $firstSibling = $this->firstSiblingInlineFuncCallProducerIndex($callIndex, $block->orig->children);
        if (null === $firstSibling) {
            return null;
        }
        $outerProducer = $this->outerSiblingInlineCallArgProducerForArgIndex(
            $cfgCallOp,
            $argIndex,
            $firstSibling,
            $callIndex,
            $block->orig->children
        );
        if (!$outerProducer instanceof Op\Expr || null === $outerProducer->result) {
            return null;
        }
        if (null === $block->slotForOperand($outerProducer->result)) {
            $prevForce = $this->forceDeferredSiblingCallReturnSlot;
            $this->forceDeferredSiblingCallReturnSlot = true;
            try {
                foreach ($this->compileExpr($outerProducer, $block) as $op) {
                    if (null !== $emitOps) {
                        $emitOps[] = $op;
                    } else {
                        $block->addOpCode($op);
                    }
                }
            } finally {
                $this->forceDeferredSiblingCallReturnSlot = $prevForce;
            }
        }
        $operandSlot = $block->slotForOperand($outerProducer->result);
        if (null !== $operandSlot) {
            return (string) $operandSlot;
        }
        $execReturn = $this->slotForInlineCallArgProducerResult(
            $block,
            $outerProducer,
            $cfgCallOp,
            $block->orig->children
        );

        return null !== $execReturn ? $execReturn : null;
    }

    /**
     * Nth hoisted sibling FuncCall producer's final EXEC_RETURN slot (#15476, #15848).
     */
    private function slotForSiblingInlineFuncCallProducerExecReturnOrdinal(Block $block, int $producerOrdinal): ?int
    {
        if ($producerOrdinal < 0) {
            return null;
        }
        $execReturnSlots = $block->funccallExecReturnSlots();
        if ($producerOrdinal >= \count($execReturnSlots)) {
            return null;
        }

        return $execReturnSlots[$producerOrdinal];
    }

    /**
     * @param list<OpCode> $pendingNestedProducerOps
     */
    private function slotForSiblingInlineFuncCallProducerExecReturnOrdinalWithPending(
        Block $block,
        int $producerOrdinal,
        array $pendingNestedProducerOps = []
    ): ?int {
        if ($producerOrdinal < 0) {
            return null;
        }
        $execReturnSlots = $block->funccallExecReturnSlots();
        if ([] !== $pendingNestedProducerOps) {
            foreach ($pendingNestedProducerOps as $op) {
                if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type && null !== $op->arg1) {
                    $execReturnSlots[] = (int) $op->arg1;
                }
            }
        }
        if ($producerOrdinal >= \count($execReturnSlots)) {
            return null;
        }

        return $execReturnSlots[$producerOrdinal];
    }

    /** Last emitted FUNCCALL_EXEC_RETURN for a literal callee name (emission order, not cfg index). */
    private function slotForLastEmittedFuncCallExecReturnByName(Block $block, string $calleeName): ?string
    {
        $needle = strtolower($calleeName);
        $slot = null;
        $pending = false;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                $initName = $this->resolveCompileTimeStringSlot((int) $op->arg1, $block);
                $pending = $needle === strtolower($initName ?? '');
            }
            if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type && null !== $op->arg1) {
                if ($pending) {
                    $slot = (string) $op->arg1;
                }
                $pending = false;
            }
        }

        return $slot;
    }


    /**
     * var_export(..., true) — hoisted ConstFetch true may not be emitted before ARG_SEND rewire (#18164).
     */
    private function slotForVarExportHoistedReturnTruePrelude(Block $block, Op $cfgCallOp): ?string
    {
        if (null === $block->orig) {
            return null;
        }
        foreach ($this->hoistedPreludeProducersImmediatelyBeforeCall($cfgCallOp, $block) as $prelude) {
            if (!$prelude instanceof Op\Expr\ConstFetch) {
                continue;
            }
            $name = $this->staticNameFromOperand($prelude->name);
            if ('true' !== strtolower($name ?? '')) {
                continue;
            }
            if (null === $block->slotForOperand($prelude->result)) {
                foreach ($this->compileExpr($prelude, $block) as $op) {
                    $block->addOpCode($op);
                }
            }
            $slot = $block->slotForOperand($prelude->result);

            return null !== $slot ? (string) $slot : null;
        }

        return null;
    }

    /**
     * var_export(INF, true) twice — hoisted scalar ConstFetch (INF/NAN/…) is arg #0, not prior var_export EXEC_RETURN (#18426).
     *
     * @param list<OpCode> $emitOps
     */
    private function slotForVarExportHoistedScalarConstArgZero(
        Block $block,
        Op $cfgCallOp,
        array &$emitOps = []
    ): ?string {
        if (null === $block->orig || !\is_array($cfgCallOp->args ?? null)) {
            return null;
        }
        $callArg = $cfgCallOp->args[0] ?? null;
        if (!$this->callArgIsDeadInlineTemporary($callArg)) {
            return null;
        }
        $preludes = $this->hoistedPreludeProducersImmediatelyBeforeCall($cfgCallOp, $block);
        while ([] !== $preludes) {
            $tail = $preludes[\count($preludes) - 1];
            if ($tail instanceof Op\Expr\ConstFetch) {
                $tailName = strtolower($this->staticNameFromOperand($tail->name) ?? '');
                if (\in_array($tailName, ['true', 'false', 'null'], true)) {
                    array_pop($preludes);
                    continue;
                }
            }
            break;
        }
        if ([] === $preludes) {
            return null;
        }
        $argZeroPrelude = $preludes[\count($preludes) - 1];
        if (
            $argZeroPrelude instanceof Op\Expr\UnaryMinus
            || $argZeroPrelude instanceof Op\Expr\UnaryPlus
        ) {
            if (null === $block->slotForOperand($argZeroPrelude->result)) {
                foreach ($this->compileExpr($argZeroPrelude, $block) as $op) {
                    if ([] !== $emitOps) {
                        $emitOps[] = $op;
                    } else {
                        $block->addOpCode($op);
                    }
                }
            }
            $slot = $block->slotForOperand($argZeroPrelude->result);

            return null !== $slot ? (string) $slot : null;
        }
        if (!$argZeroPrelude instanceof Op\Expr\ConstFetch) {
            return null;
        }
        $name = strtolower($this->staticNameFromOperand($argZeroPrelude->name) ?? '');
        if (\in_array($name, ['true', 'false', 'null'], true)) {
            return null;
        }
        if (null === $block->slotForOperand($argZeroPrelude->result)) {
            foreach ($this->compileExpr($argZeroPrelude, $block) as $op) {
                if ([] !== $emitOps) {
                    $emitOps[] = $op;
                } else {
                    $block->addOpCode($op);
                }
            }
        }
        $slot = $block->slotForOperand($argZeroPrelude->result);
        if (null === $slot) {
            $slot = $this->slotForRecentConstFetchNamedLiteral($block, $name);
        }

        return null !== $slot ? (string) $slot : null;
    }

    /** Recent TYPE_CONST_FETCH for a global literal name (dead-temp vs hoisted-result drift, #18426). */
    private function slotForRecentConstFetchNamedLiteral(Block $block, string $literalName): ?string
    {
        $needle = strtolower($literalName);
        $i = \count($block->opCodes) - 1;
        while ($i >= 0 && OpCode::TYPE_FUNCCALL_INIT === $block->opCodes[$i]->type) {
            --$i;
        }
        for (; $i >= 0; --$i) {
            $op = $block->opCodes[$i];
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                break;
            }
            if (OpCode::TYPE_CONST_FETCH !== $op->type || null === $op->arg1 || null === $op->arg2) {
                continue;
            }
            $resolved = null;
            if (\is_string($op->arg2)) {
                $resolved = strtolower($op->arg2);
            } elseif (\is_int($op->arg2)) {
                $const = $block->constants[$op->arg2] ?? null;
                if (null !== $const && Variable::TYPE_STRING === $const->type) {
                    $resolved = strtolower($const->toString());
                } else {
                    $resolved = strtolower($this->resolveCompileTimeStringSlot($op->arg2, $block) ?? '');
                }
            }
            if ('' === $resolved) {
                continue;
            }

            return $needle === $resolved ? (string) $op->arg1 : null;
        }

        return null;
    }

    /**
     * var_export($it->current(), true) — MethodCall EXEC_RETURN ordinal uses legacy base (#13901, #17251).
     *
     * @param list<Op> $cfgChildren
     */
    private function slotForSiblingMethodCallProducerExecReturn(
        Block $block,
        Op\Expr $producer,
        Op $consumer,
        array $cfgChildren
    ): ?string {
        if (
            !$producer instanceof Op\Expr\MethodCall
            && !$producer instanceof Op\Expr\StaticCall
        ) {
            return null;
        }
        // Pair METHODCALL_INIT → EXEC_RETURN by callee name. Ordinal EXEC_RETURN indexing
        // drifts when prior stmts (loadXML) are EXEC_NORETURN but still inflate legacyBase
        // — bare $d->documentElement->replaceChild(createElement, item) (#21182).
        $paired = $this->slotForMethodOrStaticCallInitFollowingExecReturn($block, $producer);
        if (null !== $paired) {
            return $paired;
        }
        // Dead-temp createElement/item not yet on the block: ordinal EXEC_RETURN would bind a
        // prior loadXML return slot so ARG_SEND gets bool and createElement never emits (#34436).
        if (
            $producer instanceof Op\Expr\MethodCall
            && (null === $producer->result || empty($producer->result->usages))
        ) {
            return null;
        }
        $producerIndex = $this->cfgCallOpIndexInChildren($cfgChildren, $producer);
        $consumerIndex = array_search($consumer, $cfgChildren, true);
        if (!is_int($producerIndex) || !is_int($consumerIndex)) {
            return null;
        }
        $firstSibling = $this->firstSiblingInlineFuncCallProducerIndex($consumerIndex, $cfgChildren);
        if (null === $firstSibling || $producerIndex < $firstSibling || $producerIndex >= $consumerIndex) {
            return null;
        }
        $producerOrdinal = $this->siblingInlineFuncCallProducerOrdinal(
            $producerIndex,
            $firstSibling,
            $cfgChildren
        );
        $legacyBase = $this->execReturnOrdinalBaseBeforeSiblingInlineFuncCallChain(
            $firstSibling,
            $cfgChildren,
            $consumerIndex
        );
        $execSlot = $this->slotForSiblingInlineFuncCallProducerExecReturnOrdinal(
            $block,
            $legacyBase + $producerOrdinal
        );

        return null !== $execSlot ? (string) $execSlot : null;
    }

    /**
     * Hoisted ConstFetch immediately before a multi-arg consumer — map dead temps to literal slots (#16272, #13829).
     *
     * @param list<OpCode> $emitOps
     */
    private function slotForImmediateConstFetchPreludeCallArg(
        Block $block,
        Op $cfgCallOp,
        int $argIndex,
        array &$emitOps = []
    ): ?string {
        if (null === $block->orig || !\is_array($cfgCallOp->args ?? null)) {
            return null;
        }
        $callArg = $cfgCallOp->args[$argIndex] ?? null;
        if (!$this->callArgIsDeadInlineTemporary($callArg)) {
            return null;
        }
        // in_array('x', g(), true) — immediate ConstFetch is strict, not haystack (#16265).
        if (
            \in_array(strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? ''), ['in_array', 'array_search'], true)
            && 1 === $argIndex
            && $this->callArgOperandExpectsArrayProducer($callArg)
        ) {
            return null;
        }
        $hoistedScalarSlot = $this->tryFoldHoistedBoolNullLiteralCallArg(
            $callArg,
            $block,
            $cfgCallOp,
            $argIndex
        );
        if (null !== $hoistedScalarSlot) {
            return (string) $hoistedScalarSlot;
        }

        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (!\is_int($callIndex) || $callIndex < 1) {
            return null;
        }
        $immediatePrelude = $block->orig->children[$callIndex - 1] ?? null;
        if (
            !($immediatePrelude instanceof Op\Expr\ConstFetch || $immediatePrelude instanceof Op\Expr\ClassConstFetch)
            || null === $immediatePrelude->result
        ) {
            return null;
        }
        if (null === $block->slotForOperand($immediatePrelude->result)) {
            foreach ($this->compileExpr($immediatePrelude, $block) as $op) {
                if ([] !== $emitOps) {
                    $emitOps[] = $op;
                } else {
                    $block->addOpCode($op);
                }
            }
        }
        $constSlot = $block->slotForOperand($immediatePrelude->result);
        $callArg = $cfgCallOp->args[$argIndex] ?? null;
        if (
            $immediatePrelude instanceof Op\Expr\ClassConstFetch
            && null !== $callArg
            && null !== $constSlot
            && (
                $immediatePrelude->result === $callArg
                || $this->operandsReferToSameVariable($immediatePrelude->result, $callArg)
            )
        ) {
            // method_exists(I::class, 'm') after earlier stmts — hoisted ::class is arg #0 (#9486).
            return (string) $constSlot;
        }
        if (
            0 === $argIndex
            && $immediatePrelude instanceof Op\Expr\ConstFetch
            && (
                null !== $this->nestedFuncCallProducerBeforeTrailingConstFetchPreludes(
                    $cfgCallOp,
                    $callIndex,
                    $block->orig->children
                )
                || null !== $this->nonConstInlineProducerBeforeTrailingScalarConstFetchPreludes(
                    $callIndex,
                    $block->orig->children
                )
            )
        ) {
            // var_export(g(), true) / importNode($doc->documentElement, true) — trailing ConstFetch is not arg #0 (#11272, #16318).
            return null;
        }
        if (
            0 === $argIndex
            && null !== $callArg
            && $this->callArgOperandExpectsArrayProducer($callArg)
            && !($immediatePrelude instanceof Op\Expr\Array_)
        ) {
            // array_pad([E::A], N, E::B) — immediate ClassConstFetch is pad value, not haystack (#8883).
            return null;
        }
        if (
            0 === $argIndex
            && 'array_pad' === strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '')
            && !($immediatePrelude instanceof Op\Expr\Array_)
        ) {
            return null;
        }
        if (
            0 === $argIndex
            && 'extract' === strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '')
            && !($immediatePrelude instanceof Op\Expr\Array_)
        ) {
            return null;
        }

        return null !== $constSlot ? (string) $constSlot : null;
    }

    /**
     * var_dump($g(), $g()) — ARG_SEND must use FUNCCALL_EXEC_RETURN slots, not drifted operand temps (#16029).
     */
    private function finalSiblingInlineCallArgSendSlot(
        Block $block,
        Op $cfgCallOp,
        int $argIndex
    ): ?string {
        if (null === $block->orig || !\is_array($cfgCallOp->args ?? null) || \count($cfgCallOp->args) < 2) {
            return null;
        }
        if (
            1 === $argIndex
            && 'substr' === strtolower($this->resolveInlineCallArgFuncName($cfgCallOp, null) ?? '')
        ) {
            $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
            if (\is_int($callIndex)) {
                $cfgChildren = $block->orig->children;
                for ($j = $callIndex - 1; $j >= 0; --$j) {
                    $scan = $cfgChildren[$j] ?? null;
                    if (
                        ($scan instanceof Op\Expr\FuncCall || $scan instanceof Op\Expr\NsFuncCall)
                        && $this->isNestedCallArgProducerForConsumer($scan, $cfgCallOp, $j, $callIndex, $cfgChildren)
                        && 0 === $this->siblingMultiArgFuncCallProducerTargetArgIndex($j, $callIndex, $cfgChildren)
                    ) {
                        return null;
                    }
                }
            }
        }
        $callArg = $cfgCallOp->args[$argIndex] ?? null;
        if (!$this->callArgIsDeadInlineTemporary($callArg)) {
            return null;
        }
        $constPreludeSlot = $this->slotForImmediateConstFetchPreludeCallArg($block, $cfgCallOp, $argIndex);
        if (null !== $constPreludeSlot) {
            return $constPreludeSlot;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (!\is_int($callIndex)) {
            return null;
        }
        $firstSibling = $this->firstSiblingInlineFuncCallProducerIndex($callIndex, $block->orig->children);
        if (null === $firstSibling) {
            return null;
        }
        $argCount = \count($cfgCallOp->args);
        $deadInlineTempCount = 0;
        foreach ($cfgCallOp->args as $callArg) {
            if ($this->callArgIsDeadInlineTemporary($callArg)) {
                ++$deadInlineTempCount;
            }
        }
        $outer = $this->outerSiblingInlineFuncCallProducers(
            $firstSibling,
            $callIndex,
            $block->orig->children
        );
        if (\count($outer) >= $argCount) {
            $outer = \array_slice($outer, -$argCount);
        }
        if (
            \count($outer) === $argCount
            && $deadInlineTempCount === $argCount
        ) {
            $outerSlot = $this->outerSiblingInlineCallArgProducerExecReturnSlot($block, $cfgCallOp, $argIndex);
            if (null !== $outerSlot) {
                return $outerSlot;
            }
        }
        $siblingFuncCount = $this->countSiblingInlineFuncCallProducers(
            $firstSibling,
            $callIndex,
            $block->orig->children
        );
        if ($siblingFuncCount < 2) {
            $nestedSlot = $this->resolveAdjacentNestedFuncCallArgSlot($block, $cfgCallOp, $argIndex);
            if (null !== $nestedSlot) {
                return $nestedSlot;
            }

            return null;
        }
        if ($this->callIncludesNamedParameter($cfgCallOp)) {
            return null;
        }
        for ($j = $firstSibling; $j < $callIndex; ++$j) {
            $between = $block->orig->children[$j] ?? null;
            if ($between instanceof Op\Expr\Array_) {
                return null;
            }
        }
        $leadingEmbedded = 0;
        foreach ($cfgCallOp->args as $embeddedArg) {
            if ($this->isEmbeddedCallLiteralArg($embeddedArg)) {
                ++$leadingEmbedded;
                continue;
            }
            break;
        }
        // array_intersect(f(g()), f(g())) — map args to outer f() EXEC_RETURN slots, not inner g() (#15488, #16031).
        $outerSlot = $this->outerSiblingInlineCallArgProducerSlot($block, $cfgCallOp, $argIndex);
        if (null !== $outerSlot) {
            return $outerSlot;
        }
        // probe('label', in_array(..., g(), true)) — one hoisted arg maps to adjacent callee, not firstSibling (#16253).
        if (1 === $deadInlineTempCount && $callIndex > 0) {
            $adjacentIndex = $callIndex - 1;
            while ($adjacentIndex >= 0) {
                $adjacentSkip = $block->orig->children[$adjacentIndex] ?? null;
                if ($adjacentSkip instanceof Op\Expr\ConstFetch || $adjacentSkip instanceof Op\Expr\ClassConstFetch) {
                    --$adjacentIndex;
                    continue;
                }
                break;
            }
            $adjacentProducer = $block->orig->children[$adjacentIndex] ?? null;
            if (
                ($adjacentProducer instanceof Op\Expr\FuncCall || $adjacentProducer instanceof Op\Expr\NsFuncCall)
                && $this->isAdjacentNestedFuncCallProducer(
                    $adjacentProducer,
                    $cfgCallOp,
                    $adjacentIndex,
                    $callIndex
                )
            ) {
                $adjacentTargetArg = $this->siblingMultiArgFuncCallProducerTargetArgIndex(
                    $adjacentIndex,
                    $callIndex,
                    $block->orig->children
                );
                if (null === $adjacentTargetArg) {
                    $adjacentTargetArg = $leadingEmbedded;
                }
                if ($adjacentTargetArg === $argIndex) {
                    $adjacentExecReturn = $this->slotForInlineFuncCallProducerExecReturnByCfgIndex(
                        $block,
                        $adjacentIndex,
                        $block->orig->children
                    );
                    if (null !== $adjacentExecReturn) {
                        return (string) $adjacentExecReturn;
                    }
                }
            }
        }
        // substr(sprintf('%o', fileperms($path)), -N) — arg #0 is adjacent nested sprintf, not ordinal-0 fileperms (#16451, #16480).
        if ('substr' === strtolower($this->resolveInlineCallArgFuncName($cfgCallOp, null) ?? '')) {
            $adjacentNestedSlot = $this->resolveAdjacentNestedFuncCallArgSlot($block, $cfgCallOp, $argIndex);
            if (null !== $adjacentNestedSlot) {
                return $adjacentNestedSlot;
            }
        }
        // replaceWith($d->createElement('x'), 'txt', $d->createElement('y')) — when an embedded
        // literal sits between MethodCall producers, map by non-embedded dead-temp ordinal (#21901).
        $hasEmbeddedLiteralArg = false;
        foreach ($cfgCallOp->args as $scanArg) {
            if ($this->isEmbeddedCallLiteralArg($scanArg)) {
                $hasEmbeddedLiteralArg = true;
                break;
            }
        }
        if ($hasEmbeddedLiteralArg) {
            $nonEmbeddedDeadIndices = [];
            foreach ($cfgCallOp->args as $i => $scanArg) {
                if (
                    $scanArg instanceof Operand
                    && $this->callArgIsDeadInlineTemporary($scanArg)
                    && !$this->isEmbeddedCallLiteralArg($scanArg)
                ) {
                    $nonEmbeddedDeadIndices[] = (int) $i;
                }
            }
            $producerOrdinal = array_search($argIndex, $nonEmbeddedDeadIndices, true);
            if (false === $producerOrdinal) {
                return null;
            }
            $producerOrdinal = (int) $producerOrdinal;
        } else {
            $producerOrdinal = $argIndex - $leadingEmbedded;
        }
        if ($producerOrdinal < 0 || $producerOrdinal >= $siblingFuncCount) {
            return null;
        }
        $producerIndex = $this->siblingInlineFuncCallProducerIndexAtOrdinal(
            $producerOrdinal,
            $firstSibling,
            $callIndex,
            $block->orig->children
        );
        if (null === $producerIndex) {
            return null;
        }
        $producer = $block->orig->children[$producerIndex] ?? null;
        if (!$producer instanceof Op\Expr || !$this->isSiblingInlineCallProducerExpr($producer)) {
            return null;
        }
        $targetArgForProducer = $this->siblingMultiArgFuncCallProducerTargetArgIndex(
            $producerIndex,
            $callIndex,
            $block->orig->children
        );
        if (null !== $targetArgForProducer && $targetArgForProducer !== $argIndex) {
            return null;
        }
        if (
            $hasEmbeddedLiteralArg
            && ($producer instanceof Op\Expr\MethodCall || $producer instanceof Op\Expr\StaticCall)
        ) {
            $methodSlot = $this->slotForMethodOrStaticCallInitFollowingExecReturnMatchingArgs(
                $block,
                $producer
            );
            if (null !== $methodSlot) {
                return $methodSlot;
            }
        }
        $execReturn = $this->slotForInlineFuncCallProducerExecReturnByCfgIndex(
            $block,
            $producerIndex,
            $block->orig->children
        );
        if (null === $execReturn) {
            $chainProducerCount = $this->countSiblingInlineFuncCallProducers(
                $firstSibling,
                $callIndex,
                $block->orig->children
            );
            $execReturnCount = $block->funccallExecReturnCount();
            if ($this->forceDeferredSiblingCallReturnSlot) {
                $execOrdinal = $execReturnCount;
            } else {
                $execOrdinal = $execReturnCount - $chainProducerCount + $producerOrdinal;
            }
            $execReturn = $this->slotForSiblingInlineFuncCallProducerExecReturnOrdinal($block, $execOrdinal);
        }

        return null !== $execReturn ? (string) $execReturn : null;
    }

}
