<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;

use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Operand\Literal;
use PHPCfg\Operand\Temporary;

/**
 * Multi-arg sibling FuncCall producer detect + ordinal wiring (#36387 / #36403).
 *
 * Extracted from {@see SiblingInlineFuncCallProducers} so gen-0 split-TU can hollow
 * a smaller Concern TU (Part of #36387 / prior #36147).
 *
 * Covers {@see isSiblingInlineCallProducerExpr},
 * {@see isSiblingMultiArgFuncCallProducer},
 * {@see computeIsSiblingMultiArgFuncCallProducer},
 * {@see isUnaryInlineSiblingCallArgExpr},
 * {@see siblingMultiArgProducerWiresByDistinctDeadArgOrdinal}, and
 * {@see siblingMultiArgProducerOrdinalToConsumerArgIndex}.
 *
 * Call sites and visibility stay identical so LintCompiler overrides are unaffected.
 * Mirrors php-src Zend/zend_execute.c ZEND_SEND_* / adjacent call-arg wiring — move-only.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; call-arg
 * slot wiring relies on coercion (same as SiblingInlineFuncCallProducers).
 */
trait SiblingMultiArgFuncCallProducerDetect
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
}

