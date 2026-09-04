<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;

use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Operand\Literal;
use PHPCfg\Operand\Temporary;

/**
 * Sibling / multi-arg inline FuncCall producer discovery (#36387 / #36403).
 *
 * Extracted from {@see \PHPCompiler\Compiler} so the hub can shrink toward
 * host-CFG split-TU emit under SPINE_CHUNK (gen-0 <30m).
 *
 * Covers {@see isSiblingInlineCallProducerExpr},
 * {@see computeIsSiblingMultiArgFuncCallProducer},
 * {@see computeFirstSiblingInlineFuncCallProducerIndex},
 * {@see ensureDeferredSiblingInlineCallArgProducersCompiled}, and the
 * contiguous sibling / hoisted multi-arg helpers they share.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; call-arg
 * slot wiring relies on coercion (same as PrecedingInlineCallArgProducers).
 */
trait SiblingInlineFuncCallProducers
{
    /** Hoisted FuncCall / MethodCall / StaticCall sibling inline call-arg producers (#9463, #9351, #12421). */
    private function isSiblingInlineCallProducerExpr(?Op $op): bool
    {
        return null !== $op
            && ($op instanceof Op\Expr\FuncCall
            || $op instanceof Op\Expr\NsFuncCall
            || $op instanceof Op\Expr\MethodCall
            || $op instanceof Op\Expr\StaticCall);
    }

    /**
     * php-cfg `var_dump($g(), $g())` hoists sibling FuncCall producers with dead arg temps (#9463).
     *
     * @param list<Op> $cfgChildren
     */
    private function isSiblingMultiArgFuncCallProducer(
        Op\Expr $producer,
        Op $consumer,
        int $producerIndex,
        int $consumerIndex,
        array $cfgChildren
    ): bool {
        $cacheKey = spl_object_id($producer).':'.spl_object_id($consumer);
        if (\array_key_exists($cacheKey, $this->isSiblingMultiArgFuncCallProducerCache)) {
            return $this->isSiblingMultiArgFuncCallProducerCache[$cacheKey];
        }
        if (isset($this->isSiblingMultiArgFuncCallProducerComputing[$cacheKey])) {
            // Reentrant same-pair during firstSibling/scan — mirror Active-null conservatism (#36387).
            return false;
        }
        $this->isSiblingMultiArgFuncCallProducerComputing[$cacheKey] = true;
        try {
            $result = $this->computeIsSiblingMultiArgFuncCallProducer(
                $producer,
                $consumer,
                $producerIndex,
                $consumerIndex,
                $cfgChildren
            );
        } finally {
            unset($this->isSiblingMultiArgFuncCallProducerComputing[$cacheKey]);
        }
        $this->isSiblingMultiArgFuncCallProducerCache[$cacheKey] = $result;

        return $result;
    }

    /**
     * @param list<Op> $cfgChildren
     */
    private function computeIsSiblingMultiArgFuncCallProducer(
        Op\Expr $producer,
        Op $consumer,
        int $producerIndex,
        int $consumerIndex,
        array $cfgChildren
    ): bool {
        if (!$this->isSiblingInlineCallProducerExpr($producer)) {
            return false;
        }
        // Fast reject outside the near sibling window (nested stmts were O(n²) — #36387).
        if (!property_exists($consumer, 'args') || !\is_array($consumer->args)) {
            return false;
        }
        $argCount = \count($consumer->args);
        if ($argCount < 2) {
            return false;
        }
        $maxDistance = max(8, $argCount * 4);
        if (($consumerIndex - $producerIndex) > $maxDistance) {
            return false;
        }
        if (
            ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall)
            && $this->funcCallExprHasByRefMutatingSideEffects($producer)
        ) {
            return false;
        }
        if (
            ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall)
            && $this->isStatementLevelSideEffectFuncCall($producer)
        ) {
            // fwrite/rewind before var_export(fread(...), true) must not re-emit (#25084).
            return false;
        }
        // show(strtoupper(...), [...]); show(...) — prior multi-arg statement with dead-temp args is
        // not a hoisted value producer for the next call (#26367). Nested value producers that feed
        // the consumer (array_merge(array_merge(...), ...)) still match via feedsConsumer.
        if (
            ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall)
            && property_exists($producer, 'args')
            && \is_array($producer->args)
            && \count($producer->args) >= 2
            && $this->deadInlineTemporaryArgCount($producer) >= 2
            && !$this->inlineCallArgProducerFeedsConsumer($producer, $consumer)
        ) {
            return false;
        }
        if (
            $producer instanceof Op\Expr\MethodCall
            && $this->methodCallHasStatementLevelSideEffects($producer)
        ) {
            // Keep `f($o->next(), $o->next())` in the sibling chain; skip bare `$it->next()` (#25672 / #13901).
            $deadInlineArgCount = $this->deadInlineTemporaryArgCount($consumer);
            if (
                $deadInlineArgCount < 1
                || ($consumerIndex - $producerIndex) > $deadInlineArgCount
            ) {
                return false;
            }
        }
        // getElementsByTagName()->item(0) before importNode(..., true) — receiver MethodCall must
        // emit before the leaf item() sibling even though it does not bind a consumer arg temp
        // (#25702, re-#20284/#25605). Do not recurse into isSiblingMultiArgFuncCallProducer.
        if (
            $producer instanceof Op\Expr\MethodCall
            && null !== $producer->result
            && !empty($producer->result->usages)
        ) {
            for ($j = $producerIndex + 1; $j < $consumerIndex; ++$j) {
                $later = $cfgChildren[$j] ?? null;
                if (!($later instanceof Op\Expr\MethodCall)) {
                    continue;
                }
                if (
                    !property_exists($later, 'var')
                    || null === $later->var
                    || !$this->operandsReferToSameVariable($producer->result, $later->var)
                ) {
                    continue;
                }
                if ($this->isNestedCallArgProducerSeparatedByConsumerLiteralPreludes(
                    $later,
                    $consumer,
                    $j,
                    $consumerIndex,
                    $cfgChildren
                )) {
                    return true;
                }
                // replaceChild(..., getElementsByTagName()->item(0)) — leaf item is adjacent (#25563).
                // Require ≥2 consumer args: isSiblingMultiArgInlineCallConsumer matches any
                // MethodCall, and treating 0/1-arg finals (getLineNo/getAttribute) as multi-arg
                // consumers deferred getElementsByTagName with nothing to re-emit (#25842).
                // Also allow ConstFetch between leaf and consumer for var_export(..., true) (#25841).
                // The MethodCall that *uses* $producer ($later) must itself feed $consumer — not a
                // discarded statement like item()->appendChild(); dump23514(...) (#25949, re-#23514).
                if (
                    $this->isSiblingMultiArgInlineCallConsumer($consumer)
                    && \is_array($consumer->args ?? null)
                    && \count($consumer->args) >= 2
                    && (
                        $j === $consumerIndex - 1
                        || $this->onlyScalarConstFetchPreludesBetween($j, $consumerIndex, $cfgChildren)
                    )
                    && null !== $later->result
                    && !empty($later->result->usages)
                ) {
                    foreach ($later->result->usages as $laterUsage) {
                        if ($laterUsage === $consumer) {
                            return true;
                        }
                    }
                }
            }
        }
        if ($this->isNestedCallArgProducerSeparatedByConsumerLiteralPreludes(
            $producer,
            $consumer,
            $producerIndex,
            $consumerIndex,
            $cfgChildren
        )) {
            return true;
        }
        // Prior array_udiff*(...) / array_uintersect*(...) stmts are not arg producers for a later u* call (#16045).
        if (
            ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall)
            && ($consumer instanceof Op\Expr\FuncCall || $consumer instanceof Op\Expr\NsFuncCall)
            && $this->builtinUsesTrailingComparatorCallback($this->resolveCfgFuncCallName($producer))
            && $this->builtinUsesTrailingComparatorCallback($this->resolveCfgFuncCallName($consumer))
        ) {
            return false;
        }
        if (
            !$this->isSiblingMultiArgInlineCallConsumer($consumer)
        ) {
            return false;
        }
        if (!property_exists($consumer, 'args') || !is_array($consumer->args)) {
            return false;
        }
        if ($this->callIncludesNamedParameter($consumer)) {
            return false;
        }
        $argCount = count($consumer->args);
        if ($argCount < 2) {
            return false;
        }
        if ($this->consumerHasTrailingHoistedScalarConstFetchArgPreludes($consumer, $consumerIndex, $cfgChildren)) {
            return false;
        }
        // Statement-level calls before fscanf($f, '…') are not sibling arg producers (#11093).
        // Trailing by-ref named locals (similar_text $percent, preg_match $matches) are not producers (#15476).
        foreach ($consumer->args as $argIndex => $consumerArg) {
            if (!($consumerArg instanceof Operand)) {
                continue;
            }
            if ($this->isByRefNamedCallArgExcludedFromSiblingProducerWiring($consumer, (int) $argIndex, $consumerArg)) {
                continue;
            }
            if ($this->isNamedVariableOperand($consumerArg)) {
                return false;
            }
        }
        $firstSibling = $this->firstSiblingInlineFuncCallProducerIndex($consumerIndex, $cfgChildren);
        if (null === $firstSibling) {
            return false;
        }
        $arrayPreludeChain = $this->siblingFuncCallChainHasArrayPrelude($firstSibling, $consumerIndex, $cfgChildren);
        if ($arrayPreludeChain) {
            if (
                'array_pad' === strtolower($this->resolveCfgFuncCallName($consumer) ?? '')
                && $this->priorStmtLevelCallSeparatedByHoistedArrayPreludeOnly(
                    $producerIndex,
                    $consumerIndex,
                    $cfgChildren
                )
            ) {
                return false;
            }
            $producerOrdinal = $this->siblingInlineFuncCallProducerOrdinal(
                $producerIndex,
                $firstSibling,
                $cfgChildren
            );
            if ($producerOrdinal < 0 || $producerOrdinal >= $argCount) {
                return false;
            }
        } else {
            $outer = $this->outerSiblingInlineFuncCallProducers($firstSibling, $consumerIndex, $cfgChildren);
            $hoistedArgCount = 0;
            $deadInlineTempCount = 0;
            foreach ($consumer->args as $hoistedArgIndex => $consumerArg) {
                if (null !== $consumerArg && !$this->isEmbeddedCallLiteralArg($consumerArg)) {
                    if ($this->isByRefNamedCallArgExcludedFromSiblingProducerWiring($consumer, (int) $hoistedArgIndex, $consumerArg)) {
                        continue;
                    }
                    ++$hoistedArgCount;
                }
                if ($this->callArgIsDeadInlineTemporary($consumerArg)) {
                    ++$deadInlineTempCount;
                }
            }
            if (
                \count($outer) === $argCount
                && ($deadInlineTempCount === $argCount || $hoistedArgCount === \count($outer))
                && \count($outer) < $consumerIndex - $firstSibling
            ) {
                $outerOrdinal = $this->outerSiblingInlineFuncCallProducerOrdinal(
                    $producer,
                    $firstSibling,
                    $consumerIndex,
                    $cfgChildren
                );
                if (null === $outerOrdinal || $outerOrdinal < 0 || $outerOrdinal >= $argCount) {
                    return false;
                }
            } elseif (
                \count($outer) === $hoistedArgCount
                && \count($outer) < $consumerIndex - $firstSibling
            ) {
                $outerOrdinal = $this->outerSiblingInlineFuncCallProducerOrdinal(
                    $producer,
                    $firstSibling,
                    $consumerIndex,
                    $cfgChildren
                );
                if (null === $outerOrdinal || $outerOrdinal < 0 || $outerOrdinal >= $argCount) {
                    return false;
                }
            } else {
                $firstSiblingForDistance = $this->firstSiblingInlineFuncCallProducerIndex($consumerIndex, $cfgChildren);
                if (null !== $firstSiblingForDistance) {
                    $producerOrdinal = $this->hoistedSiblingCallArgProducerOrdinal(
                        $producerIndex,
                        $firstSiblingForDistance,
                        $consumerIndex,
                        $cfgChildren
                    );
                    $effectiveDistance = $producerOrdinal + 1;
                    if ($effectiveDistance < 1 || $effectiveDistance > $argCount) {
                        return false;
                    }
                } else {
                    $distance = $consumerIndex - $producerIndex;
                    if ($distance < 1 || $distance > $argCount) {
                        return false;
                    }
                }
            }
        }
        $targetArgIndex = $this->siblingMultiArgFuncCallProducerTargetArgIndex(
            $producerIndex,
            $consumerIndex,
            $cfgChildren
        );
        if (null === $targetArgIndex) {
            return false;
        }
        // Producer at distance d supplies consumer arg d-1; UnaryMinus prelude shifts arg 0 (#10673).
        $targetArg = $consumer->args[$targetArgIndex] ?? null;
        if (!$this->callArgIsDeadInlineTemporary($targetArg)) {
            return false;
        }
        // Statement-level side effects before f('lit', g(), lit) are not sibling producers (#13509).
        // php-cfg f(g(), h()) — distinct dead arg temps without shared cfg roots (#10917, #13570).
        if (null === $producer->result) {
            return false;
        }
        if (!$this->operandsReferToSameVariable($producer->result, $targetArg)) {
            if (
                $this->stmtLevelNamedFuncCallPrecedesNestedInlineCallArgChain(
                    $producerIndex,
                    $consumerIndex,
                    $cfgChildren
                )
            ) {
                return false;
            }
            if (!$this->siblingMultiArgProducerWiresByDistinctDeadArgOrdinal(
                $producer,
                $producerIndex,
                $consumerIndex,
                $targetArgIndex,
                $cfgChildren
            )) {
                return false;
            }
        }
        for ($j = $producerIndex + 1; $j < $consumerIndex; ++$j) {
            $mid = $cfgChildren[$j] ?? null;
            if (
                $mid instanceof Op\Expr\ConstFetch
                || $mid instanceof Op\Expr\ClassConstFetch
            ) {
                // [sprintf('%F', NAN), sprintf('%G', NAN)] — trailing ConstFetch feeds this call (#12764).
                if (
                    ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall)
                    && $j === $consumerIndex - 1
                ) {
                    $trailingArgIndex = $this->soleNonEmbeddedCallArgIndex($consumer->args)
                        ?? ($argCount - 1);
                    if ($targetArgIndex === $trailingArgIndex) {
                        return false;
                    }
                }
                continue;
            }
            // array_merge(array_keys($src), [...]) — sibling inline Array_ between hoisted producers (#12450).
            if ($mid instanceof Op\Expr\Array_) {
                continue;
            }
            if ($this->isUnaryInlineSiblingCallArgExpr($mid)) {
                continue;
            }
            // sprintf("n=%d avg=%.3f", count($a), $sum / count($a)) — Div between the first
            // count() producer and sprintf must not reject the unused-usages count sibling (#36353).
            // Only skip when this producer is *not* an operand of the BinaryOp (that producer feeds
            // the arithmetic only; the earlier dead-temp count still feeds sprintf).
            if ($mid instanceof Op\Expr\BinaryOp) {
                if (
                    null !== $producer->result
                    && !$this->cfgExprUsesOperand($mid, $producer->result)
                ) {
                    continue;
                }

                return false;
            }
            if ($mid instanceof Op\Expr\ArrowFunction
                || $mid instanceof Op\Expr\Closure
                || $mid instanceof Op\Expr\FirstClassCallable) {
                // array_udiff(array_keys(...), array_keys(...), strcmp(...)) — trailing FCC (#13990).
                continue;
            }
            // is_countable(new ArrayObject()) — New_ prelude between hoisted sibling producers (#14958).
            if ($mid instanceof Op\Expr\New_ || $mid instanceof Op\Expr\Clone_) {
                continue;
            }
            // insertBefore($d->createElement('x'), $r->lastChild) — PropertyFetch sibling arg (#19719).
            if (
                $mid instanceof Op\Expr\PropertyFetch
                || $mid instanceof Op\Expr\NullsafePropertyFetch
                || $mid instanceof Op\Expr\StaticPropertyFetch
            ) {
                continue;
            }
            if (
                $mid instanceof Op\Expr\ArrayDimFetch
                && $this->isArrayDimFetchOnlyIssetVar($mid, $cfgChildren[$j + 1] ?? null)
            ) {
                continue;
            }
            if ($mid instanceof Op\Expr\Isset_ || $mid instanceof Op\Expr\Empty_) {
                continue;
            }
            if ($this->isSiblingInlineCallProducerExpr($mid)) {
                continue;
            }
            return false;
        }

        return true;
    }

    private function isUnaryInlineSiblingCallArgExpr(?Op $op): bool
    {
        return $op instanceof Op\Expr\UnaryMinus
            || $op instanceof Op\Expr\UnaryPlus
            || $op instanceof Op\Expr\BitwiseNot
            || $op instanceof Op\Expr\BooleanNot;
    }

    /**
     * php-cfg f(g(), h()) with distinct dead arg temps — ordinal sibling wiring (#10917, #13570).
     *
     * Skips cfgVarRoot equality when every consumer arg is a dead inline temp and every
     * hoisted stmt from firstSibling..consumer-1 is a FuncCall (not f('lit', g(), lit) #13509).
     *
     * @param list<Op> $cfgChildren
     */
    private function siblingMultiArgProducerWiresByDistinctDeadArgOrdinal(
        Op\Expr $producer,
        int $producerIndex,
        int $consumerIndex,
        int $targetArgIndex,
        array $cfgChildren
    ): bool {
        $consumer = $cfgChildren[$consumerIndex] ?? null;
        if (
            !$this->isSiblingMultiArgInlineCallConsumer($consumer)
        ) {
            return false;
        }
        if (!property_exists($consumer, 'args') || !is_array($consumer->args)) {
            return false;
        }
        $hoistedArgs = [];
        foreach ($consumer->args as $argIndex => $callArg) {
            if (null === $callArg || $this->isEmbeddedCallLiteralArg($callArg)) {
                continue;
            }
            if ($this->isByRefNamedCallArgExcludedFromSiblingProducerWiring($consumer, (int) $argIndex, $callArg)) {
                continue;
            }
            $hoistedArgs[] = $callArg;
        }
        if (!$this->callArgsAreDistinctInlineTemporaries($hoistedArgs)) {
            return false;
        }
        $firstSibling = $this->firstSiblingInlineFuncCallProducerIndex($consumerIndex, $cfgChildren);
        if (null === $firstSibling) {
            return false;
        }
        if ($producerIndex < $firstSibling || $producerIndex >= $consumerIndex) {
            return false;
        }
        for ($j = $firstSibling; $j < $consumerIndex; ++$j) {
            $sib = $cfgChildren[$j] ?? null;
            if ($sib instanceof Op\Expr\ConstFetch || $sib instanceof Op\Expr\ClassConstFetch) {
                continue;
            }
            if ($sib instanceof Op\Expr\Array_) {
                continue;
            }
            if ($this->isUnaryInlineSiblingCallArgExpr($sib)) {
                continue;
            }
            if ($sib instanceof Op\Expr\ArrowFunction
                || $sib instanceof Op\Expr\Closure
                || $sib instanceof Op\Expr\FirstClassCallable) {
                continue;
            }
            if ($sib instanceof Op\Expr\New_ || $sib instanceof Op\Expr\Clone_) {
                continue;
            }
            // insertBefore($d->createElement('x'), $r->lastChild) — PropertyFetch sibling arg (#19719).
            if (
                $sib instanceof Op\Expr\PropertyFetch
                || $sib instanceof Op\Expr\NullsafePropertyFetch
                || $sib instanceof Op\Expr\StaticPropertyFetch
            ) {
                continue;
            }
            if (
                $sib instanceof Op\Expr\ArrayDimFetch
                && $this->isArrayDimFetchOnlyIssetVar($sib, $cfgChildren[$j + 1] ?? null)
            ) {
                continue;
            }
            if ($sib instanceof Op\Expr\Isset_ || $sib instanceof Op\Expr\Empty_) {
                continue;
            }
            if (!$this->isSiblingInlineCallProducerExpr($sib)) {
                return false;
            }
        }
        $outer = $this->outerSiblingInlineFuncCallProducers($firstSibling, $consumerIndex, $cfgChildren);
        $hoistedArgCount = 0;
        foreach ($consumer->args as $hoistedArgIndex => $consumerArg) {
            if (null !== $consumerArg && !$this->isEmbeddedCallLiteralArg($consumerArg)) {
                if ($this->isByRefNamedCallArgExcludedFromSiblingProducerWiring($consumer, (int) $hoistedArgIndex, $consumerArg)) {
                    continue;
                }
                ++$hoistedArgCount;
            }
        }
        if (
            \count($outer) === $hoistedArgCount
            && \count($outer) < $consumerIndex - $firstSibling
        ) {
            $outerOrdinal = $this->outerSiblingInlineFuncCallProducerOrdinal(
                $producer,
                $firstSibling,
                $consumerIndex,
                $cfgChildren
            );
            if (null === $outerOrdinal) {
                // array_intersect(f(g()), f(g())) — inner g() is not an outer arg producer (#15488, #16031).
                return false;
            }
            $ordinal = $outerOrdinal;
        } else {
            $ordinal = $this->siblingFuncCallChainHasArrayPrelude($firstSibling, $consumerIndex, $cfgChildren)
                ? $this->siblingInlineFuncCallProducerOrdinal($producerIndex, $firstSibling, $cfgChildren)
                : ($producerIndex - $firstSibling);
        }

        return $this->siblingMultiArgProducerOrdinalToConsumerArgIndex(
            $consumer,
            $ordinal,
            $cfgChildren,
            $firstSibling,
            $consumerIndex
        ) === $targetArgIndex;
    }

    /**
     * Map hoisted sibling producer ordinal to consumer arg index (array_udiff* trailing comparator).
     *
     * @param list<Op> $cfgChildren
     */
    private function siblingMultiArgProducerOrdinalToConsumerArgIndex(
        Op $consumer,
        int $ordinal,
        array $cfgChildren,
        int $firstSibling,
        int $consumerIndex
    ): int {
        $consumerName = $this->resolveCfgFuncCallName($consumer);
        if ($this->builtinUsesTrailingComparatorCallback($consumerName) && property_exists($consumer, 'args') && \is_array($consumer->args)) {
            $callbackArgIndex = \count($consumer->args) - 1;
            $funcArgIndex = 0;
            foreach ($consumer->args as $i => $callArg) {
                if ($i >= $callbackArgIndex) {
                    break;
                }
                if (
                    $this->isEmbeddedCallLiteralArg($callArg)
                    || $this->callArgIsDeadInlineTemporary($callArg)
                ) {
                    if ($funcArgIndex === $ordinal) {
                        return $i;
                    }
                    ++$funcArgIndex;
                }
            }
        }
        $leadingEmbedded = 0;
        if (property_exists($consumer, 'args') && \is_array($consumer->args)) {
            foreach ($consumer->args as $arg) {
                if ($this->isEmbeddedCallLiteralArg($arg)) {
                    ++$leadingEmbedded;
                    continue;
                }
                break;
            }
        }

        return $leadingEmbedded + $ordinal;
    }

    /**
     * @param list<Op> $cfgChildren
     */
    private function siblingMultiArgFuncCallProducerTargetArgIndex(
        int $producerIndex,
        int $consumerIndex,
        array $cfgChildren
    ): ?int {
        $distance = $consumerIndex - $producerIndex;
        if ($distance < 1) {
            return null;
        }
        $consumer = $cfgChildren[$consumerIndex] ?? null;
        if (
            1 === $distance
            && ($consumer instanceof Op\Expr\FuncCall || $consumer instanceof Op\Expr\NsFuncCall)
            && property_exists($consumer, 'args')
            && \is_array($consumer->args)
        ) {
            $leadingEmbedded = 0;
            foreach ($consumer->args as $arg) {
                if ($this->isEmbeddedCallLiteralArg($arg)) {
                    ++$leadingEmbedded;
                    continue;
                }
                break;
            }
            // probe('label', g()) / probe('label', in_array(...)) — adjacent hoisted callee (#15846, #16013).
            if ($producerIndex === $consumerIndex - 1) {
                $consumerName = $this->resolveCfgFuncCallName($consumer);
                $firstSiblingAdjacent = $this->firstSiblingInlineFuncCallProducerIndex($consumerIndex, $cfgChildren);
                $useAdjacentArgZero = true;
                if (null !== $firstSiblingAdjacent) {
                    $adjacentProducer = $cfgChildren[$producerIndex] ?? null;
                    if ($adjacentProducer instanceof Op\Expr) {
                        $outerOrdinalAdjacent = $this->outerSiblingInlineFuncCallProducerOrdinal(
                            $adjacentProducer,
                            $firstSiblingAdjacent,
                            $consumerIndex,
                            $cfgChildren
                        );
                        if (null !== $outerOrdinalAdjacent && $outerOrdinalAdjacent > 0) {
                            // array_intersect(f(g()), f(g())) — trailing outer producer is not arg #0 (#15488).
                            $useAdjacentArgZero = false;
                        }
                    }
                }
                if (
                    $useAdjacentArgZero
                    && (
                        !$this->builtinUsesTrailingComparatorCallback($consumerName)
                        || null === $firstSiblingAdjacent
                        || $producerIndex <= $firstSiblingAdjacent
                    )
                    && (
                        null === $firstSiblingAdjacent
                        || $producerIndex === $firstSiblingAdjacent
                        || $this->countSiblingInlineFuncCallProducers(
                            $firstSiblingAdjacent,
                            $consumerIndex,
                            $cfgChildren
                        ) < 2
                    )
                ) {
                    return $leadingEmbedded;
                }
            }
            $firstSibling = $this->firstSiblingInlineFuncCallProducerIndex($consumerIndex, $cfgChildren);
            if (null === $firstSibling || $producerIndex === $firstSibling) {
                // probe('label', g()) — sole adjacent hoisted producer feeds first hoisted arg (#15846).
                $consumerName = $this->resolveCfgFuncCallName($consumer);
                if (
                    1 === $distance
                    && \in_array($consumerName, ['array_merge', 'array_merge_recursive', 'array_replace', 'array_replace_recursive'], true)
                    && 2 === \count($consumer->args)
                ) {
                    for ($i = $producerIndex - 1; $i >= 0; --$i) {
                        $prev = $cfgChildren[$i] ?? null;
                        if ($prev instanceof Op\Expr\Array_) {
                            return 1;
                        }
                        if ($prev instanceof Op\Expr\FuncCall || $prev instanceof Op\Expr\NsFuncCall) {
                            break;
                        }
                        if (
                            !$this->isUnaryInlineSiblingCallArgExpr($prev)
                            && !($prev instanceof Op\Expr\ConstFetch)
                            && !($prev instanceof Op\Expr\ClassConstFetch)
                        ) {
                            break;
                        }
                    }
                }

                return $leadingEmbedded;
            }
        }
        $mid = $cfgChildren[$producerIndex + 1] ?? null;
        if (2 === $distance && $this->isUnaryInlineSiblingCallArgExpr($mid)) {
            return 0;
        }
        // tempnam(sys_get_temp_dir(), E::A) — FuncCall arg #0, ClassConstFetch prelude (#10303, #9321).
        // in_array('x', g(), true) — embedded needle + FuncCall haystack + ConstFetch strict (#15612, #16013).
        if (
            2 === $distance
            && ($mid instanceof Op\Expr\ClassConstFetch || $mid instanceof Op\Expr\ConstFetch)
        ) {
            $producer = $cfgChildren[$producerIndex] ?? null;
            $priorSibling = $cfgChildren[$producerIndex - 1] ?? null;
            if (
                !($priorSibling instanceof Op\Expr\FuncCall || $priorSibling instanceof Op\Expr\NsFuncCall)
                || !$this->isSiblingInlineCallProducerExpr($priorSibling)
            ) {
                if (
                    ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall
                        || $producer instanceof Op\Expr\MethodCall || $producer instanceof Op\Expr\StaticCall)
                    && ($consumer instanceof Op\Expr\FuncCall || $consumer instanceof Op\Expr\NsFuncCall)
                    && property_exists($consumer, 'args')
                    && \is_array($consumer->args)
                ) {
                    $leadingEmbedded = 0;
                    foreach ($consumer->args as $arg) {
                        if ($this->isEmbeddedCallLiteralArg($arg)) {
                            ++$leadingEmbedded;
                            continue;
                        }
                        break;
                    }
                    if ($leadingEmbedded > 0) {
                        return $leadingEmbedded;
                    }
                }

                return 0;
            }
            // in_array(get_class(), get_declared_classes(), true) — use ordinal path, not haystack shortcut (#17882).
        }
        if ($this->firstSiblingInlineFuncCallProducerIndexActive) {
            // Reentrant siblingMultiArg during firstSibling scan — must not recurse into impl (#16012).
            $firstSiblingWhileActive = $this->scanFirstSiblingInlineFuncCallProducerIndex($consumerIndex, $cfgChildren);
            if (null !== $firstSiblingWhileActive && $producerIndex >= $firstSiblingWhileActive && $producerIndex < $consumerIndex) {
                $ordinalWhileActive = $this->siblingFuncCallChainHasArrayPrelude(
                    $firstSiblingWhileActive,
                    $consumerIndex,
                    $cfgChildren
                )
                    ? $this->siblingInlineFuncCallProducerOrdinal(
                        $producerIndex,
                        $firstSiblingWhileActive,
                        $cfgChildren
                    )
                    : ($producerIndex - $firstSiblingWhileActive);
                $consumerNameWhileActive = $this->resolveCfgFuncCallName($consumer);
                if ($this->builtinUsesTrailingComparatorCallback($consumerNameWhileActive)) {
                    $callbackArgIndex = \count($consumer->args) - 1;
                    $funcArgIndex = 0;
                    foreach ($consumer->args as $i => $callArg) {
                        if ($i >= $callbackArgIndex) {
                            break;
                        }
                        if (
                            $this->isEmbeddedCallLiteralArg($callArg)
                            || $this->callArgIsDeadInlineTemporary($callArg)
                        ) {
                            if ($funcArgIndex === $ordinalWhileActive) {
                                return $i;
                            }
                            ++$funcArgIndex;
                        }
                    }
                }
                if (
                    ($consumer instanceof Op\Expr\FuncCall || $consumer instanceof Op\Expr\NsFuncCall)
                    && property_exists($consumer, 'args')
                    && \is_array($consumer->args)
                ) {
                    $leadingEmbeddedWhileActive = 0;
                    foreach ($consumer->args as $arg) {
                        if ($this->isEmbeddedCallLiteralArg($arg)) {
                            ++$leadingEmbeddedWhileActive;
                            continue;
                        }
                        break;
                    }

                    return $leadingEmbeddedWhileActive + $ordinalWhileActive;
                }
            }

            $arrayLiteralTarget = $this->soleFuncCallBeforeArrayLiteralCallArgTargetIndex(
                $producerIndex,
                $consumerIndex,
                $cfgChildren,
                $consumer
            );
            if (null !== $arrayLiteralTarget) {
                // show(strtoupper(...), ['k'=>false]) — ConstFetch+Array_ must not yield distance-1 (#26367).
                return $arrayLiteralTarget;
            }

            return $distance - 1;
        }
        $firstSibling = $this->firstSiblingInlineFuncCallProducerIndex($consumerIndex, $cfgChildren);
        if (null === $firstSibling) {
            $arrayLiteralTarget = $this->soleFuncCallBeforeArrayLiteralCallArgTargetIndex(
                $producerIndex,
                $consumerIndex,
                $cfgChildren,
                $consumer
            );
            if (null !== $arrayLiteralTarget) {
                return $arrayLiteralTarget;
            }

            return $distance - 1;
        }
        if ($producerIndex < $firstSibling || $producerIndex >= $consumerIndex) {
            return null;
        }

        $ordinal = $this->siblingInlineFuncCallProducerOrdinal(
            $producerIndex,
            $firstSibling,
            $cfgChildren
        );
        if ($ordinal < 0) {
            return null;
        }
        $producer = $cfgChildren[$producerIndex] ?? null;
        if ($producer instanceof Op\Expr) {
            $outerOrdinal = $this->outerSiblingInlineFuncCallProducerOrdinal(
                $producer,
                $firstSibling,
                $consumerIndex,
                $cfgChildren
            );
            if (null !== $outerOrdinal) {
                $outer = $this->outerSiblingInlineFuncCallProducers($firstSibling, $consumerIndex, $cfgChildren);
                $hoistedArgCount = 0;
                if (
                    ($consumer instanceof Op\Expr\FuncCall || $consumer instanceof Op\Expr\NsFuncCall)
                    && property_exists($consumer, 'args')
                    && \is_array($consumer->args)
                ) {
                    foreach ($consumer->args as $hoistedArgIndex => $callArg) {
                        if (null !== $callArg && !$this->isEmbeddedCallLiteralArg($callArg)) {
                            if ($this->isByRefNamedCallArgExcludedFromSiblingProducerWiring($consumer, (int) $hoistedArgIndex, $callArg)) {
                                continue;
                            }
                            ++$hoistedArgCount;
                        }
                    }
                }
                if (\count($outer) === $hoistedArgCount && \count($outer) < $consumerIndex - $firstSibling) {
                    $ordinal = $outerOrdinal;
                }
            }
        }
        if (
            ($consumer instanceof Op\Expr\FuncCall || $consumer instanceof Op\Expr\NsFuncCall)
            && property_exists($consumer, 'args')
            && is_array($consumer->args)
        ) {
            $consumerName = $this->resolveCfgFuncCallName($consumer);
            if ($this->builtinUsesTrailingComparatorCallback($consumerName)) {
                $callbackArgIndex = \count($consumer->args) - 1;
                $funcArgIndex = 0;
                foreach ($consumer->args as $i => $callArg) {
                    if ($i >= $callbackArgIndex) {
                        break;
                    }
                    if (
                        $this->isEmbeddedCallLiteralArg($callArg)
                        || $this->callArgIsDeadInlineTemporary($callArg)
                    ) {
                        if ($funcArgIndex === $ordinal) {
                            return $i;
                        }
                        ++$funcArgIndex;
                    }
                }
            }
            $leadingEmbedded = 0;
            foreach ($consumer->args as $arg) {
                if ($this->isEmbeddedCallLiteralArg($arg)) {
                    ++$leadingEmbedded;
                    continue;
                }
                break;
            }

            return $leadingEmbedded + $ordinal;
        }

        // MethodCall ChildNode: replaceWith($el, 'txt', $el2) — remap producer ordinal onto
        // non-embedded dead-temp args only when an embedded literal is present (#21901).
        // Scoped to ChildNode mutators so insertBefore($new, $list->item(1)) ordinal legacy is unchanged.
        if (
            (
                $consumer instanceof Op\Expr\MethodCall
                || $consumer instanceof Op\Expr\NullsafeMethodCall
            )
            && property_exists($consumer, 'args')
            && is_array($consumer->args)
        ) {
            $consumerMethod = strtolower((string) ($this->staticNameFromOperand($consumer->name) ?? ''));
            if (
                \in_array($consumerMethod, ['replacewith', 'before', 'after', 'append', 'prepend'], true)
            ) {
                $nonEmbeddedDeadIndices = [];
                $hasEmbeddedLiteral = false;
                foreach ($consumer->args as $i => $callArg) {
                    if ($this->isEmbeddedCallLiteralArg($callArg)) {
                        $hasEmbeddedLiteral = true;
                        continue;
                    }
                    if (
                        $callArg instanceof Operand
                        && $this->callArgIsDeadInlineTemporary($callArg)
                    ) {
                        $nonEmbeddedDeadIndices[] = (int) $i;
                    }
                }
                if ($hasEmbeddedLiteral && isset($nonEmbeddedDeadIndices[$ordinal])) {
                    return $nonEmbeddedDeadIndices[$ordinal];
                }
            }
        }

        return $ordinal;
    }

    /**
     * chmod($path, …); substr(sprintf('%o', fileperms($path)), -N) — named-arg stmt call is not a hoisted producer (#16451, #16480).
     *
     * @param list<Op> $cfgChildren
     */
    private function stmtLevelNamedFuncCallPrecedesNestedInlineCallArgChain(
        int $producerIndex,
        int $consumerIndex,
        array $cfgChildren
    ): bool {
        $producer = $cfgChildren[$producerIndex] ?? null;
        if (!$producer instanceof Op\Expr\FuncCall && !$producer instanceof Op\Expr\NsFuncCall) {
            return false;
        }
        if (!$this->funcCallHasNamedVariableOperand($producer)) {
            return false;
        }
        for ($k = $producerIndex + 1; $k < $consumerIndex - 1; ++$k) {
            $inner = $cfgChildren[$k] ?? null;
            $outer = $cfgChildren[$k + 1] ?? null;
            if (
                $inner instanceof Op\Expr
                && ($outer instanceof Op\Expr\FuncCall || $outer instanceof Op\Expr\NsFuncCall)
                && $this->isAdjacentNestedFuncCallProducer($inner, $outer, $k, $k + 1)
            ) {
                return true;
            }
        }

        return false;
    }

    private function funcCallHasNamedVariableOperand(Op\Expr $call): bool
    {
        if (!property_exists($call, 'args') || !\is_array($call->args)) {
            return false;
        }
        foreach ($call->args as $arg) {
            if ($arg instanceof Operand && $this->isNamedVariableOperand($arg)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Statement-level FuncCall before a hoisted sibling chain feeding a multi-arg consumer (#13912, #13916).
     *
     * php-cfg `next($a); var_export(next($a), true)` hoists only the inner call — do not fold the
     * statement-level callee into {@see firstSiblingInlineFuncCallProducerIndex()}.
     *
     * Also true when only Array_/UnaryMinus preludes sit between a prior UDF call and the consumer
     * (`hold([]); array_pad([...], -N, 0)` — #15421).
     *
     * @param list<Op> $cfgChildren
     */
    private function producerIsHoistedMultiArgSiblingChainStart(
        int $producerIndex,
        int $consumerIndex,
        array $cfgChildren
    ): bool {
        $consumer = $cfgChildren[$consumerIndex] ?? null;
        if (
            !$consumer instanceof Op\Expr
            || !$this->isSiblingMultiArgInlineCallConsumer($consumer)
            || !\is_array($consumer->args ?? null)
            || \count($consumer->args) < 2
        ) {
            return false;
        }
        $deadArgs = [];
        foreach ($consumer->args as $arg) {
            if ($this->callArgIsDeadInlineTemporary($arg)) {
                $deadArgs[] = $arg;
            }
        }
        // sprintf("…", count($a), $sum / count($a)) — leading format literal is not a dead temp;
        // compare against non-embedded arg count (#36353).
        $hoistedArgCount = 0;
        foreach ($consumer->args as $arg) {
            if (null !== $arg && !$this->isEmbeddedCallLiteralArg($arg)) {
                ++$hoistedArgCount;
            }
        }
        if (\count($deadArgs) < 2 || \count($deadArgs) !== $hoistedArgCount) {
            return false;
        }
        if (!$this->callArgsAreDistinctInlineTemporaries($deadArgs)) {
            return false;
        }
        $funcProducerCount = 0;
        for ($j = $producerIndex; $j < $consumerIndex; ++$j) {
            $mid = $cfgChildren[$j] ?? null;
            if (!($mid instanceof Op\Expr\FuncCall || $mid instanceof Op\Expr\NsFuncCall)) {
                continue;
            }
            // Prior show(strtoupper(...), [...]) statements are not value producers in this chain (#26367).
            if (
                \is_array($mid->args ?? null)
                && \count($mid->args) >= 2
                && $this->deadInlineTemporaryArgCount($mid) >= 2
                && (
                    null === ($cfgChildren[$consumerIndex] ?? null)
                    || !$this->inlineCallArgProducerFeedsConsumer($mid, $cfgChildren[$consumerIndex])
                )
            ) {
                continue;
            }
            ++$funcProducerCount;
        }

        return $funcProducerCount >= 2;
    }

    private function statementLevelFuncCallBeforeHoistedSiblingChain(
        int $producerIndex,
        int $consumerIndex,
        array $cfgChildren
    ): bool {
        if ($producerIndex >= $consumerIndex - 1) {
            return false;
        }
        $onlySkippablePreludes = true;
        for ($j = $producerIndex + 1; $j < $consumerIndex; ++$j) {
            $mid = $cfgChildren[$j] ?? null;
            if ($mid instanceof Op\Expr\ConstFetch || $mid instanceof Op\Expr\ClassConstFetch) {
                continue;
            }
            if ($mid instanceof Op\Expr\Array_) {
                continue;
            }
            if ($this->isUnaryInlineSiblingCallArgExpr($mid)) {
                continue;
            }
            if ($mid instanceof Op\Expr\ArrowFunction
                || $mid instanceof Op\Expr\Closure
                || $mid instanceof Op\Expr\FirstClassCallable) {
                continue;
            }
            if ($mid instanceof Op\Expr\New_ || $mid instanceof Op\Expr\Clone_) {
                continue;
            }
            // take($r->lastChild, $d->createElement('y')) after prior take(...) — PropertyFetch
            // between statement call and next call is a hoisted arg, not a chain break (#19719).
            if (
                $mid instanceof Op\Expr\PropertyFetch
                || $mid instanceof Op\Expr\NullsafePropertyFetch
                || $mid instanceof Op\Expr\StaticPropertyFetch
            ) {
                continue;
            }
            if ($this->isSiblingInlineCallProducerExpr($mid)) {
                if ($this->producerIsHoistedMultiArgSiblingChainStart($producerIndex, $consumerIndex, $cfgChildren)) {
                    return false;
                }
                // $e->getAttributeNode()->isId() before var_export(..., true) — receiver MethodCall
                // feeds the leaf; not a completed stmt ahead of the hoisted chain (#25841).
                $producer = $cfgChildren[$producerIndex] ?? null;
                if (
                    $producer instanceof Op\Expr\MethodCall
                    && $mid instanceof Op\Expr\MethodCall
                    && null !== $producer->result
                    && property_exists($mid, 'var')
                    && null !== $mid->var
                    && $this->operandsReferToSameVariable($producer->result, $mid->var)
                ) {
                    return false;
                }

                return true;
            }
            $onlySkippablePreludes = false;
            break;
        }
        if (!$onlySkippablePreludes) {
            return false;
        }
        $producer = $cfgChildren[$producerIndex] ?? null;
        $consumer = $cfgChildren[$consumerIndex] ?? null;
        if (
            ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall)
            && $consumer instanceof Op\Expr
            && $this->producerFeedsConsumerArg0ThroughLiteralPreludesOnly(
                $producer,
                $consumer,
                $producerIndex,
                $consumerIndex,
                $cfgChildren
            )
        ) {
            // json_decode(g(), true, 512, JSON_THROW_ON_ERROR) — arg0 producer, not a stmt-level callee (#12009, #15441).
            return false;
        }
        if ($onlySkippablePreludes) {
            for ($j = $producerIndex + 1; $j < $consumerIndex; ++$j) {
                $mid = $cfgChildren[$j] ?? null;
                if ($this->isSiblingInlineCallProducerExpr($mid)) {
                    if ($this->producerIsHoistedMultiArgSiblingChainStart($producerIndex, $consumerIndex, $cfgChildren)) {
                        return false;
                    }

                    return $producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall;
                }
            }

            // array_combine(array_keys(...), [...]) — trailing values Array_ means producer feeds arg #0 (#15949, re-#15857).
            if (
                ($consumer instanceof Op\Expr\FuncCall || $consumer instanceof Op\Expr\NsFuncCall)
                && 'array_combine' === $this->resolveCfgFuncCallName($consumer)
            ) {
                for ($j = $producerIndex + 1; $j < $consumerIndex; ++$j) {
                    if (($cfgChildren[$j] ?? null) instanceof Op\Expr\Array_) {
                        return false;
                    }
                }
            }

            // var_dump(...); ini_get_all(null, false) — completed stmt callee, ConstFetch preludes only (#15931).
            // date_sun_info(strtotime(...), lat, -lon) still handled via producerFeedsConsumerArg0ThroughLiteralPreludesOnly above.
            // substr(sprintf(...), -N) — hoisted FuncCall arg #0 + UnaryMinus arg #1, not stmt-level (#10673, #16000).
            if (
                ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall)
                && ($consumer instanceof Op\Expr\FuncCall || $consumer instanceof Op\Expr\NsFuncCall)
                && \is_array($consumer->args ?? null)
                && \count($consumer->args) >= 2
                && $this->callArgIsDeadInlineTemporary($consumer->args[0] ?? null)
                && $this->callArgIsDeadInlineTemporary($consumer->args[1] ?? null)
            ) {
                $targetArgIndex = $this->siblingMultiArgFuncCallProducerTargetArgIndex(
                    $producerIndex,
                    $consumerIndex,
                    $cfgChildren
                );
                if (0 === $targetArgIndex) {
                    $onlyUnaryPreludes = true;
                    for ($j = $producerIndex + 1; $j < $consumerIndex; ++$j) {
                        $mid = $cfgChildren[$j] ?? null;
                        if ($this->isUnaryInlineSiblingCallArgExpr($mid)) {
                            continue;
                        }
                        if ($mid instanceof Op\Expr\ConstFetch || $mid instanceof Op\Expr\ClassConstFetch) {
                            continue;
                        }
                        if ($mid instanceof Op\Expr\Array_) {
                            continue;
                        }
                        $onlyUnaryPreludes = false;
                        break;
                    }
                    if ($onlyUnaryPreludes) {
                        $hasArrayPreludeBetween = false;
                        for ($j = $producerIndex + 1; $j < $consumerIndex; ++$j) {
                            if (($cfgChildren[$j] ?? null) instanceof Op\Expr\Array_) {
                                $hasArrayPreludeBetween = true;
                                break;
                            }
                        }
                        if (!$hasArrayPreludeBetween) {
                            // substr(sprintf(...), -N) — UnaryMinus only, producer feeds arg #0 (#10673, #16000).
                            return false;
                        }
                        // hold([]); array_pad([...], -N, 0) — Array_ + UnaryMinus are consumer args (#15421, #16066).
                        if (
                            $producer instanceof Op\Expr
                            && (
                                $this->inlineCallArgProducerFeedsConsumer($producer, $consumer)
                                || $this->isNestedCallArgProducerForConsumer(
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
                    }
                }
            }

            if (
                $this->isNestedCallArgProducerSeparatedByConsumerLiteralPreludes(
                    $producer,
                    $consumer,
                    $producerIndex,
                    $consumerIndex,
                    $cfgChildren
                )
            ) {
                return false;
            }

            return $producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall;
        }

        return $producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall;
    }

    /**
     * Hoisted FuncCall arg0 producer with only ConstFetch preludes before the consumer (#12009, #15441).
     *
     * @param list<Op> $cfgChildren
     */
    private function producerFeedsConsumerArg0ThroughLiteralPreludesOnly(
        Op\Expr $producer,
        Op $consumer,
        int $producerIndex,
        int $consumerIndex,
        array $cfgChildren
    ): bool {
        if ($producerIndex >= $consumerIndex - 1) {
            return false;
        }
        for ($j = $producerIndex + 1; $j < $consumerIndex; ++$j) {
            $mid = $cfgChildren[$j] ?? null;
            if ($mid instanceof Op\Expr\ConstFetch || $mid instanceof Op\Expr\ClassConstFetch) {
                continue;
            }
            if ($this->isUnaryInlineSiblingCallArgExpr($mid)) {
                continue;
            }

            return false;
        }
        if (!property_exists($consumer, 'args') || !\is_array($consumer->args) || [] === $consumer->args) {
            return false;
        }
        $arg0 = $consumer->args[0] ?? null;
        if (!$this->callArgIsDeadInlineTemporary($arg0)) {
            return false;
        }
        $hasEmbeddedMiddle = false;
        foreach ($consumer->args as $argIndex => $callArg) {
            if (0 === $argIndex) {
                continue;
            }
            if ($this->isEmbeddedCallLiteralArg($callArg)) {
                $hasEmbeddedMiddle = true;
                break;
            }
        }
        if (!$hasEmbeddedMiddle) {
            return false;
        }
        $literalPreludeCount = $consumerIndex - $producerIndex - 1;
        $hoistedArgCount = 0;
        foreach ($consumer->args as $callArg) {
            if (null !== $callArg && !$this->isEmbeddedCallLiteralArg($callArg)) {
                ++$hoistedArgCount;
            }
        }

        return $literalPreludeCount === max(0, $hoistedArgCount - 1);
    }

    /**
     * Contiguous FuncCall stmts from {@see $fromIndex} feeding a distinct dead-temp consumer (#13969).
     *
     * @param list<Op> $cfgChildren
     */
    private function hasContiguousHoistedFuncCallProducersFrom(
        int $fromIndex,
        int $consumerIndex,
        array $cfgChildren
    ): bool {
        $consumer = $cfgChildren[$consumerIndex] ?? null;
        if (
            (null === $consumer || !$this->isSiblingMultiArgInlineCallConsumer($consumer))
            || !property_exists($consumer, 'args')
            || !is_array($consumer->args)
            || \count($consumer->args) < 2
        ) {
            return false;
        }
        $hoistedArgs = [];
        foreach ($consumer->args as $argIndex => $callArg) {
            if (null === $callArg || $this->isEmbeddedCallLiteralArg($callArg)) {
                continue;
            }
            if ($this->isByRefNamedCallArgExcludedFromSiblingProducerWiring($consumer, (int) $argIndex, $callArg)) {
                continue;
            }
            $hoistedArgs[] = $callArg;
        }
        if (\count($hoistedArgs) < 2 || !$this->callArgsAreDistinctInlineTemporaries($hoistedArgs)) {
            return false;
        }
        for ($k = $fromIndex; $k < $consumerIndex; ++$k) {
            $stmt = $cfgChildren[$k] ?? null;
            if (
                ($stmt instanceof Op\Expr\FuncCall || $stmt instanceof Op\Expr\NsFuncCall)
                && $this->siblingInlineFuncCallSkipsExecReturnOrdinal($stmt, $k, $cfgChildren)
            ) {
                // var_dump(in_array(...)) between stmt chains — not one multi-arg hoisted consumer (#9390, #17317).
                return false;
            }
        }
        // chmod(); substr(sprintf('%o', fileperms($path)), -N) — stmt-level callee is not chain start (#16451).
        if (
            $this->statementLevelFuncCallBeforeHoistedSiblingChain($fromIndex, $consumerIndex, $cfgChildren)
        ) {
            $contiguousFirst = $this->firstContiguousSiblingMultiArgProducerIndex(
                $consumerIndex,
                $consumer,
                $cfgChildren
            );
            if (null === $contiguousFirst || $fromIndex !== $contiguousFirst) {
                return false;
            }
        }
        $outerProducerCount = \count(
            $this->outerSiblingInlineFuncCallProducers($fromIndex, $consumerIndex, $cfgChildren)
        );
        // array_intersect(f(g()), f(g())) — outer f() producers match hoisted arg temps (#15488, #16050, #16427).
        // in_array/array_search before var_dump — full scan for EXEC_RETURN ordinals (#9390, #17317).
        if ($outerProducerCount >= 2 && $outerProducerCount === \count($hoistedArgs)) {
            $consumerName = strtolower($this->resolveInlineCallArgFuncName($consumer) ?? '');
            if (!\in_array($consumerName, [
                'in_array',
                'array_search',
                'array_key_exists',
                'key_exists',
            ], true)) {
                return true;
            }
        }
        $arrayPreludeChain = $this->siblingFuncCallChainHasArrayPrelude(
            $fromIndex,
            $consumerIndex,
            $cfgChildren
        );
        $producerFuncCalls = 0;
        for ($k = $fromIndex; $k < $consumerIndex; ++$k) {
            $stmt = $cfgChildren[$k] ?? null;
            if ($stmt instanceof Op\Expr\ConstFetch || $stmt instanceof Op\Expr\ClassConstFetch) {
                continue;
            }
            if ($stmt instanceof Op\Expr\Array_) {
                continue;
            }
            if ($this->isUnaryInlineSiblingCallArgExpr($stmt)) {
                continue;
            }
            // sprintf(..., count($a), $sum / count($a)) — Div between sibling counts (#36353).
            if ($stmt instanceof Op\Expr\BinaryOp) {
                continue;
            }
            if ($stmt instanceof Op\Expr\ArrowFunction
                || $stmt instanceof Op\Expr\Closure
                || $stmt instanceof Op\Expr\FirstClassCallable) {
                continue;
            }
            // var_dump(is_countable(null), is_countable(new ArrayObject())) — New_ between producers (#14958).
            if ($stmt instanceof Op\Expr\New_ || $stmt instanceof Op\Expr\Clone_) {
                continue;
            }
            if ($stmt instanceof Op\Expr\Assign || $stmt instanceof Op\Expr\AssignRef) {
                // $expected = [...] before array_udiff(array_keys(...), …) — not part of hoisted chain (#15475).
                continue;
            }
            if ($this->isSiblingInlineCallProducerExpr($stmt)) {
                // array_intersect_assoc(array_keys([...]), array_keys([...])) — literal callees with
                // hoisted Array_ args (#13778, #13954). var_dump(acosh(1.5), …) / str_repeat('a', $n) (#14119, #10917).
                if (
                    !$this->funcCallExprUsesVariableCallee($stmt)
                    && !$arrayPreludeChain
                    && !$this->funcCallExprLiteralCalleeAllowedAsHoistedProducer($stmt)
                ) {
                    return false;
                }
                ++$producerFuncCalls;
                continue;
            }

            return false;
        }

        $hoistedArgCount = \count($hoistedArgs);
        $consumerName = $this->resolveInlineCallArgFuncName($consumer);
        if ($this->builtinUsesTrailingComparatorCallback($consumerName) && $hoistedArgCount > 1) {
            $callbackArg = $consumer->args[\count($consumer->args) - 1] ?? null;
            // array_udiff(g(), h(), 'strcmp') — embedded string callback is not a hoisted producer (#14021).
            if (null !== $callbackArg && !$this->isEmbeddedCallLiteralArg($callbackArg)) {
                --$hoistedArgCount;
            }
        }
        $lastProducerIndex = -1;
        for ($k = $fromIndex; $k < $consumerIndex; ++$k) {
            $stmt = $cfgChildren[$k] ?? null;
            if ($this->isSiblingInlineCallProducerExpr($stmt)) {
                $lastProducerIndex = $k;
            }
        }
        if ($lastProducerIndex >= 0) {
            for ($k = $lastProducerIndex + 1; $k < $consumerIndex; ++$k) {
                $mid = $cfgChildren[$k] ?? null;
                if ($mid instanceof Op\Expr\ConstFetch || $mid instanceof Op\Expr\ClassConstFetch) {
                    --$hoistedArgCount;
                }
            }
        }

        $outerProducerCount = \count(
            $this->outerSiblingInlineFuncCallProducers($fromIndex, $consumerIndex, $cfgChildren)
        );
        if ($outerProducerCount >= 2 && $outerProducerCount === $hoistedArgCount) {
            return true;
        }

        return $producerFuncCalls >= 2 && $producerFuncCalls === $hoistedArgCount;
    }

    /** True when FuncCall callee is a variable/closure slot, not a literal name (#13969). */
    private function funcCallExprUsesVariableCallee(Op\Expr $expr): bool
    {
        if ($expr instanceof Op\Expr\FuncCall || $expr instanceof Op\Expr\NsFuncCall) {
            return !($expr->name instanceof Operand\Literal);
        }
        if ($expr instanceof Op\Expr\MethodCall || $expr instanceof Op\Expr\StaticCall) {
            return true;
        }

        return false;
    }

    /**
     * True when every FuncCall argument is an embedded php-cfg literal (acosh(1.5), str_repeat('a', 1)).
     */
    private function funcCallExprHasOnlyEmbeddedLiteralArgs(Op\Expr $expr): bool
    {
        if (!$expr instanceof Op\Expr\FuncCall && !$expr instanceof Op\Expr\NsFuncCall) {
            return false;
        }
        if (!property_exists($expr, 'args') || !is_array($expr->args)) {
            return true;
        }
        foreach ($expr->args as $arg) {
            if (!$arg instanceof Operand\Literal) {
                return false;
            }
        }

        return true;
    }

    /**
     * Literal-name callee allowed in a hoisted sibling producer chain (#14119, #10917).
     *
     * True for acosh(1.5) (embedded literals) and str_repeat('a', $n) (named variable temps).
     * False for array_keys($hoistedArrayTemp) where args are dead temps without a Variable root (#13778).
     */
    private function funcCallExprLiteralCalleeAllowedAsHoistedProducer(Op\Expr $expr): bool
    {
        if ($this->funcCallExprHasOnlyEmbeddedLiteralArgs($expr)) {
            return true;
        }
        if (!$expr instanceof Op\Expr\FuncCall && !$expr instanceof Op\Expr\NsFuncCall) {
            return false;
        }
        if (!property_exists($expr, 'args') || !is_array($expr->args)) {
            return true;
        }
        foreach ($expr->args as $arg) {
            if ($arg instanceof Operand\Literal) {
                continue;
            }
            if ($arg instanceof Operand\Variable) {
                continue;
            }
            if ($arg instanceof Operand\Temporary && $arg->original instanceof Operand\Variable) {
                continue;
            }

            return false;
        }

        return true;
    }

    /**
     * Non-recursive first-sibling scan for reentrant siblingMultiArg wiring (#16012).
     *
     * @param list<Op> $cfgChildren
     */
    private function scanFirstSiblingInlineFuncCallProducerIndex(int $consumerIndex, array $cfgChildren): ?int
    {
        $i = $consumerIndex - 1;
        while ($i >= 0) {
            $child = $cfgChildren[$i] ?? null;
            if ($this->isSiblingInlineCallProducerExpr($child)) {
                return $i;
            }
            if ($child instanceof Op\Expr\ConstFetch || $child instanceof Op\Expr\ClassConstFetch) {
                --$i;
                continue;
            }
            if ($child instanceof Op\Expr\Array_) {
                --$i;
                continue;
            }
            if ($this->isUnaryInlineSiblingCallArgExpr($child)) {
                --$i;
                continue;
            }
            // sprintf(..., count($a), $sum / count($a)) — Div immediately before the consumer
            // must not hide earlier count() sibling producers (#36353).
            if ($child instanceof Op\Expr\BinaryOp) {
                --$i;
                continue;
            }
            if ($child instanceof Op\Expr\ArrowFunction
                || $child instanceof Op\Expr\Closure
                || $child instanceof Op\Expr\FirstClassCallable) {
                --$i;
                continue;
            }
            if ($child instanceof Op\Expr\New_ || $child instanceof Op\Expr\Clone_) {
                --$i;
                continue;
            }
            if ($child instanceof Op\Expr\Isset_ || $child instanceof Op\Expr\Empty_) {
                --$i;
                continue;
            }
            if (
                $child instanceof Op\Expr\PropertyFetch
                && $this->isPropertyFetchOnlyIssetVar($child, $cfgChildren[$i + 1] ?? null)
            ) {
                --$i;
                continue;
            }
            // var_dump($m(), $a[$k]) — ArrayDimFetch is a sibling call arg (#28821).
            if ($child instanceof Op\Expr\ArrayDimFetch) {
                --$i;
                continue;
            }
            if (
                $child instanceof Op\Expr\StaticPropertyFetch
                && $this->isStaticPropertyFetchOnlyIssetVar($child, $cfgChildren[$i + 1] ?? null)
            ) {
                --$i;
                continue;
            }
            if ($child instanceof Op\Expr\Assign || $child instanceof Op\Expr\AssignRef) {
                break;
            }
            break;
        }

        return null;
    }

    /**
     * Prior FuncCall in the backward sibling scan is a completed statement, not a hoisted
     * arg producer for $consumer — stop scanning (#36224 / #36387 compile scaling).
     *
     * @param list<Op> $cfgChildren
     */
    private function siblingScanStopsAtPriorFuncCall(
        Op $child,
        ?Op $consumer,
        int $producerIndex,
        int $consumerIndex,
        array $cfgChildren
    ): bool {
        if (!($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall)) {
            return false;
        }
        $emptyUsages = $child instanceof Op\Expr
            && null !== $child->result
            && empty($child->result->usages);
        if (!$emptyUsages) {
            return false;
        }
        if (!$consumer instanceof Op\Expr) {
            return true;
        }
        // php-cfg often leaves hoisted nested callees with empty usage lists too. Only the
        // near window (≈ consumer arity) can be a real nested/sibling producer — beyond it,
        // treat empty-usage FuncCalls as completed statements (#36387).
        $maxNear = 4;
        if (property_exists($consumer, 'args') && \is_array($consumer->args)) {
            $maxNear = max(4, \count($consumer->args) + 2);
        }
        if (($consumerIndex - $producerIndex) > $maxNear) {
            return true;
        }
        if ($this->isNestedCallArgProducerForConsumer($child, $consumer, $producerIndex, $consumerIndex, $cfgChildren)) {
            return false;
        }
        if ($this->isNestedCallArgProducerSeparatedByConsumerLiteralPreludes(
            $child,
            $consumer,
            $producerIndex,
            $consumerIndex,
            $cfgChildren
        )) {
            return false;
        }
        if ($this->isSiblingMultiArgFuncCallProducer($child, $consumer, $producerIndex, $consumerIndex, $cfgChildren)) {
            return false;
        }
        if ($this->inlineCallArgProducerFeedsConsumer($child, $consumer)) {
            return false;
        }
        // While firstSibling is Active, isSiblingMultiArgFuncCallProducer returns false
        // (reentrancy guard — #36387). That incorrectly stopped the scan on the leading
        // empty-usage count() in sprintf(..., count($a), $sum / count($a)), so the first
        // count compiled as EXEC_NORETURN and ARG_SEND wired a dead 0 (#36353 regression).
        if (
            $this->firstSiblingInlineFuncCallProducerIndexActive
            && $this->emptyUsageFuncCallIsHoistedSiblingBeforeBinaryOp(
                $producerIndex,
                $consumerIndex,
                $cfgChildren,
                $consumer
            )
        ) {
            return false;
        }

        return true;
    }

    /**
     * Empty-usage FuncCall separated from a multi-arg consumer only by BinaryOp and/or
     * other sibling producers (and scalar preludes) — the #36353 sprintf/count/Div shape.
     *
     * @param list<Op> $cfgChildren
     */
    private function emptyUsageFuncCallIsHoistedSiblingBeforeBinaryOp(
        int $producerIndex,
        int $consumerIndex,
        array $cfgChildren,
        Op $consumer
    ): bool {
        if (
            !($consumer instanceof Op\Expr\FuncCall || $consumer instanceof Op\Expr\NsFuncCall)
            || !property_exists($consumer, 'args')
            || !\is_array($consumer->args)
            || \count($consumer->args) < 2
        ) {
            return false;
        }
        $deadTemps = 0;
        foreach ($consumer->args as $arg) {
            if ($this->callArgIsDeadInlineTemporary($arg)) {
                ++$deadTemps;
            }
        }
        if ($deadTemps < 1) {
            return false;
        }
        $sawBinaryOp = false;
        for ($j = $producerIndex + 1; $j < $consumerIndex; ++$j) {
            $mid = $cfgChildren[$j] ?? null;
            if ($mid instanceof Op\Expr\BinaryOp) {
                $sawBinaryOp = true;
                continue;
            }
            if ($this->isSiblingInlineCallProducerExpr($mid)) {
                continue;
            }
            if ($mid instanceof Op\Expr\ConstFetch || $mid instanceof Op\Expr\ClassConstFetch) {
                continue;
            }
            if ($mid instanceof Op\Expr\Array_) {
                continue;
            }
            if ($this->isUnaryInlineSiblingCallArgExpr($mid)) {
                continue;
            }
            if (
                $mid instanceof Op\Expr\ArrowFunction
                || $mid instanceof Op\Expr\Closure
                || $mid instanceof Op\Expr\FirstClassCallable
            ) {
                continue;
            }
            if ($mid instanceof Op\Expr\New_ || $mid instanceof Op\Expr\Clone_) {
                continue;
            }

            return false;
        }

        return $sawBinaryOp;
    }

    /**
     * First hoisted FuncCall in a sibling inline call-arg chain ending at {@see $consumerIndex}.
     *
     * @param list<Op> $cfgChildren
     */
    private function firstSiblingInlineFuncCallProducerIndex(int $consumerIndex, array $cfgChildren): ?int
    {
        if ($this->firstSiblingInlineFuncCallProducerIndexActive) {
            return null;
        }
        $this->firstSiblingInlineFuncCallProducerIndexActive = true;
        try {
            return $this->firstSiblingInlineFuncCallProducerIndexImpl($consumerIndex, $cfgChildren);
        } finally {
            $this->firstSiblingInlineFuncCallProducerIndexActive = false;
        }
    }

    /**
     * Cache key for {@see firstSiblingInlineFuncCallProducerIndexImpl} (#36387).
     *
     * @param list<Op> $cfgChildren
     */
    private function firstSiblingInlineFuncCallProducerCacheKey(int $consumerIndex, array $cfgChildren): string
    {
        $consumer = $cfgChildren[$consumerIndex] ?? null;
        if ($consumer instanceof Op) {
            return 'o'.spl_object_id($consumer);
        }
        $anchor = $cfgChildren[0] ?? null;
        $anchorId = $anchor instanceof Op ? spl_object_id($anchor) : 0;

        return 'i'.$anchorId.':'.$consumerIndex.':'.\count($cfgChildren);
    }

    /**
     * @param list<Op> $cfgChildren
     */
    private function firstSiblingInlineFuncCallProducerIndexImpl(int $consumerIndex, array $cfgChildren): ?int
    {
        $cacheKey = $this->firstSiblingInlineFuncCallProducerCacheKey($consumerIndex, $cfgChildren);
        if (\array_key_exists($cacheKey, $this->firstSiblingInlineFuncCallProducerCache)) {
            $cached = $this->firstSiblingInlineFuncCallProducerCache[$cacheKey];

            return $cached < 0 ? null : $cached;
        }
        $result = $this->computeFirstSiblingInlineFuncCallProducerIndex($consumerIndex, $cfgChildren);
        $this->firstSiblingInlineFuncCallProducerCache[$cacheKey] = null === $result ? -1 : $result;

        return $result;
    }

    /**
     * @param list<Op> $cfgChildren
     */
    private function computeFirstSiblingInlineFuncCallProducerIndex(int $consumerIndex, array $cfgChildren): ?int
    {
        $deadInlineArgCount = 0;
        $consumer = $cfgChildren[$consumerIndex] ?? null;
        if ($consumer instanceof Op\Expr && \is_array($consumer->args ?? null)) {
            foreach ($consumer->args as $arg) {
                if ($this->callArgIsDeadInlineTemporary($arg)) {
                    ++$deadInlineArgCount;
                }
            }
        }
        $funcProducersSeen = 0;
        $i = $consumerIndex - 1;
        while ($i >= 0) {
            $child = $cfgChildren[$i] ?? null;
            if ($child instanceof Op\Expr\MethodCall) {
                // Skip loadXML-style prior stmts; keep trailing item()/unknown producers (#19719, #21171).
                if ($this->methodCallIsSkippedHoistedSiblingProducer(
                    $child,
                    $i,
                    $consumerIndex,
                    $deadInlineArgCount,
                    $cfgChildren
                )) {
                    --$i;
                    continue;
                }
            }
            if ($this->isSiblingInlineCallProducerExpr($child)) {
                if (
                    ($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall)
                    && $this->siblingScanStopsAtPriorFuncCall($child, $consumer, $i, $consumerIndex, $cfgChildren)
                ) {
                    break;
                }
                if (
                    ($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall)
                    && $this->isStatementLevelSideEffectFuncCall($child)
                ) {
                    // fwrite/rewind/chmod end the hoisted sibling chain — do not walk past them as
                    // firstSibling (re-#16451; prevents re-emitting fwrite before var_export (#25084)).
                    break;
                }
                if (
                    ($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall)
                    && $deadInlineArgCount >= 2
                    && $deadInlineArgCount === \count($consumer->args ?? [])
                ) {
                    ++$funcProducersSeen;
                    if (
                        $funcProducersSeen > $deadInlineArgCount
                        && !$this->hasContiguousHoistedFuncCallProducersFrom(0, $consumerIndex, $cfgChildren)
                    ) {
                        // var_dump(ftell(), fgetc()) after fseek/fwrite — only trailing N producers (#16254).
                        // array_intersect(f(g()), f(g())) — full scan when outer producers match args (#16427, re-#16050).
                        break;
                    }
                }
                if (
                    ($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall)
                    && $this->builtinUsesTrailingComparatorCallback($this->resolveCfgFuncCallName($child))
                ) {
                    // Completed prior array_udiff*(...) stmt — not part of this hoisted chain (#16045).
                    break;
                }
                if (
                    $this->statementLevelFuncCallBeforeHoistedSiblingChain($i, $consumerIndex, $cfgChildren)
                    && !$this->hasContiguousHoistedFuncCallProducersFrom($i, $consumerIndex, $cfgChildren)
                ) {
                    // var_dump(acosh(), asinh(), atanh()) — partial scan from $i fails but chain from 0 is contiguous (#14119).
                    if (
                        $i > 0
                        && $this->hasContiguousHoistedFuncCallProducersFrom(0, $consumerIndex, $cfgChildren)
                    ) {
                        --$i;
                        continue;
                    }
                    break;
                }
                --$i;
                continue;
            }
            if ($child instanceof Op\Expr\ConstFetch || $child instanceof Op\Expr\ClassConstFetch) {
                --$i;
                continue;
            }
            if ($child instanceof Op\Expr\Array_) {
                --$i;
                continue;
            }
            if ($this->isUnaryInlineSiblingCallArgExpr($child)) {
                --$i;
                continue;
            }
            // sprintf(..., count($a), $sum / count($a)) — skip Div between sibling counts (#36353).
            if ($child instanceof Op\Expr\BinaryOp) {
                --$i;
                continue;
            }
            if ($child instanceof Op\Expr\ArrowFunction
                || $child instanceof Op\Expr\Closure
                || $child instanceof Op\Expr\FirstClassCallable) {
                --$i;
                continue;
            }
            if ($child instanceof Op\Expr\New_ || $child instanceof Op\Expr\Clone_) {
                --$i;
                continue;
            }
            if ($child instanceof Op\Expr\Isset_ || $child instanceof Op\Expr\Empty_) {
                --$i;
                continue;
            }
            // insertBefore($d->createElement('x'), $r->lastChild) — PropertyFetch is a sibling arg (#19719).
            if (
                $child instanceof Op\Expr\PropertyFetch
                || $child instanceof Op\Expr\NullsafePropertyFetch
                || $child instanceof Op\Expr\StaticPropertyFetch
            ) {
                --$i;
                continue;
            }
            // var_dump($s->contains($o), $s[$o], count($s)) — ArrayDimFetch is a sibling arg; do not
            // break the MethodCall/FuncCall chain (#28821, peer PropertyFetch #19719).
            if ($child instanceof Op\Expr\ArrayDimFetch) {
                --$i;
                continue;
            }
            if ($child instanceof Op\Expr\Assign || $child instanceof Op\Expr\AssignRef) {
                // $c = get_defined_constants(true); in_array(..., get_declared_traits(), …) — stmt assign
                // is not part of the hoisted sibling chain (#15611, re-#14237).
                $assignFeedsCallArg = false;
                $consumer = $cfgChildren[$consumerIndex] ?? null;
                if (
                    $consumer instanceof Op\Expr
                    && property_exists($consumer, 'args')
                    && \is_array($consumer->args)
                ) {
                    foreach ($consumer->args as $callArg) {
                        if (
                            $this->operandsReferToSameVariable($child->var, $callArg)
                            || $this->operandsReferToSameVariable($child->result, $callArg)
                        ) {
                            $assignFeedsCallArg = true;
                            break;
                        }
                    }
                }
                if ($assignFeedsCallArg) {
                    --$i;
                    continue;
                }
                break;
            }
            break;
        }
        $first = $i + 1;
        while ($first < $consumerIndex) {
            $skip = $cfgChildren[$first] ?? null;
            if ($skip instanceof Op\Expr\MethodCall) {
                // Leading loadXML — skip; trailing item() inside dead-arg window — keep (#19719, #21171).
                if ($this->methodCallIsSkippedHoistedSiblingProducer(
                    $skip,
                    $first,
                    $consumerIndex,
                    $deadInlineArgCount,
                    $cfgChildren
                )) {
                    ++$first;
                    continue;
                }
            }
            if ($skip instanceof Op\Expr\ConstFetch || $skip instanceof Op\Expr\ClassConstFetch) {
                ++$first;
                continue;
            }
            if ($skip instanceof Op\Expr\Array_) {
                ++$first;
                continue;
            }
            if ($this->isUnaryInlineSiblingCallArgExpr($skip)) {
                ++$first;
                continue;
            }
            if ($skip instanceof Op\Expr\ArrowFunction
                || $skip instanceof Op\Expr\Closure
                || $skip instanceof Op\Expr\FirstClassCallable) {
                ++$first;
                continue;
            }
            if ($skip instanceof Op\Expr\New_ || $skip instanceof Op\Expr\Clone_) {
                ++$first;
                continue;
            }
            if ($skip instanceof Op\Expr\Isset_ || $skip instanceof Op\Expr\Empty_) {
                ++$first;
                continue;
            }
            if (
                $skip instanceof Op\Expr\PropertyFetch
                && $this->isPropertyFetchOnlyIssetVar($skip, $cfgChildren[$first + 1] ?? null)
            ) {
                ++$first;
                continue;
            }
            // Call-arg PropertyFetch between Assign and MethodCall (#19719): $r->lastChild before createElement.
            if (
                $skip instanceof Op\Expr\PropertyFetch
                || $skip instanceof Op\Expr\NullsafePropertyFetch
                || $skip instanceof Op\Expr\StaticPropertyFetch
            ) {
                ++$first;
                continue;
            }
            // Call-arg ArrayDimFetch between MethodCall and FuncCall (#28821).
            if ($skip instanceof Op\Expr\ArrayDimFetch) {
                ++$first;
                continue;
            }
            if (
                $skip instanceof Op\Expr\StaticPropertyFetch
                && $this->isStaticPropertyFetchOnlyIssetVar($skip, $cfgChildren[$first + 1] ?? null)
            ) {
                ++$first;
                continue;
            }
            if ($skip instanceof Op\Expr\Assign || $skip instanceof Op\Expr\AssignRef) {
                ++$first;
                continue;
            }
            if (
                ($skip instanceof Op\Expr\FuncCall || $skip instanceof Op\Expr\NsFuncCall)
                && $this->isStatementLevelSideEffectFuncCall($skip)
            ) {
                // Leading fwrite/rewind/chmod are not the start of a hoisted arg chain (#25084, #16480).
                ++$first;
                continue;
            }
            break;
        }
        if ($first >= $consumerIndex) {
            return null;
        }
        $firstChild = $cfgChildren[$first] ?? null;
        if (!$this->isSiblingInlineCallProducerExpr($firstChild)) {
            return null;
        }
        if (
            $this->stmtLevelNamedFuncCallPrecedesNestedInlineCallArgChain(
                $first,
                $consumerIndex,
                $cfgChildren
            )
        ) {
            ++$first;
            if ($first >= $consumerIndex) {
                return null;
            }
            $firstChild = $cfgChildren[$first] ?? null;
            if (!$this->isSiblingInlineCallProducerExpr($firstChild)) {
                return null;
            }
        }
        $consumer = $cfgChildren[$consumerIndex] ?? null;
        // fileperms(); sprintf('%o', …); substr(…, -N) — skip nested-only producers (#13616).
        while ($first + 1 < $consumerIndex && $consumer instanceof Op\Expr) {
            $chainChild = $cfgChildren[$first] ?? null;
            $nextChild = $cfgChildren[$first + 1] ?? null;
            $consumerArgCount = (
                property_exists($consumer, 'args')
                && is_array($consumer->args)
            ) ? \count($consumer->args) : 0;
            if (
                $chainChild instanceof Op\Expr
                && ($nextChild instanceof Op\Expr\FuncCall || $nextChild instanceof Op\Expr\NsFuncCall)
                && $this->isAdjacentNestedFuncCallProducer($chainChild, $nextChild, $first, $first + 1)
                && ($consumerIndex - $first) > $consumerArgCount
            ) {
                // array_intersect(f(g()), f(g())) — dual outer producers; do not skip past first f() (#16050, #16031).
                if (
                    $this->isSiblingMultiArgInlineCallConsumer($consumer)
                    && $consumerArgCount >= 2
                    && property_exists($consumer, 'args')
                    && \is_array($consumer->args)
                ) {
                    $outer = $this->outerSiblingInlineFuncCallProducers($first, $consumerIndex, $cfgChildren);
                    $hoistedArgCount = 0;
                    foreach ($consumer->args as $consumerArg) {
                        if (null !== $consumerArg && !$this->isEmbeddedCallLiteralArg($consumerArg)) {
                            ++$hoistedArgCount;
                        }
                    }
                    if (\count($outer) === $hoistedArgCount && $hoistedArgCount >= 2) {
                        break;
                    }
                }
                ++$first;
                continue;
            }
            break;
        }

        $consumer = $cfgChildren[$consumerIndex] ?? null;
        if ($this->isSiblingMultiArgInlineCallConsumer($consumer)) {
            $contiguousFirst = $this->firstContiguousHoistedFuncCallProducerForMultiArgConsumer(
                $consumerIndex,
                $consumer,
                $cfgChildren
            );
            if (null !== $contiguousFirst && $contiguousFirst < $first) {
                $hoistedArgCount = 0;
                if (property_exists($consumer, 'args') && \is_array($consumer->args)) {
                    foreach ($consumer->args as $consumerArg) {
                        if (null !== $consumerArg && !$this->isEmbeddedCallLiteralArg($consumerArg)) {
                            ++$hoistedArgCount;
                        }
                    }
                }
                if ($hoistedArgCount >= 2) {
                    $outerFromFirst = \count(
                        $this->outerSiblingInlineFuncCallProducers($first, $consumerIndex, $cfgChildren)
                    );
                    $outerFromContiguous = \count(
                        $this->outerSiblingInlineFuncCallProducers($contiguousFirst, $consumerIndex, $cfgChildren)
                    );
                    if (
                        $outerFromContiguous === $hoistedArgCount
                        && $outerFromFirst !== $hoistedArgCount
                    ) {
                        $first = $contiguousFirst;
                    }
                }
            }
        }

        return $first;
    }

    /**
     * var_dump(strlen($s), substr($s, 0, 2)) — N stmt-level FuncCalls before a multi-arg consumer (#16254).
     *
     * php-cfg hoists each arg producer as a sibling; statementLevelFuncCallBeforeHoistedSiblingChain would
     * otherwise trim strlen when substr sits between it and var_dump.
     *
     * @param list<Op> $cfgChildren
     */
    private function firstContiguousHoistedFuncCallProducerForMultiArgConsumer(
        int $consumerIndex,
        Op $consumer,
        array $cfgChildren
    ): ?int {
        if (
            !$this->isSiblingMultiArgInlineCallConsumer($consumer)
            || !property_exists($consumer, 'args')
            || !\is_array($consumer->args)
            || \count($consumer->args) < 2
        ) {
            return null;
        }
        $hoistedArgs = [];
        foreach ($consumer->args as $argIndex => $callArg) {
            if (null === $callArg || $this->isEmbeddedCallLiteralArg($callArg)) {
                continue;
            }
            if ($this->isByRefNamedCallArgExcludedFromSiblingProducerWiring($consumer, (int) $argIndex, $callArg)) {
                continue;
            }
            $hoistedArgs[] = $callArg;
        }
        if (
            \count($hoistedArgs) < 2
            || !$this->callArgsAreDistinctInlineTemporaries($hoistedArgs)
        ) {
            return null;
        }
        $hoistedArgCount = \count($hoistedArgs);
        $first = $consumerIndex - $hoistedArgCount;
        if ($first < 0) {
            return null;
        }
        for ($j = $first; $j < $consumerIndex; ++$j) {
            $child = $cfgChildren[$j] ?? null;
            if ($child instanceof Op\Expr\ConstFetch || $child instanceof Op\Expr\ClassConstFetch) {
                continue;
            }
            if ($child instanceof Op\Expr\Array_) {
                continue;
            }
            if ($this->isUnaryInlineSiblingCallArgExpr($child)) {
                continue;
            }
            if ($child instanceof Op\Expr\ArrowFunction
                || $child instanceof Op\Expr\Closure
                || $child instanceof Op\Expr\FirstClassCallable) {
                continue;
            }
            if ($child instanceof Op\Expr\New_ || $child instanceof Op\Expr\Clone_) {
                continue;
            }
            if ($child instanceof Op\Expr\Isset_ || $child instanceof Op\Expr\Empty_) {
                continue;
            }
            if (
                $child instanceof Op\Expr\PropertyFetch
                && $this->isPropertyFetchOnlyIssetVar($child, $cfgChildren[$j + 1] ?? null)
            ) {
                continue;
            }
            if (
                $child instanceof Op\Expr\ArrayDimFetch
                && $this->isArrayDimFetchOnlyIssetVar($child, $cfgChildren[$j + 1] ?? null)
            ) {
                continue;
            }
            if (
                $child instanceof Op\Expr\StaticPropertyFetch
                && $this->isStaticPropertyFetchOnlyIssetVar($child, $cfgChildren[$j + 1] ?? null)
            ) {
                continue;
            }
            if (!$this->isSiblingInlineCallProducerExpr($child)) {
                return null;
            }
        }
        $outer = $this->outerSiblingInlineFuncCallProducers($first, $consumerIndex, $cfgChildren);
        if (\count($outer) !== $hoistedArgCount) {
            return null;
        }

        return $first;
    }

    /** True when php-cfg hoisted ≥2 sibling FuncCall producers before a multi-arg consumer (#13671). */
    private function hasSiblingMultiArgInlineCallProducers(Block $block, Op $cfgCallOp): bool
    {
        if (null === $block->orig || !property_exists($cfgCallOp, 'args') || !is_array($cfgCallOp->args)) {
            return false;
        }
        if (\count($cfgCallOp->args) < 2) {
            return false;
        }
        $callIndex = $this->cfgCallOpIndex($block, $cfgCallOp);
        if (null === $callIndex) {
            return false;
        }
        $firstSibling = $this->firstSiblingInlineFuncCallProducerIndexImpl($callIndex, $block->orig->children);
        if (null === $firstSibling) {
            return false;
        }
        // A::inc(); A::inc(); var_dump(A::$n, B::$n) — fetches cover args; not a call-producer chain (#34997).
        if ($this->interveningFetchProducersCoverDeadTempCallArgs(
            $firstSibling,
            $callIndex,
            $block->orig->children,
            $cfgCallOp
        )) {
            return false;
        }

        return ($callIndex - $firstSibling) >= 2;
    }

    /**
     * True when StaticPropertyFetch/PropertyFetch/ArrayDimFetch between sibling call producers
     * and the consumer can fill every dead-temp call arg (#34997).
     *
     * @param list<Op> $cfgChildren
     */
    private function interveningFetchProducersCoverDeadTempCallArgs(
        int $firstSibling,
        int $callIndex,
        array $cfgChildren,
        Op $cfgCallOp
    ): bool {
        $deadInlineArgCount = $this->deadInlineTemporaryArgCount($cfgCallOp);
        if ($deadInlineArgCount < 2) {
            return false;
        }
        $interveningFetchProducers = 0;
        for ($j = $firstSibling; $j < $callIndex; ++$j) {
            $between = $cfgChildren[$j] ?? null;
            if (
                $between instanceof Op\Expr\StaticPropertyFetch
                || $between instanceof Op\Expr\PropertyFetch
                || $between instanceof Op\Expr\NullsafePropertyFetch
                || $between instanceof Op\Expr\ArrayDimFetch
            ) {
                ++$interveningFetchProducers;
            }
        }

        return $interveningFetchProducers >= $deadInlineArgCount;
    }

    /**
     * True when php-cfg hoisted ≥2 sibling inline New_ producers before a multi-arg ctor (#17524, re-#15124).
     * Trailing ClassConstFetch/ConstFetch/scalar option preludes are skipped (#19731, #19738).
     */
    private function hasSiblingMultiArgInlineNewProducers(Block $block, Op $cfgCallOp): bool
    {
        if (null === $block->orig || !property_exists($cfgCallOp, 'args') || !is_array($cfgCallOp->args)) {
            return false;
        }
        $args = $cfgCallOp->args;
        if (\count($args) < 2) {
            return false;
        }
        $siblingNews = $this->siblingInlineNewProducersBeforeCfgOp($block, $cfgCallOp);
        $newCount = \count($siblingNews);

        return $newCount >= 2 && $newCount <= \count($args);
    }

    /**
     * Positional sibling inline New_ → multi-arg ctor/call (#17524, re-#15124 / #14483).
     * Allows trailing ClassConstFetch/ConstFetch/scalar options after New_ producers (#19731, #19738).
     * Nested `new Outer(new Mid(new Inner), Mode::C)` must not map Mid/Inner onto the mode
     * arg or Outer arg #0 by ordinal alone — require the New_ to feed that call arg (#22007, #19770).
     *
     * @param list<Op\Expr> $producers
     * @param list<Operand|null> $callArgs
     */
    private function matchSiblingInlineNewCallArgProducer(
        array $producers,
        array $callArgs,
        int $argIndex
    ): ?Op\Expr\New_ {
        $producerCount = \count($producers);
        $argCount = \count($callArgs);
        if ($producerCount < 2) {
            return null;
        }
        foreach ($producers as $producer) {
            if (!$producer instanceof Op\Expr\New_) {
                return null;
            }
        }
        if ($producerCount < $argCount) {
            // new DatePeriod(new DateTime(...), new DateInterval(...), new DateTime(...), INCLUDE_END_DATE)
            for ($i = $producerCount; $i < $argCount; ++$i) {
                if ($this->callArgIsNewExpression($callArgs[$i] ?? null)) {
                    return null;
                }
            }
        } elseif ($producerCount !== $argCount) {
            return null;
        }
        if ($argIndex >= $producerCount) {
            return null;
        }
        $callArg = $callArgs[$argIndex] ?? null;
        if (null === $callArg || !$this->callArgIsDeadInlineTemporary($callArg)) {
            return null;
        }
        $matched = $producers[$argIndex] ?? null;
        if (!$matched instanceof Op\Expr\New_) {
            return null;
        }
        // Nested New_ chain (ParentIterator(RecursiveArrayIterator)) ≠ parallel sibling args (#22007).
        return $this->inlineNewProducerFeedsCallArg($matched, $callArg) ? $matched : null;
    }

    /**
     * Sibling inline New_ stmts immediately before $cfgCallOp (#17524).
     * Skips trailing ClassConstFetch/ConstFetch and scalar/flag option preludes
     * (#19731, #19735, #19738) — bitwise, arithmetic, unary, and casts.
     *
     * @return list<Op\Expr\New_>
     */
    private function siblingInlineNewProducersBeforeCfgOp(Block $block, Op $cfgCallOp): array
    {
        if (null === $block->orig || !property_exists($cfgCallOp, 'args') || !is_array($cfgCallOp->args)) {
            return [];
        }
        $callIndex = $this->cfgCallOpIndex($block, $cfgCallOp);
        if (null === $callIndex) {
            return [];
        }
        $producers = [];
        for ($i = $callIndex - 1; $i >= 0 && \count($producers) < \count($cfgCallOp->args); --$i) {
            $child = $block->orig->children[$i] ?? null;
            if ($this->isTrailingInlineNewCtorOptionPrelude($child)) {
                continue;
            }
            if (!$child instanceof Op\Expr\New_) {
                break;
            }
            array_unshift($producers, $child);
        }

        return $producers;
    }

    /**
     * Scalar / flag option prelude between sibling New_ args and outer ctor (#19731, #19738).
     * e.g. DatePeriod::INCLUDE_END_DATE, EXCLUDE_START_DATE|INCLUDE_END_DATE, or 1+2 / 1<<2 / -1.
     */
    private function isTrailingInlineNewCtorOptionPrelude(?Op $child): bool
    {
        if ($child instanceof Op\Expr\ConstFetch || $child instanceof Op\Expr\ClassConstFetch) {
            return true;
        }
        if (
            $child instanceof Op\Expr\UnaryMinus
            || $child instanceof Op\Expr\UnaryPlus
            || $child instanceof Op\Expr\BitwiseNot
            || $child instanceof Op\Expr\Cast
        ) {
            return true;
        }

        return $this->isArithmeticInlineCallArgProducer($child);
    }

    private function cfgCallOpIndex(Block $block, Op $cfgCallOp): ?int
    {
        if (null === $block->orig) {
            return null;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (\is_int($callIndex)) {
            return $callIndex;
        }
        if (!property_exists($cfgCallOp, 'result') || null === $cfgCallOp->result) {
            return null;
        }
        foreach ($block->orig->children as $i => $child) {
            if (
                ($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall)
                && null !== $child->result
                && (
                    $child->result === $cfgCallOp->result
                    || $this->operandsReferToSameVariable($child->result, $cfgCallOp->result)
                )
            ) {
                return $i;
            }
        }

        return null;
    }

    /**
     * var_dump(f(), g()) at cfg index — hoisted sibling consumer, not an EXEC_RETURN emitter (#16254).
     *
     * @param list<Op> $cfgChildren
     */
    private function isMultiArgSiblingInlineCallConsumerAt(int $callIndex, array $cfgChildren): bool
    {
        $consumer = $cfgChildren[$callIndex] ?? null;
        if (!$consumer instanceof Op\Expr\FuncCall && !$consumer instanceof Op\Expr\NsFuncCall) {
            return false;
        }
        if (!\is_array($consumer->args ?? null) || \count($consumer->args) < 2) {
            return false;
        }
        $firstSibling = $this->firstSiblingInlineFuncCallProducerIndex($callIndex, $cfgChildren);
        if (null === $firstSibling) {
            return false;
        }

        return ($callIndex - $firstSibling) >= 2;
    }

    /** substr($s, 0, 2) hoisted sibling — trailing embedded literals add one EXEC_RETURN (#16254). */
    private function extraExecReturnOrdinalsForInlineFuncCallProducer(Op $child): int
    {
        if (!$child instanceof Op\Expr\FuncCall && !$child instanceof Op\Expr\NsFuncCall) {
            return 0;
        }
        if (!\is_array($child->args ?? null)) {
            return 0;
        }
        foreach ($child->args as $i => $arg) {
            if ($i > 0 && $this->isEmbeddedCallLiteralArg($arg)) {
                return 1;
            }
        }

        return 0;
    }

    /**
     * True when every CFG child between $fromExclusive and $toExclusive is true/false/null ConstFetch
     * (or ClassConstFetch). Used for importNode(...->item(0), true) chain deferral (#25702).
     *
     * @param list<Op> $cfgChildren
     */
    private function onlyScalarConstFetchPreludesBetween(
        int $fromExclusive,
        int $toExclusive,
        array $cfgChildren
    ): bool {
        if ($fromExclusive + 1 >= $toExclusive) {
            // Adjacent — not a ConstFetch-prelude pattern (require at least one scalar) (#25702).
            return false;
        }
        $sawScalar = false;
        for ($k = $fromExclusive + 1; $k < $toExclusive; ++$k) {
            $mid = $cfgChildren[$k] ?? null;
            if ($mid instanceof Op\Expr\ConstFetch) {
                $name = $this->staticNameFromOperand($mid->name);
                if (
                    null === $name
                    || !\in_array(strtolower($name), ['true', 'false', 'null'], true)
                ) {
                    return false;
                }
                $sawScalar = true;
                continue;
            }
            if ($mid instanceof Op\Expr\ClassConstFetch) {
                $sawScalar = true;
                continue;
            }

            return false;
        }

        return $sawScalar;
    }

    /**
     * Whether a MethodCall before trailing true/false/null ConstFetch actually feeds the multi-arg
     * consumer (live result usage or dead-temp call arg) — #25702 / #26458.
     *
     * Bare statement MethodCalls such as {@code $r->appendChild($a)} before
     * {@code $r->insertBefore($b, null)} share the ConstFetch-null CFG shape but must not be
     * treated as sibling arg producers (that deferred the append and dropped prior children).
     */
    private function methodCallFeedsMultiArgConsumerAcrossScalarConstFetch(
        Op\Expr\MethodCall $producer,
        Op $consumer
    ): bool {
        if (!\is_array($consumer->args ?? null) || \count($consumer->args) < 2) {
            return false;
        }
        if (null === $producer->result) {
            return false;
        }
        if (!empty($producer->result->usages)) {
            foreach ($producer->result->usages as $usage) {
                if ($usage === $consumer) {
                    return true;
                }
            }
        }
        // Dead-temp leaf (importNode(...->item(0), true) — #25702). php-cfg may use distinct
        // result/arg temporaries, so also accept a non-scalar-ConstFetch dead temp when this
        // MethodCall is the leaf before the ConstFetch prelude. Skip true/false/null ConstFetch
        // temps — those are not feeds from a prior stmt MethodCall such as appendChild (#26458).
        $sawNonScalarDeadTemp = false;
        foreach ($consumer->args as $arg) {
            if (!$this->callArgIsDeadInlineTemporary($arg)) {
                continue;
            }
            if ($this->callArgTemporaryIsHoistedTrueFalseNullConstFetch($arg)) {
                continue;
            }
            if ($this->operandsReferToSameVariable($producer->result, $arg)) {
                return true;
            }
            $sawNonScalarDeadTemp = true;
        }

        return $sawNonScalarDeadTemp && empty($producer->result->usages);
    }

    /** True when a call-arg Temporary is the hoisted true/false/null ConstFetch prelude (#26458). */
    private function callArgTemporaryIsHoistedTrueFalseNullConstFetch(?Operand $arg): bool
    {
        if (!$arg instanceof Operand\Temporary) {
            return false;
        }
        foreach ($arg->ops ?? [] as $embedded) {
            if (!$embedded instanceof Op\Expr\ConstFetch) {
                continue;
            }
            $name = $this->staticNameFromOperand($embedded->name);
            if (null !== $name && \in_array(strtolower($name), ['true', 'false', 'null'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * php-cfg `var_dump($g(), $g())` hoists sibling FuncCall stmts before the consumer (#9463, #10981).
     * Compile each producer once with its own EXEC_RETURN slot before ARG_SEND wiring.
     */
    private function ensureDeferredSiblingInlineCallArgProducersCompiled(Block $block, Op $cfgCallOp): void
    {
        if (null === $block->orig || !property_exists($cfgCallOp, 'args') || !is_array($cfgCallOp->args)) {
            return;
        }
        $argCount = \count($cfgCallOp->args);
        $cfgChildren = $block->orig->children;
        $this->ensureCfgChildrenOpIndicesBuilt($cfgChildren, $block->orig);
        $callIndex = $this->cfgCallOpIndexInChildren($cfgChildren, $cfgCallOp, $block->orig);
        if (null === $callIndex) {
            return;
        }
        if (
            $argCount < 2
            && !(
                1 === $argCount
                && $callIndex >= 2
                && ($cfgChildren[$callIndex - 2] ?? null) instanceof Op\Expr
                && $this->isIifeHoistedFuncCallArgProducer(
                    $cfgChildren[$callIndex - 2],
                    $cfgCallOp,
                    $callIndex - 2,
                    $callIndex,
                    $cfgChildren
                )
            )
        ) {
            return;
        }
        if ($this->isSubstrNestedSprintfUnaryMinusPattern($cfgCallOp, $callIndex, $cfgChildren)) {
            $this->ensureSideEffectsBeforeSubstrNestedSprintfCompiled($block, $callIndex, $cfgChildren);
        }
        $firstSibling = $this->firstSiblingInlineFuncCallProducerIndex($callIndex, $block->orig->children);
        if (null === $firstSibling) {
            if (
                1 === $argCount
                && $callIndex >= 2
            ) {
                $producerIndex = $callIndex - 2;
                $producer = $cfgChildren[$producerIndex] ?? null;
                if (
                    $producer instanceof Op\Expr
                    && $this->isIifeHoistedFuncCallArgProducer(
                        $producer,
                        $cfgCallOp,
                        $producerIndex,
                        $callIndex,
                        $cfgChildren
                    )
                    && null === $block->slotForOperand($producer->result)
                ) {
                    $this->ensureStatementLevelSideEffectsBeforeChainStartCompiled(
                        $block,
                        $producerIndex,
                        $cfgChildren
                    );
                    $emitOps = [];
                    $prevForce = $this->forceDeferredSiblingCallReturnSlot;
                    $this->forceDeferredSiblingCallReturnSlot = true;
                    try {
                        foreach ($this->compileExpr($producer, $block) as $op) {
                            $emitOps[] = $op;
                        }
                    } finally {
                        $this->forceDeferredSiblingCallReturnSlot = $prevForce;
                    }
                    foreach ($emitOps as $op) {
                        $block->addOpCode($op);
                    }
                }
            }

            return;
        }
        // A::inc(); A::inc(); var_dump(A::$n, B::$n) — do not re-emit stmt StaticCalls (#34997).
        if ($this->interveningFetchProducersCoverDeadTempCallArgs(
            $firstSibling,
            $callIndex,
            $block->orig->children,
            $cfgCallOp
        )) {
            return;
        }
        $arrayPreludeChain = $this->siblingFuncCallChainHasArrayPrelude(
            $firstSibling,
            $callIndex,
            $block->orig->children
        );
        $siblingFuncCount = $this->countSiblingInlineFuncCallProducers(
            $firstSibling,
            $callIndex,
            $block->orig->children
        );
        if ($siblingFuncCount < 2) {
            // json_decode(str_repeat(...), true, 512, JSON_THROW_ON_ERROR) — lone hoisted FuncCall (#12009, #15441).
            $producers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                $block->orig->children,
                $cfgCallOp
            );
            foreach ($producers as $producer) {
                $isFuncProducer = $producer instanceof Op\Expr\FuncCall
                    || $producer instanceof Op\Expr\NsFuncCall;
                $isMethodProducer = $producer instanceof Op\Expr\MethodCall
                    || $producer instanceof Op\Expr\StaticCall;
                if (!$isFuncProducer && !$isMethodProducer) {
                    continue;
                }
                if ($isFuncProducer && 'define' === strtolower($this->resolveCfgFuncCallName($producer) ?? '')) {
                    continue;
                }
                if ($isFuncProducer && $this->funcCallExprHasByRefMutatingSideEffects($producer)) {
                    continue;
                }
                $producerIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $producer, $block->orig);
                if (!is_int($producerIndex)) {
                    continue;
                }
                if (
                    !$this->isNestedCallArgProducerSeparatedByConsumerLiteralPreludes(
                        $producer,
                        $cfgCallOp,
                        $producerIndex,
                        $callIndex,
                        $block->orig->children
                    )
                    && !$this->isIifeHoistedFuncCallArgProducer(
                        $producer,
                        $cfgCallOp,
                        $producerIndex,
                        $callIndex,
                        $block->orig->children
                    )
                    && !$this->isSiblingMultiArgFuncCallProducer(
                        $producer,
                        $cfgCallOp,
                        $producerIndex,
                        $callIndex,
                        $block->orig->children
                    )
                ) {
                    continue;
                }
                if (null !== $block->slotForOperand($producer->result)) {
                    break;
                }
                $this->ensureStatementLevelSideEffectsBeforeChainStartCompiled(
                    $block,
                    $producerIndex,
                    $block->orig->children
                );
                $emitOps = [];
                $prevForce = $this->forceDeferredSiblingCallReturnSlot;
                $this->forceDeferredSiblingCallReturnSlot = true;
                try {
                    foreach ($this->compileExpr($producer, $block) as $op) {
                        $emitOps[] = $op;
                    }
                } finally {
                    $this->forceDeferredSiblingCallReturnSlot = $prevForce;
                }
                foreach ($emitOps as $op) {
                    $block->addOpCode($op);
                }
                break;
            }

            return;
        }
        if ($this->callHasOnlyTrailingHoistedScalarConstFetchArgs($cfgCallOp, $block)) {
            return;
        }
        $contiguousFirst = $this->firstContiguousSiblingMultiArgProducerIndex(
            $callIndex,
            $cfgCallOp,
            $block->orig->children
        );
        if (null === $contiguousFirst) {
            $contiguousFirst = $firstSibling;
        } elseif ($arrayPreludeChain) {
            // array_diff_assoc(array_keys(), array_keys()) — Array_ between siblings, not a chain break (#16418).
            $contiguousFirst = $firstSibling;
        }
        $cfgChildren = $block->orig->children;
        // Include dead-temp MethodCall producers (createElement) that precede the contiguous
        // sibling chain for multi-arg MethodCall consumers (#25563).
        if (null !== $contiguousFirst) {
            for ($j = $contiguousFirst - 1; $j >= 0; --$j) {
                $prior = $cfgChildren[$j] ?? null;
                if (
                    $prior instanceof Op\Expr\MethodCall
                    && $this->methodCallDeadTempFeedsLaterMultiArgMethodCallInOps(
                        $prior,
                        $cfgChildren,
                        $j
                    )
                ) {
                    $contiguousFirst = $j;
                    continue;
                }
                break;
            }
        }
        $this->ensureStatementLevelSideEffectsBeforeChainStartCompiled(
            $block,
            $contiguousFirst,
            $cfgChildren
        );
        // O(1) via Block cache — was a full opCodes scan per multi-arg call (#36387).
        $execReturnCountAtChainStart = $block->funccallExecReturnCount();
        for ($j = $contiguousFirst; $j < $callIndex; ++$j) {
            $producer = $cfgChildren[$j] ?? null;
            if (!$producer instanceof Op\Expr) {
                continue;
            }
            if (
                ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall)
                && $this->funcCallExprHasByRefMutatingSideEffects($producer)
            ) {
                continue;
            }
            $isSib = $this->isSiblingMultiArgFuncCallProducer(
                $producer,
                $cfgCallOp,
                $j,
                $callIndex,
                $cfgChildren
            );
            $isCreate = $producer instanceof Op\Expr\MethodCall
                && $this->methodCallDeadTempFeedsLaterMultiArgMethodCallInOps(
                    $producer,
                    $cfgChildren,
                    $j
                );
            // Leaf MethodCall before trailing true/false/null ConstFetch multi-arg consumer (#25702).
            // Must feed the consumer (live usage or dead-temp arg) — not prior stmts like
            // appendChild before insertBefore($x, null) (#26458).
            if (
                !$isSib
                && $producer instanceof Op\Expr\MethodCall
                && $this->onlyScalarConstFetchPreludesBetween($j, $callIndex, $cfgChildren)
                && $this->isSiblingMultiArgInlineCallConsumer($cfgCallOp)
                && $this->methodCallFeedsMultiArgConsumerAcrossScalarConstFetch($producer, $cfgCallOp)
            ) {
                $isSib = true;
            }
            // Receiver MethodCall feeding a later MethodCall whose result feeds this consumer
            // (importNode(...->item(0), true) — #25702). Do not hoist getElements→item→appendChild()
            // statement chains ahead of a following multi-arg FuncCall (#25949, re-#23514/#25842).
            if (
                !$isSib
                && !$isCreate
                && $producer instanceof Op\Expr\MethodCall
                && null !== $producer->result
            ) {
                for ($k = $j + 1; $k < $callIndex; ++$k) {
                    $later = $cfgChildren[$k] ?? null;
                    if (
                        !($later instanceof Op\Expr\MethodCall)
                        || !property_exists($later, 'var')
                        || null === $later->var
                        || !$this->operandsReferToSameVariable($producer->result, $later->var)
                        || null === $later->result
                        || empty($later->result->usages)
                    ) {
                        continue;
                    }
                    foreach ($later->result->usages as $laterUsage) {
                        if ($laterUsage === $cfgCallOp) {
                            $isSib = true;
                            break 2;
                        }
                    }
                }
            }
            if (!$isSib && !$isCreate) {
                continue;
            }
            $siblingOrdinal = $this->siblingInlineFuncCallProducerOrdinal(
                $j,
                $contiguousFirst,
                $cfgChildren,
                $callIndex
            );
            $execReturnCountNow = $block->funccallExecReturnCount();
            if ($execReturnCountNow >= $execReturnCountAtChainStart + $siblingOrdinal + 1) {
                continue;
            }
            $emitOps = [];
            $prevForce = $this->forceDeferredSiblingCallReturnSlot;
            $this->forceDeferredSiblingCallReturnSlot = true;
            try {
                foreach ($this->compileExpr($producer, $block) as $op) {
                    $emitOps[] = $op;
                }
            } finally {
                $this->forceDeferredSiblingCallReturnSlot = $prevForce;
            }
            foreach ($emitOps as $op) {
                $block->addOpCode($op);
            }
        }
    }
}
