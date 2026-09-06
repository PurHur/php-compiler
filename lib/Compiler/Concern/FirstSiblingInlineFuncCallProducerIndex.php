<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;

use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Operand\Literal;
use PHPCfg\Operand\Temporary;

/**
 * First-sibling inline FuncCall producer index scan + cache (#36387 / #36403).
 *
 * Extracted from {@see SiblingInlineFuncCallProducers} so gen-0 split-TU can hollow
 * a smaller Concern TU (Part of #36387 / prior #36147).
 *
 * Covers {@see scanFirstSiblingInlineFuncCallProducerIndex},
 * {@see siblingScanStopsAtPriorFuncCall},
 * {@see emptyUsageFuncCallIsHoistedSiblingBeforeBinaryOp},
 * {@see firstSiblingInlineFuncCallProducerIndex},
 * {@see firstSiblingInlineFuncCallProducerCacheKey},
 * {@see firstSiblingInlineFuncCallProducerIndexImpl}, and
 * {@see computeFirstSiblingInlineFuncCallProducerIndex}.
 *
 * Call sites and visibility stay identical so LintCompiler overrides are unaffected.
 * Mirrors php-src Zend/zend_execute.c ZEND_SEND_* / adjacent call-arg wiring — move-only.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; call-arg
 * slot wiring relies on coercion (same as SiblingInlineFuncCallProducers).
 */
trait FirstSiblingInlineFuncCallProducerIndex
{
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

}
